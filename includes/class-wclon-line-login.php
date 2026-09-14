<?php
/**
 * LINE Login：讓顧客授權並取得 LINE User ID
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Line_Login {

	const USER_META_KEY    = '_wclon_line_user_id';
	const USER_META_NAME   = '_wclon_line_display_name';
	const NOTIFY_META_KEY  = '_wclon_line_notify_enabled';
	const COUPON_CODE_META_KEY = '_wclon_line_bind_coupon_code';
	const SESSION_KEY      = 'wclon_line_user_id';
	const STATE_TRANSIENT  = 'wclon_state_';
	const LINE_ID_COUPON_CLAIMED_OPTION = 'wclon_line_bind_coupon_claimed_ids';


	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_request' ) );
		add_shortcode( 'wclon_line_connect', array( __CLASS__, 'render_connect_button' ) );

		// v1.30.0 起結帳頁的 chip 不再由各平台自己掛 hook（原本是 priority 5/6/7 夾在主檔案的
		// 4/8 wrapper 中間）：改由 WCLON_Settings::render_social_bar() 直接呼叫，與購物車頁、
		// [wclon_social_bar] 短代碼走同一條路。`render_checkout_chip()` 方法本身保留。
		if ( WCLON_Settings::get( 'show_on_myaccount', 1 ) ) {
			add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', array( __CLASS__, 'echo_account_row' ), 10 );
			add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', array( __CLASS__, 'echo_account_actions' ), 41 );
		}

		// LINE 登入／綁定按鈕（登入頁、WC 帳號頁）
		if ( WCLON_Settings::get( 'login_channel_id' ) ) {
			add_action( WCLON_Settings::social_hook( 'wp_login' ), array( __CLASS__, 'echo_wp_login_button' ) );
			add_filter( 'login_message', array( __CLASS__, 'render_login_page_notice' ) );
			add_action( WCLON_Settings::social_hook( 'wc_login' ), array( __CLASS__, 'echo_wc_login_button' ) );
			add_action( WCLON_Settings::social_hook( 'wc_register' ), array( __CLASS__, 'echo_wc_register_button' ) );
			// 新帳號建立後自動綁定 session 中的 LINE ID
			add_action( 'woocommerce_created_customer', array( __CLASS__, 'auto_link_on_register' ), 10, 1 );
			// LINE 直接註冊後，在帳戶詳細資料頁顯示補填 email 提示
			add_action( 'woocommerce_before_edit_account_form', array( __CLASS__, 'render_new_account_notice' ) );
		}

		// 結帳時把 session 中的 LINE User ID 寫入訂單（涵蓋訪客結帳）
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_line_id_to_order' ), 10, 1 );

		// 後台使用者頁面顯示 LINE 綁定狀態
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_profile_section' ) );
	}

	/* ---------- OAuth 流程 ---------- */

	public static function handle_request() {
		if ( empty( $_GET['wclon_action'] ) ) {
			return;
		}
		$action = sanitize_text_field( wp_unslash( $_GET['wclon_action'] ) );

		if ( 'login' === $action ) {
			self::redirect_to_line();
		} elseif ( 'callback' === $action ) {
			self::handle_callback();
		} elseif ( 'disconnect' === $action ) {
			self::handle_disconnect();
		} elseif ( 'toggle_notify' === $action ) {
			self::handle_toggle_notify();
		}
	}

	private static function redirect_to_line() {
		$channel_id = WCLON_Settings::get( 'login_channel_id' );
		if ( ! $channel_id ) {
			wp_die( '尚未設定 LINE Login Channel ID。' );
		}

		$state    = wp_generate_password( 24, false );
		$redirect = ! empty( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : wc_get_checkout_url();
		$raw_intent = sanitize_text_field( wp_unslash( $_GET['intent'] ?? 'link' ) );
		$intent   = in_array( $raw_intent, array( 'link', 'login', 'register', 'checkout' ), true ) ? $raw_intent : 'link';

		set_transient( self::STATE_TRANSIENT . $state, array(
			'redirect' => $redirect,
			'intent'   => $intent,
		), 10 * MINUTE_IN_SECONDS );

		$params = array(
			'response_type' => 'code',
			'client_id'     => $channel_id,
			'redirect_uri'  => home_url( '/?wclon_action=callback' ),
			'state'         => $state,
			'scope'         => 'profile openid email',
			'bot_prompt'    => 'aggressive',
		);

		// LINE 會**記住使用者先前的同意結果**，權限組合變了也不會重新詢問。在 channel 的
		// 「Email address permission」核准之前就授權過本站的顧客，當初同意的是不含 email 的
		// 權限組合，之後就算 channel 拿到了權限，LINE 也不會再問一次，id_token 裡就永遠不會
		// 出現 email claim——表現就是「權限明明是 Applied，卻還是抓不到顧客 Email」。
		// prompt=consent 會強制每次都顯示同意畫面，讓這些顧客有機會補同意提供 email。
		// 代價是每次登入多一個同意步驟，所以做成設定而不是寫死（見設定頁「LINE」分頁說明）。
		if ( WCLON_Settings::get( 'line_force_consent', 0 ) ) {
			$params['prompt'] = 'consent';
		}

		wp_redirect( 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query( $params ) );
		exit;
	}

	private static function handle_callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$data  = get_transient( self::STATE_TRANSIENT . $state );
		if ( false === $data ) {
			wp_die( '驗證逾時，請重新操作 LINE 綁定。' );
		}
		delete_transient( self::STATE_TRANSIENT . $state );

		// 向下相容：舊版 transient 直接存字串
		if ( is_string( $data ) ) {
			$redirect = $data;
			$intent   = 'link';
		} else {
			$redirect = $data['redirect'] ?? home_url();
			$intent   = $data['intent'] ?? 'link';
		}

		// 向 LINE 換取 access token
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$response = wp_remote_post( 'https://api.line.me/oauth2/v2.1/token', array(
			'timeout' => 15,
			'body'    => array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => home_url( '/?wclon_action=callback' ),
				'client_id'     => WCLON_Settings::get( 'login_channel_id' ),
				'client_secret' => WCLON_Settings::get( 'login_channel_secret' ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_die( 'LINE 連線失敗：' . esc_html( $response->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			wp_die( 'LINE 授權失敗，請確認 Channel ID / Secret 與 Callback URL 設定是否正確。' );
		}

		// 從 ID Token 取得 email（需在 LINE Developers Console 開啟 email 權限）
		$line_email = self::extract_email_from_id_token( $body['id_token'] ?? '' );

		// 綁定歡迎優惠券只在確認已加官方帳號好友時才發放（見 maybe_issue_bind_coupon()）；
		// 只有啟用該功能時才需要多打一次好友狀態 API，避免每次登入都平白多一次外部請求。
		$is_line_friend = WCLON_Settings::get( 'line_bind_coupon_enabled' )
			? self::is_line_friend( $body['access_token'] )
			: true;

		// 取得 LINE Profile
		$profile_res = wp_remote_get( 'https://api.line.me/v2/profile', array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ),
		) );

		if ( is_wp_error( $profile_res ) ) {
			wp_die( '取得 LINE 個人資料失敗。' );
		}

		$profile = json_decode( wp_remote_retrieve_body( $profile_res ), true );
		if ( empty( $profile['userId'] ) ) {
			wp_die( '無法取得 LINE User ID。' );
		}

		$line_user_id = sanitize_text_field( $profile['userId'] );
		$display_name = isset( $profile['displayName'] ) ? sanitize_text_field( $profile['displayName'] ) : '';

		// ── 依 intent 決定行為 ───────────────────────────────────────────────

		if ( 'login' === $intent || 'register' === $intent || 'checkout' === $intent ) {
			list( $wp_user_id, $matched_by_email ) = self::resolve_login_user( $line_user_id, $line_email );

			if ( $wp_user_id ) {
				if ( $matched_by_email ) {
					// 這個帳號原本沒綁過 LINE（是靠 email 對上的）→ 等同「第一次綁定」：補寫 LINE ID、
					// 預設開啟 LINE 訂單通知，並比照其他綁定入口發放歡迎優惠券。
					// maybe_issue_bind_coupon() 內部自己會判斷模組開關與是否已發過，重複呼叫是安全的。
					update_user_meta( $wp_user_id, self::USER_META_KEY, $line_user_id );
					update_user_meta( $wp_user_id, self::NOTIFY_META_KEY, 1 );
				} else {
					// 靠 LINE ID 對上的既有帳號：補一次 email——Email 權限核准前建立的帳號會是佔位符，
					// 權限核准後靠這裡自動修好（靠 email 對上的那條路，email 本來就已經相符，不需要補）
					self::maybe_backfill_email( $wp_user_id, $line_email );
				}

				update_user_meta( $wp_user_id, self::USER_META_NAME, $display_name );

				if ( $matched_by_email ) {
					self::maybe_issue_bind_coupon( $wp_user_id, $line_user_id, $is_line_friend );
				}

				wp_set_current_user( $wp_user_id );
				wp_set_auth_cookie( $wp_user_id, true );
				$user_data = get_userdata( $wp_user_id );
				do_action( 'wp_login', $user_data->user_login, $user_data );
				wp_safe_redirect( $redirect );
				exit;
			}

			// 找不到對應帳號
			self::set_line_session( $line_user_id );

			if ( is_user_logged_in() ) {
				// 已登入（例如點了綁定但帳號尚未綁定）→ 直接綁定
				if ( WCLON_Settings::email_conflicts( $line_email, get_current_user_id() ) ) {
					wc_add_notice( '此 LINE 帳號的 Email 與您目前帳號的 Email 不符，無法綁定。', 'error' );
					wp_safe_redirect( $redirect );
					exit;
				}
				update_user_meta( get_current_user_id(), self::USER_META_KEY, $line_user_id );
				update_user_meta( get_current_user_id(), self::USER_META_NAME, $display_name );
				update_user_meta( get_current_user_id(), self::NOTIFY_META_KEY, 1 );
				self::maybe_issue_bind_coupon( get_current_user_id(), $line_user_id, $is_line_friend );
				wp_safe_redirect( add_query_arg( 'wclon_connected', '1', $redirect ) );
				exit;
			}

			// 未登入且無對應帳號 → 自動建立帳號
			$new_user_id = self::create_user_from_line( $line_user_id, $display_name, $line_email );
			if ( is_wp_error( $new_user_id ) ) {
				wp_die( 'LINE 帳號建立失敗：' . esc_html( $new_user_id->get_error_message() ) );
			}

			update_user_meta( $new_user_id, self::USER_META_KEY, $line_user_id );
			update_user_meta( $new_user_id, self::USER_META_NAME, $display_name );
			update_user_meta( $new_user_id, self::NOTIFY_META_KEY, 1 );
			self::maybe_issue_bind_coupon( $new_user_id, $line_user_id, $is_line_friend );

			wp_set_current_user( $new_user_id );
			wp_set_auth_cookie( $new_user_id, true );
			$user_data = get_userdata( $new_user_id );
			do_action( 'wp_login', $user_data->user_login, $user_data );

			if ( 'checkout' === $intent ) {
				// 結帳頁 chip 註冊：完成後留在結帳頁
				$dest = add_query_arg( 'wclon_connected', '1', $redirect );
			} else {
				// 若 email 是佔位符，導向帳戶詳細資料頁提示補填
				$has_real_email = $line_email && ! str_contains( $line_email, '@noemail.invalid' );
				$dest = $has_real_email
					? wc_get_account_endpoint_url( 'dashboard' )
					: add_query_arg( 'wclon_new_account', '1', wc_get_account_endpoint_url( 'edit-account' ) );
			}
			wp_safe_redirect( $dest );
			exit;
		}

		// ── intent = link（預設）：綁定到目前帳號 ───────────────────────────

		if ( is_user_logged_in() ) {
			$existing_user_id = self::find_user_by_line_id( $line_user_id );
			if ( $existing_user_id && $existing_user_id !== get_current_user_id() ) {
				wc_add_notice( '此 LINE 帳號已綁定其他會員，請先於該會員帳號解除綁定後再試一次。', 'error' );
				wp_safe_redirect( $redirect );
				exit;
			}
			if ( WCLON_Settings::email_conflicts( $line_email, get_current_user_id() ) ) {
				wc_add_notice( '此 LINE 帳號的 Email 與您目前帳號的 Email 不符，無法綁定。', 'error' );
				wp_safe_redirect( $redirect );
				exit;
			}
			update_user_meta( get_current_user_id(), self::USER_META_KEY, $line_user_id );
			update_user_meta( get_current_user_id(), self::USER_META_NAME, $display_name );
			update_user_meta( get_current_user_id(), self::NOTIFY_META_KEY, 1 );
			self::maybe_issue_bind_coupon( get_current_user_id(), $line_user_id, $is_line_friend );
		}
		self::set_line_session( $line_user_id );

		wp_safe_redirect( add_query_arg( 'wclon_connected', '1', $redirect ) );
		exit;
	}

	private static function handle_disconnect() {
		if ( is_user_logged_in() && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wclon_disconnect' ) ) {
			delete_user_meta( get_current_user_id(), self::USER_META_KEY );
			delete_user_meta( get_current_user_id(), self::USER_META_NAME );
			delete_user_meta( get_current_user_id(), self::NOTIFY_META_KEY );
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
		wp_safe_redirect( wp_get_referer() ?: home_url() );
		exit;
	}

	private static function handle_toggle_notify() {
		if ( ! is_user_logged_in() || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wclon_toggle_notify' ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}
		$current = self::get_notify_enabled( get_current_user_id() );
		update_user_meta( get_current_user_id(), self::NOTIFY_META_KEY, $current ? 0 : 1 );
		wp_safe_redirect( wp_get_referer() ?: wc_get_account_endpoint_url( WCLON_ACCOUNT_ENDPOINT ) );
		exit;
	}

	/* ---------- 新帳號自動綁定 ---------- */

	public static function auto_link_on_register( $customer_id ) {
		$line_id = self::get_current_line_user_id();
		if ( ! $line_id ) {
			return;
		}
		// 確認這個 LINE ID 尚未綁定其他帳號
		if ( ! self::find_user_by_line_id( $line_id ) ) {
			update_user_meta( $customer_id, self::USER_META_KEY, $line_id );
			update_user_meta( $customer_id, self::NOTIFY_META_KEY, 1 );
		}
	}

	/* ---------- 訂單綁定 ---------- */

	public static function attach_line_id_to_order( $order ) {
		$line_id = self::get_current_line_user_id();
		if ( $line_id ) {
			$order->update_meta_data( '_wclon_line_user_id', $line_id );
		}
	}

	/* ---------- Helper ---------- */

	/**
	 * 從 LINE ID Token（JWT）解碼出 email。
	 *
	 * 拿不到 email 時一律寫 error_log（前綴 `WCLON LINE:`）並說明是卡在哪一步——
	 * 這條路徑失敗的表現是「登入完全正常、只是帳號 email 變成 line_xxx@noemail.invalid」，
	 * 沒有任何錯誤訊息，光看前台完全分不出是 LINE Developers Console 的 email 權限沒核准、
	 * 還是 token 解析出了問題。**特別注意 LINE 對未核准的 email 權限不會回報錯誤**，
	 * 授權請求照樣成功，只是 id_token 裡不會有 email claim。
	 */
	private static function extract_email_from_id_token( $id_token ) {
		if ( ! $id_token ) {
			self::log( 'token 交換的回應裡沒有 id_token（scope 是否有帶 openid？）' );
			return '';
		}
		$parts = explode( '.', $id_token );
		if ( count( $parts ) !== 3 ) {
			self::log( 'id_token 不是三段式 JWT，無法解析' );
			return '';
		}
		$padded  = str_pad( strtr( $parts[1], '-_', '+/' ), ( strlen( $parts[1] ) + 3 ) & ~3, '=', STR_PAD_RIGHT );
		$payload = json_decode( base64_decode( $padded ), true );
		if ( ! is_array( $payload ) ) {
			self::log( 'id_token 的 payload 無法解析為 JSON' );
			return '';
		}

		$email = sanitize_email( $payload['email'] ?? '' );
		if ( '' === $email ) {
			// 只記 claim 的「欄位名稱」，不記值——payload 裡有 sub（LINE User ID）與 name 等個資
			self::log(
				'id_token 裡沒有可用的 email claim。這次拿到的 claim 有：'
				. implode( ', ', array_keys( $payload ) )
				. '。兩種可能：(1) LINE Developers Console → 該 LINE Login channel → OpenID Connect →'
				. ' Email address permission 尚未核准（未核准時 LINE 不會報錯，只是不給這個 claim）；'
				. '(2) 權限已是 Applied，但這位顧客在權限核准「之前」就授權過本站，LINE 記住了當初不含'
				. ' email 的同意結果、不會重複詢問——此時請開啟設定頁「LINE」分頁的「強制重新詢問同意」'
				. '（prompt=consent），或請顧客在 LINE App 移除本站的授權後重新登入'
			);
		}
		return $email;
	}

	/**
	 * login / register / checkout 三種 intent 共用的帳號查找：先用 LINE User ID，找不到再用 email
	 * 比對既有帳號（v1.25.0 新增，與 `WCLON_Google_Login`／`WCLON_Apple_Login` 的做法一致）。
	 *
	 * 沒有這段 fallback 時，顧客若在站上已經有一個同 email 的帳號，用 LINE 登入不會認出它，
	 * 而是走到 `create_user_from_line()`——那裡的 `! email_exists()` 判斷會落到 else，
	 * **靜默**建立第二個 email 為 `line_xxx@noemail.invalid` 的重複帳號。
	 *
	 * **信任假設**：Google／Apple 的同一段 fallback 在 v1.18.0 加了 `email_verified` 條件擋帳號
	 * 接管風險（見「Google/Apple 帳號接管風險修正」），但 LINE 的 id_token **沒有** email_verified
	 * 這個 claim，這裡沒有等價的檢查——依據是 LINE 帳號要登記 email 本來就必須通過 LINE 自己的
	 * 驗證流程，因此 id_token 裡出現的 email 一律視為已驗證。若日後 LINE 改變 email 的驗證政策，
	 * 這段必須跟著重新評估。
	 *
	 * @return array{0:int,1:bool} [ 會員 ID（0 = 找不到）, 是否靠 email 對上（true 代表該帳號原本沒綁過 LINE） ]
	 */
	private static function resolve_login_user( $line_user_id, $line_email ) {
		$user_id = self::find_user_by_line_id( $line_user_id );
		if ( $user_id ) {
			return array( (int) $user_id, false ); // LINE ID 優先於 email
		}

		if ( $line_email ) {
			$existing = get_user_by( 'email', $line_email );
			if ( $existing ) {
				return array( (int) $existing->ID, true );
			}
		}

		return array( 0, false );
	}

	private static function log( $message ) {
		error_log( 'WCLON LINE: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * 既有帳號若 email 還是佔位符（`xxx@noemail.invalid`），而這次 LINE 有給真實 email 就補上。
	 *
	 * 沒有這一步的話，**Email 權限核准之前建立的帳號會永遠卡著佔位 email**：`handle_callback()`
	 * 在 `find_user_by_line_id()` 找到帳號後就直接登入，從來不會回頭更新 email，就算之後
	 * LINE Developers Console 的權限過了、id_token 開始有 email，那些舊帳號也不會自己修好。
	 *
	 * 刻意只補「佔位符」這一種情況：顧客自己在帳戶詳細資料頁填過的真實 email 一律不覆蓋
	 * （LINE 上登記的 email 未必是顧客希望用來收訂單通知的那個）；email 已經屬於別的帳號時
	 * 也直接跳過，不搶別人的 email。
	 */
	private static function maybe_backfill_email( $user_id, $line_email ) {
		if ( ! $line_email || ! is_email( $line_email ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! str_contains( (string) $user->user_email, '@noemail.invalid' ) ) {
			return; // 沒有這個帳號，或 email 已經是真實的 → 不動
		}
		$owner = email_exists( $line_email );
		if ( $owner && (int) $owner !== (int) $user_id ) {
			self::log( "帳號 #{$user_id} 的佔位 email 未補上：LINE 提供的 email 已屬於帳號 #{$owner}" );
			return;
		}
		$result = wp_update_user( array(
			'ID'         => $user_id,
			'user_email' => $line_email,
		) );
		if ( is_wp_error( $result ) ) {
			self::log( "帳號 #{$user_id} 的佔位 email 補寫失敗：" . $result->get_error_message() );
		}
	}

	/**
	 * 用 LINE 資料自動建立 WooCommerce 顧客帳號
	 *
	 * @return int|WP_Error
	 */
	private static function create_user_from_line( $line_user_id, $display_name, $line_email = '' ) {
		// 產生唯一 username
		$base = sanitize_user( remove_accents( $display_name ), true );
		if ( empty( $base ) ) {
			$base = 'line_user';
		}
		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . '_' . $i++;
		}

		// 決定 email：優先使用 LINE 提供的 email，否則用佔位符
		if ( $line_email && is_email( $line_email ) && ! email_exists( $line_email ) ) {
			$email = $line_email;
		} else {
			$email = 'line_' . strtolower( substr( $line_user_id, 0, 12 ) ) . '@noemail.invalid';
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 18 ),
			'first_name'   => $display_name,
			'display_name' => $display_name,
			'role'         => 'customer',
			'meta_input'   => array(
				'billing_first_name' => $display_name,
			),
		) );

		return $user_id;
	}

	/**
	 * LINE 直接註冊後，在帳戶詳細資料頁顯示補填 email 的提示
	 */
	public static function render_new_account_notice() {
		if ( empty( $_GET['wclon_new_account'] ) ) {
			return;
		}
		$user  = wp_get_current_user();
		$email = $user->user_email ?? '';
		if ( str_contains( $email, '@noemail.invalid' ) ) {
			echo '<div class="woocommerce-message" role="alert">您已透過 LINE 完成快速註冊！請在下方更新您的電子郵件地址，以便接收訂單確認信與密碼重設。</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ---------- 綁定歡迎優惠券 ---------- */

	/**
	 * 會員帳號第一次成功綁定 LINE 時，建立一張限定該會員使用一次的優惠券並用 LINE 推播。
	 * 用 COUPON_CODE_META_KEY 是否已存在判斷「這個 WordPress 會員是否已發過」——這個 meta
	 * 不會在解除綁定（handle_disconnect）時被清除，避免顧客反覆解綁／重新綁定重複領取。
	 *
	 * **v1.18.3 起額外檢查「這個 LINE 帳號」是否已領過**（見 line_id_already_claimed()）：
	 * 原本只認 WordPress 會員帳號，同一個 LINE 帳號拿去綁定第二個新會員（例如重新用 LINE
	 * 快速註冊出另一個帳號）就能再領一次，屬於漏洞；現在改成「WordPress 會員」與「LINE 帳號」
	 * 兩邊都沒領過才會發，且 LINE 帳號這邊的紀錄與任何 WordPress 帳號無關（就算日後那個
	 * 帳號被刪除，紀錄依然保留，同一個 LINE 帳號永遠不會再領到第二張）。
	 *
	 * @param bool $is_friend （v1.16.3 新增）目前是否已加官方帳號好友。false 時直接跳過、
	 *                         也不寫入任何「已發放」紀錄，之後顧客真的加好友時（LINE Webhook
	 *                         的 follow 事件，見 WCAN_Webhook::maybe_issue_line_bind_coupon()）
	 *                         會再呼叫一次本函式補發，屆時 $is_friend 固定傳 true。
	 */
	private static function maybe_issue_bind_coupon( $user_id, $line_user_id, $is_friend = true ) {
		if ( ! WCLON_Settings::get( 'line_bind_coupon_enabled' ) ) {
			return;
		}
		if ( get_user_meta( $user_id, self::COUPON_CODE_META_KEY, true ) ) {
			return;
		}
		if ( self::line_id_already_claimed( $line_user_id ) ) {
			return;
		}
		if ( ! $is_friend ) {
			return;
		}
		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return;
		}

		$coupon = self::create_bind_coupon( $user_id );
		if ( ! $coupon ) {
			return;
		}

		// 先寫入兩邊的「已發放」紀錄再嘗試推播：優惠券本身已經是真實存在的 WC 優惠券
		// （可由後台查到），就算推播失敗（例如顧客剛加好友但 LINE 那邊還沒同步完成）
		// 也不重複建立第二張。
		update_user_meta( $user_id, self::COUPON_CODE_META_KEY, $coupon->get_code() );
		self::mark_line_id_claimed( $line_user_id );

		WCLON_Notifier::send_bind_coupon_notice( $line_user_id, $coupon );
	}

	/**
	 * 這個 LINE 帳號是否已經領過綁定歡迎優惠券——與目前綁定哪個 WordPress 會員無關，
	 * 用獨立的 option 記錄，即使原本領取的會員帳號後來被刪除，紀錄依然保留。
	 */
	private static function line_id_already_claimed( $line_user_id ) {
		$claimed = (array) get_option( self::LINE_ID_COUPON_CLAIMED_OPTION, array() );
		return in_array( $line_user_id, $claimed, true );
	}

	private static function mark_line_id_claimed( $line_user_id ) {
		$claimed = (array) get_option( self::LINE_ID_COUPON_CLAIMED_OPTION, array() );
		if ( in_array( $line_user_id, $claimed, true ) ) {
			return;
		}
		$claimed[] = $line_user_id;
		update_option( self::LINE_ID_COUPON_CLAIMED_OPTION, $claimed, false );
	}

	/**
	 * 依後台設定（折扣類型／數值／有效天數／最低消費）建立優惠券，
	 * 限定該會員使用一次；實際建立邏輯與 twshop 相容處理集中在 WCLON_Settings::create_customer_coupon()。
	 */
	private static function create_bind_coupon( $user_id ) {
		$type   = WCLON_Settings::get( 'line_bind_coupon_type', 'fixed_cart' );
		$amount = (float) WCLON_Settings::get( 'line_bind_coupon_amount', 100 );

		return WCLON_Settings::create_customer_coupon(
			$user_id,
			'LINE',
			$type,
			$amount,
			(int) WCLON_Settings::get( 'line_bind_coupon_expiry_days', 30 ),
			(float) WCLON_Settings::get( 'line_bind_coupon_min_spend', 0 ),
			'LINE 綁定歡迎禮',
			'感謝您綁定 LINE 帳號，折抵 ' . WCLON_Settings::format_coupon_amount( $type, $amount ),
			sprintf( 'LINE 綁定歡迎優惠券（會員 #%d）', $user_id )
		);
	}

	/**
	 * 查詢目前使用者是否已加官方帳號好友（v1.16.3 新增）。
	 * 用 LINE Login 換到的「使用者 access token」查詢（不是 Channel Access Token），
	 * 需要 LINE Login channel 已完成「Linked OA」設定（既有前置作業，見「LINE」分頁說明）
	 * 才查得到正確結果。API 呼叫本身失敗（逾時、LINE 那邊異常等）時預設視為「已加好友」放行，
	 * 避免因為查詢本身不穩定而誤擋原本就有加好友的合法顧客——這只是加分限制，不是安全機制，
	 * 寧可放過極少數邊界案例，也不要讓多數正常顧客領不到優惠券。
	 */
	private static function is_line_friend( $access_token ) {
		$response = wp_remote_get( 'https://api.line.me/friendship/v1/status', array(
			'timeout' => 10,
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return true;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $body['friendFlag'] );
	}

	/**
	 * 供 WCAN_Webhook 在收到 LINE 的 follow 事件（顧客加官方帳號好友）時呼叫，
	 * 補發綁定當下因為尚未加好友而被跳過的歡迎優惠券。找不到對應的 WP 帳號
	 * （這個 LINE User ID 從沒綁定過任何會員）時什麼都不做。內部沿用
	 * maybe_issue_bind_coupon() 既有的「模組開關」「是否已發過」判斷，
	 * 不需要額外狀態記錄哪些帳號在等發券。
	 */
	public static function maybe_issue_bind_coupon_for_line_id( $line_user_id ) {
		$user_id = self::find_user_by_line_id( $line_user_id );
		if ( ! $user_id ) {
			return;
		}
		self::maybe_issue_bind_coupon( $user_id, $line_user_id, true );
	}

	private static function find_user_by_line_id( $line_user_id ) {
		$users = get_users( array(
			'meta_key'   => self::USER_META_KEY,
			'meta_value' => $line_user_id,
			'number'     => 1,
			'fields'     => 'ids',
		) );
		return ! empty( $users ) ? (int) $users[0] : 0;
	}

	private static function set_line_session( $line_user_id ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->session->set( self::SESSION_KEY, $line_user_id );
		}
	}

	public static function get_current_line_user_id() {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}
		if ( is_user_logged_in() ) {
			$id = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
			if ( $id ) {
				$cache = $id;
				return $cache;
			}
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			$id = WC()->session->get( self::SESSION_KEY );
			if ( $id ) {
				$cache = $id;
				return $cache;
			}
		}
		$cache = '';
		return $cache;
	}

	/**
	 * 取得使用者的訂單通知開關（預設 true）
	 */
	public static function get_notify_enabled( $user_id ) {
		$val = get_user_meta( $user_id, self::NOTIFY_META_KEY, true );
		return $val === '' ? true : (bool) $val;
	}

	/* ---------- 前台綁定按鈕（結帳/我的帳號）---------- */

	public static function echo_connect_button() {
		echo self::render_connect_button(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_connect_button() {
		if ( ! WCLON_Settings::get( 'login_channel_id' ) ) {
			return '';
		}

		$line_id      = self::get_current_line_user_id();
		$connected    = (bool) $line_id;
		$display_name = '';
		if ( $connected && is_user_logged_in() ) {
			$display_name = (string) get_user_meta( get_current_user_id(), self::USER_META_NAME, true );
		}

		$current_url = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$current     = ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . $current_url;

		ob_start();
		?>
		<div class="wclon-connect-box">
			<?php if ( $connected ) :
				$notify_on = is_user_logged_in() ? self::get_notify_enabled( get_current_user_id() ) : true;
			?>
				<div class="wclon-ios-row">
					<span class="wclon-connected-status wclon-connected-status--line"><?php echo self::line_icon_green(); // phpcs:ignore WordPress.Security.EscapeOutput ?> 已綁定 LINE<?php echo $display_name ? '（' . esc_html( $display_name ) . '）' : ''; ?></span>
				</div>
				<?php if ( is_user_logged_in() ) : ?>
					<div class="wclon-ios-row">
						<span class="wclon-ios-label">訂單通知</span>
						<a class="wclon-notify-toggle<?php echo $notify_on ? ' wclon-notify-toggle--on' : ''; ?>"
						   href="<?php echo esc_url( wp_nonce_url( home_url( '/?wclon_action=toggle_notify' ), 'wclon_toggle_notify' ) ); ?>"
						   title="<?php echo $notify_on ? '點擊關閉訂單通知' : '點擊開啟訂單通知'; ?>">
							<span class="wclon-notify-toggle__track"><span class="wclon-notify-toggle__thumb"></span></span>
						</a>
					</div>
					<div class="wclon-ios-row wclon-ios-row--action">
						<a class="wclon-unlink-text" href="<?php echo esc_url( wp_nonce_url( home_url( '/?wclon_action=disconnect' ), 'wclon_disconnect' ) ); ?>">解除綁定</a>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<?php // intent=link 只能用在這裡（會員中心，使用者必定已登入）；結帳／購物車頁的入口一律用 intent=checkout，見 CLAUDE.md 踩坑 ?>
				<a class="wclon-connect-btn<?php echo esc_attr( WCLON_Settings::get_btn_classes() ); ?>" href="<?php echo esc_url( home_url( '/?wclon_action=login&intent=link&redirect=' . rawurlencode( $current ) ) ); ?>" aria-label="用 LINE 綁定，接收訂單通知">
					<?php echo self::line_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="wclon-btn-label">用 LINE 綁定，接收訂單通知</span>
				</a>
				<div class="wclon-connect-hint">綁定後即可透過 LINE 官方帳號收到訂單狀態通知。</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------- 精簡 chip（結帳頁 / 帳戶詳細資料頁共用）---------- */

	private static function render_chip( $intent, $redirect, $label = 'LINE' ) {
		if ( ! WCLON_Settings::get( 'login_channel_id' ) ) {
			return '';
		}
		$shape_class = esc_attr( WCLON_Settings::get_shape_class() );
		if ( self::get_current_line_user_id() ) {
			return '<span class="wclon-chip wclon-chip--done' . $shape_class . '"><span class="wclon-chip__check">✓</span>LINE</span>';
		}
		$url = home_url( '/?wclon_action=login&intent=' . $intent . '&redirect=' . rawurlencode( $redirect ) );
		return '<a class="wclon-chip wclon-chip--line' . $shape_class . '" href="' . esc_url( $url ) . '">' . self::line_icon() . esc_html( $label ) . '</a>';
	}

	public static function echo_checkout_chip() {
		echo self::render_checkout_chip(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_checkout_chip() {
		$current_url = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$current     = ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . $current_url;
		return self::render_chip( 'checkout', $current );
	}

	/* ---------- 帳戶詳細資料頁：獨立一行的綁定 / 解除綁定列 ---------- */

	public static function echo_account_row() {
		echo self::render_account_row(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_account_row() {
		if ( ! WCLON_Settings::get( 'login_channel_id' ) ) {
			return '';
		}
		ob_start();
		if ( self::get_current_line_user_id() ) {
			$display_name = is_user_logged_in() ? (string) get_user_meta( get_current_user_id(), self::USER_META_NAME, true ) : '';
			$unlink_url   = wp_nonce_url( home_url( '/?wclon_action=disconnect' ), 'wclon_disconnect' );
			?>
			<div class="wclon-account-social__row">
				<span class="wclon-connected-status wclon-connected-status--line"><?php echo self::line_icon_green(); // phpcs:ignore WordPress.Security.EscapeOutput ?> 已綁定 LINE<?php echo $display_name ? '（' . esc_html( $display_name ) . '）' : ''; ?></span>
				<a class="wclon-unlink-text" href="<?php echo esc_url( $unlink_url ); ?>">解除綁定</a>
			</div>
			<?php
		} else {
			$bind_url = home_url( '/?wclon_action=login&intent=link&redirect=' . rawurlencode( wc_get_account_endpoint_url( WCLON_ACCOUNT_ENDPOINT ) ) );
			?>
			<div class="wclon-account-social__row">
				<span class="wclon-connected-status wclon-connected-status--line"><?php echo self::line_icon_green(); // phpcs:ignore WordPress.Security.EscapeOutput ?> LINE</span>
				<a class="wclon-link-text" href="<?php echo esc_url( $bind_url ); ?>">綁定帳號</a>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	public static function echo_account_actions() {
		echo self::render_account_actions(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_account_actions() {
		if ( ! WCLON_Settings::get( 'login_channel_id' ) || ! self::get_current_line_user_id() ) {
			return '';
		}
		$notify_on = self::get_notify_enabled( get_current_user_id() );
		ob_start();
		?>
		<div class="wclon-account-social__row">
			<span class="wclon-account-social__row-label">LINE 訂單通知</span>
			<a class="wclon-notify-toggle<?php echo $notify_on ? ' wclon-notify-toggle--on' : ''; ?>"
			   href="<?php echo esc_url( wp_nonce_url( home_url( '/?wclon_action=toggle_notify' ), 'wclon_toggle_notify' ) ); ?>"
			   title="<?php echo $notify_on ? '點擊關閉訂單通知' : '點擊開啟訂單通知'; ?>">
				<span class="wclon-notify-toggle__track"><span class="wclon-notify-toggle__thumb"></span></span>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------- WP 登入頁按鈕 ---------- */

	public static function echo_wp_login_button() {
		// login_form 在 wp-login.php 固定位於「密碼欄位之後、登入按鈕之前」（WP 核心版面，無法更動）。
		// 若這個 hook 是被其他主題自訂表單一併觸發（例如 Blocksy 彈出登入視窗，同時也會觸發
		// woocommerce_login_form_end），就交給稍後的 woocommerce_login_form_end 渲染，讓按鈕
		// 出現在登入按鈕「下方」而非「上方」；只有真正的 wp-login.php 頁面（用只有該頁才會觸發
		// 的 login_init 判斷）才在這裡渲染，因為那裡本來就沒有 woocommerce_login_form_end 可用。
		if ( ! did_action( 'login_init' ) ) {
			return;
		}
		echo self::render_line_auth_button( // phpcs:ignore WordPress.Security.EscapeOutput
			'login',
			wp_login_url(),
			'用 LINE 登入'
		);
	}

	/**
	 * 在 WP 登入頁顯示 LINE 相關通知（錯誤或引導訊息）
	 *
	 * @param string $message 原始 login_message 內容
	 * @return string
	 */
	public static function render_login_page_notice( $message ) {
		if ( ! empty( $_GET['wclon_register'] ) ) {
			$message .= '<div class="message" style="border-left-color:#00c300;">已取得您的 LINE 資料。請用帳號密碼登入，登入後將自動完成 LINE 綁定。</div>';
		}
		return $message;
	}

	/* ---------- WC 登入表單按鈕 ---------- */

	public static function echo_wc_login_button() {
		$redirect = wc_get_account_endpoint_url( 'dashboard' );
		echo self::render_line_auth_button( 'login', $redirect, '用 LINE 登入' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* ---------- WC 註冊表單按鈕 ---------- */

	public static function echo_wc_register_button() {
		$redirect = wc_get_page_permalink( 'myaccount' );
		echo self::render_line_auth_button( 'register', $redirect, '用 LINE 快速綁定' ); // phpcs:ignore WordPress.Security.EscapeOutput
		// 若 LINE OAuth 後帶著 wclon_register=1 回來，顯示引導訊息
		if ( ! empty( $_GET['wclon_register'] ) ) {
			echo '<p style="color:#00a32a;background:#f0fdf4;border:1px solid #bbf7d0;padding:8px 12px;border-radius:4px;margin-top:8px;font-size:13px;">已取得您的 LINE 資料。完成下方表單後即自動綁定 LINE 通知。</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ---------- 共用按鈕渲染 ---------- */

	/**
	 * @param string $intent  'link' | 'login' | 'register'
	 * @param string $redirect 成功後的跳轉 URL
	 * @param string $label   按鈕文字
	 */
	private static function render_line_auth_button( $intent, $redirect, $label ) {
		$url = home_url( '/?wclon_action=login&intent=' . rawurlencode( $intent ) . '&redirect=' . rawurlencode( $redirect ) );
		ob_start();
		?>
		<div class="wclon-auth-wrap">
			<a class="wclon-auth-btn<?php echo esc_attr( WCLON_Settings::get_btn_classes() ); ?>" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php echo self::line_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="wclon-btn-label"><?php echo esc_html( $label ); ?></span>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * 白色 LINE icon，用於品牌綠底按鈕 / chip 上
	 */
	private static function line_icon() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="#ffffff" aria-hidden="true"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>';
	}

	/**
	 * 綠色 LINE icon，用於淺色背景上的「已綁定」狀態文字（白色 icon 在此會看不見）
	 */
	private static function line_icon_green() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="#00c300" aria-hidden="true"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>';
	}

	/* ---------- 後台使用者頁面 ---------- */

	public static function render_user_profile_section( $user ) {
		// 處理後台管理員清除綁定
		if (
			isset( $_GET['wclon_clear_line'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wclon_clear_line_' . $user->ID ) &&
			current_user_can( 'edit_user', $user->ID )
		) {
			delete_user_meta( $user->ID, self::USER_META_KEY );
			delete_user_meta( $user->ID, self::USER_META_NAME );
		}

		$line_id      = (string) get_user_meta( $user->ID, self::USER_META_KEY, true );
		$display_name = (string) get_user_meta( $user->ID, self::USER_META_NAME, true );
		$coupon_code  = (string) get_user_meta( $user->ID, self::COUPON_CODE_META_KEY, true );
		?>
		<h2>LINE 帳號綁定</h2>
		<table class="form-table">
			<tr>
				<th>綁定狀態</th>
				<td>
					<?php if ( $line_id ) : ?>
						<span style="color:#00b300;font-weight:bold;">✓ 已綁定</span>
						<?php if ( $display_name ) : ?>
							<span style="margin-left:8px;color:#555;">LINE 名稱：<strong><?php echo esc_html( $display_name ); ?></strong></span>
						<?php endif; ?>
						<br><span style="font-size:12px;color:#999;margin-top:4px;display:inline-block;">User ID：<?php echo esc_html( $line_id ); ?></span>
					<?php else : ?>
						<span style="color:#999;">尚未綁定</span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $line_id ) : ?>
			<tr>
				<th>訂單通知</th>
				<td>
					<?php if ( self::get_notify_enabled( $user->ID ) ) : ?>
						<span style="color:#00b300;font-weight:bold;">✓ 已開啟</span>
					<?php else : ?>
						<span style="color:#999;">✗ 已關閉（顧客自行關閉）</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>清除綁定</th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'user-edit.php?user_id=' . $user->ID . '&wclon_clear_line=1' ), 'wclon_clear_line_' . $user->ID ) ); ?>"
					   onclick="return confirm('確定要清除此帳號的 LINE 綁定嗎？');"
					   class="button button-secondary">清除 LINE 綁定</a>
					<p class="description">清除後，顧客可自行在前台重新綁定。</p>
				</td>
			</tr>
			<?php endif; ?>
			<?php if ( $coupon_code ) : ?>
			<tr>
				<th>綁定歡迎優惠券</th>
				<td>
					<code><?php echo esc_html( $coupon_code ); ?></code>
					<?php
					$coupon_id = wc_get_coupon_id_by_code( $coupon_code );
					if ( $coupon_id ) {
						echo ' <a href="' . esc_url( get_edit_post_link( $coupon_id ) ) . '" target="_blank">查看優惠券</a>';
					}
					?>
					<p class="description">此帳號第一次綁定 LINE 時已自動發送過優惠券，之後不會再重複發送（即使之後解除綁定也不會）。</p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}
}
