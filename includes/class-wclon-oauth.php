<?php
/**
 * LINE／Google／Apple 三個登入 provider 共用的 OAuth 流程工具（v1.39.0 新增）。
 *
 * 原本三個 class 各自寫了一份 state 發放/驗證、以 meta 找會員、產生不重複帳號、登入的程式碼，
 * 抽到這裡只剩一份。其中 state 的**瀏覽器綁定**是 v1.39.0 的安全修正：改版前 state 只存在
 * transient 裡，任何拿到 callback 網址（含 code 與 state）的人都能在自己的瀏覽器完成流程。
 * 攻擊者可以用自己的 LINE 帳號走到授權完成、攔下 callback 網址，再騙已登入的顧客點開——
 * 攻擊者的 LINE 就被綁到顧客帳號上，之後攻擊者用 LINE 登入就進得去顧客帳號（LINE 授權時
 * 可以不提供 email，此時 email 不符的防線 WCLON_Settings::email_conflicts() 會直接放行）。
 * 未登入的顧客則會被登入成攻擊者的帳號、或被塞進攻擊者的 LINE ID（之後註冊會自動綁上）。
 * 現在發放 state 時同時寫一個只有這個瀏覽器才有的 cookie，callback 時兩者必須相符。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_OAuth {

	const COOKIE_PREFIX = 'wclon_oauth_';
	const STATE_TTL     = 10 * MINUTE_IN_SECONDS;
	const INTENTS       = array( 'link', 'login', 'register', 'checkout', 'verify' );

	/** 目前這次 callback 是不是管理員的憑證驗證（v1.43.0）：值是 provider key，不是就 null */
	private static $verifying = null;

	/**
	 * 發放一組 state：transient 存導回網址與 intent，並把 state 綁定到目前的瀏覽器。
	 * 導回網址與 intent 讀自 $_GET（redirect／intent），intent 不在白名單時視為 link。
	 */
	public static function start_state( $transient_prefix ) {
		$state    = wp_generate_password( 24, false );
		$redirect = ! empty( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : WCLON_WC::default_redirect(); // phpcs:ignore WordPress.Security.NonceVerification
		$intent   = sanitize_text_field( wp_unslash( $_GET['intent'] ?? 'link' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $intent, self::INTENTS, true ) ) {
			$intent = 'link';
		}
		if ( 'verify' === $intent ) {
			// 驗證只給管理員，且一定要從設定頁的按鈕發起。導回網址固定是設定頁，不吃 $_GET['redirect']。
			// callback 端不再檢查登入狀態：Apple 的 callback 是跨站 POST，WordPress 的登入 cookie
			// 帶不過去（SameSite 預設 Lax），這裡的權限檢查加上 state 的瀏覽器綁定就是全部的防線。
			if ( ! current_user_can( WCLON_WC::capability() ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), WCLON_Verify::NONCE_ACTION ) ) {
				wp_die( esc_html( '驗證連結已失效，請回到終極登入設定頁重新按「驗證」。' ) );
			}
			$redirect = admin_url( 'admin.php?page=wclon-settings' );
		}

		set_transient( $transient_prefix . $state, array(
			'redirect' => $redirect,
			'intent'   => $intent,
		), self::STATE_TTL );
		self::set_cookie( $state, self::cookie_value( $state ), time() + self::STATE_TTL );

		return $state;
	}

	/**
	 * 驗證並消耗 state（一次性）。transient 不存在或不是同一個瀏覽器發出的就 wp_die()。
	 *
	 * @return array{redirect:string,intent:string}
	 */
	public static function finish_state( $transient_prefix, $state, $provider_label ) {
		$data = get_transient( $transient_prefix . $state );
		if ( false === $data ) {
			wp_die( esc_html( "驗證逾時，請重新操作 {$provider_label} 登入。" ) );
		}
		delete_transient( $transient_prefix . $state );

		$name   = self::cookie_name( $state );
		$cookie = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		self::set_cookie( $state, '', time() - HOUR_IN_SECONDS );
		if ( '' === $cookie || ! hash_equals( self::cookie_value( $state ), $cookie ) ) {
			wp_die( esc_html( "{$provider_label} 登入驗證失敗：請在同一個瀏覽器中完成登入流程，再重新操作一次。" ) );
		}

		// 向下相容：LINE 最早期的 transient 直接存導回網址字串
		if ( is_string( $data ) ) {
			return array( 'redirect' => $data, 'intent' => 'link' );
		}
		$intent = $data['intent'] ?? 'link';
		if ( 'verify' === $intent ) {
			self::$verifying = strtolower( $provider_label );
		}
		return array(
			'redirect' => $data['redirect'] ?? home_url(),
			'intent'   => $intent,
		);
	}

	// ─── 憑證驗證（v1.43.0）─────────────────────────────────────────────────
	//
	// 三個 provider 的 callback 共用：finish_state() 拿到 intent=verify 後，callback 照常換 token、
	// 取使用者 ID，失敗時呼叫 fail()（驗證模式導回設定頁顯示原因，一般模式照舊 wp_die），
	// 成功走到「取得使用者 ID」那一步就呼叫 complete_verify()，不進入任何建帳號／登入／綁定的分支。

	/** 目前這次請求是不是從設定頁發起的驗證（redirect_to_*() 用來放行尚未驗證的 provider） */
	public static function is_verify_request() {
		return 'verify' === ( $_GET['intent'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- start_state() 會驗 nonce
	}

	public static function verifying() {
		return null !== self::$verifying;
	}

	public static function fail( $message ) {
		if ( self::verifying() ) {
			WCLON_Verify::record_failure( self::$verifying, $message );
			wp_safe_redirect( WCLON_Verify::settings_url( self::$verifying ) );
			exit;
		}
		wp_die( esc_html( $message ) );
	}

	public static function complete_verify( array $details = array() ) {
		WCLON_Verify::record_success( self::$verifying, $details );
		wp_safe_redirect( WCLON_Verify::settings_url( self::$verifying ) );
		exit;
	}

	/**
	 * provider 沒給 code 就導回來（使用者在授權頁按取消、或 provider 回報錯誤）時呼叫：這次如果是
	 * 驗證流程，就記下原因導回設定頁；不是的話直接 return，讓呼叫端照原本的方式處理。
	 */
	public static function fail_if_verifying( $transient_prefix, $state, $provider_label, $message ) {
		$data = '' !== $state ? get_transient( $transient_prefix . $state ) : false;
		if ( ! is_array( $data ) || 'verify' !== ( $data['intent'] ?? '' ) ) {
			return;
		}
		self::finish_state( $transient_prefix, $state, $provider_label );
		self::fail( $message );
	}

	/**
	 * wp-login.php 社群登入按鈕登入成功後要去的網址：沿用登入頁的 redirect_to，沒有就進後台
	 * （非管理人員會被 WooCommerce 轉到我的帳號）。1.42.1 前直接用 wp_login_url()，登入後被導回
	 * 登入頁，核心不會把已登入的人送走，畫面停在登入表單，看起來像沒登入成功。
	 */
	public static function wp_login_redirect() {
		$to = isset( $_REQUEST['redirect_to'] ) ? trim( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		return '' === $to ? admin_url() : wp_validate_redirect( $to, admin_url() );
	}

	public static function find_user_by_meta( $meta_key, $value ) {
		$users = get_users( array(
			'meta_key'   => $meta_key,
			'meta_value' => $value,
			'number'     => 1,
			'fields'     => 'ids',
		) );
		return ! empty( $users ) ? (int) $users[0] : 0;
	}

	/**
	 * 用社群帳號資料建立 customer 帳號。email 可用（格式正確且尚未被佔用）時用真實 email，
	 * 否則用呼叫端給的佔位 email（`xxx@noemail.invalid`）。
	 *
	 * @return int|WP_Error
	 */
	public static function create_customer( $display_name, $email, $placeholder_email, $username_fallback, array $meta_input ) {
		$base = $display_name ? sanitize_user( remove_accents( $display_name ), true ) : '';
		if ( '' === $base ) {
			$base = $username_fallback;
		}
		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . '_' . $i++;
		}

		return wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => ( $email && is_email( $email ) && ! email_exists( $email ) ) ? $email : $placeholder_email,
			'user_pass'    => wp_generate_password( 18 ),
			'first_name'   => $display_name,
			'display_name' => $display_name,
			'role'         => 'customer',
			'meta_input'   => $meta_input,
		) );
	}

	public static function log_in( $user_id ) {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		$user = get_userdata( $user_id );
		do_action( 'wp_login', $user->user_login, $user );
	}

	private static function cookie_name( $state ) {
		return self::COOKIE_PREFIX . substr( md5( $state ), 0, 16 );
	}

	private static function cookie_value( $state ) {
		return hash_hmac( 'sha256', $state, wp_salt( 'nonce' ) );
	}

	/**
	 * Apple 的 callback 是跨站 POST（response_mode=form_post），SameSite=Lax 的 cookie 不會被帶上，
	 * 所以 HTTPS 下用 SameSite=None（必須搭配 Secure）；HTTP 的開發站只能用 Lax（Apple 本來就
	 * 要求 HTTPS，LINE／Google 的 callback 是頂層 GET 導向，Lax 會帶）。
	 */
	private static function set_cookie( $state, $value, $expires ) {
		$secure = is_ssl();
		setcookie( self::cookie_name( $state ), $value, array(
			'expires'  => $expires,
			'path'     => '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => $secure ? 'None' : 'Lax',
		) );
	}
}
