<?php
/**
 * Apple ID Login：讓顧客透過 Apple ID 授權並快速登入 / 綁定帳號
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Apple_Login {

	const USER_META_KEY   = '_wclon_apple_user_id';
	const USER_META_NAME  = '_wclon_apple_display_name';
	const STATE_TRANSIENT = 'wclon_astate_';


	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_request' ) );
		add_shortcode( 'wclon_apple_connect', array( __CLASS__, 'render_connect_button' ) );

		// v1.30.0 起結帳頁 chip 改由 WCLON_Settings::render_social_bar() 直接呼叫，不再各自掛 hook
		if ( WCLON_Settings::get( 'show_on_myaccount', 1 ) ) {
			add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', array( __CLASS__, 'echo_account_row' ), 12 );
		}

		if ( WCLON_Settings::get( 'apple_client_id' ) ) {
			add_action( WCLON_Settings::social_hook( 'wp_login' ), array( __CLASS__, 'echo_wp_login_button' ) );
			add_action( WCLON_Settings::social_hook( 'wc_login' ), array( __CLASS__, 'echo_wc_login_button' ) );
			add_action( WCLON_Settings::social_hook( 'wc_register' ), array( __CLASS__, 'echo_wc_register_button' ) );
		}

		add_action( 'show_user_profile', array( __CLASS__, 'render_user_profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_profile_section' ) );
	}

	/* ---------- OAuth 流程 ---------- */

	public static function handle_request() {
		if ( empty( $_GET['wclon_action'] ) ) {
			return;
		}
		$action = sanitize_text_field( wp_unslash( $_GET['wclon_action'] ) );

		if ( 'apple_login' === $action ) {
			self::redirect_to_apple();
		} elseif ( 'apple_callback' === $action ) {
			self::handle_callback();
		} elseif ( 'apple_disconnect' === $action ) {
			self::handle_disconnect();
		}
	}

	private static function redirect_to_apple() {
		$client_id = WCLON_Settings::get( 'apple_client_id' );
		if ( ! $client_id ) {
			wp_die( '尚未設定 Apple Services ID。' );
		}

		$state = WCLON_OAuth::start_state( self::STATE_TRANSIENT );

		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => home_url( '/?wclon_action=apple_callback' ),
			'response_type' => 'code id_token',
			'response_mode' => 'form_post',
			'scope'         => 'name email',
			'state'         => $state,
		);

		wp_redirect( 'https://appleid.apple.com/auth/authorize?' . http_build_query( $params ) );
		exit;
	}

	private static function handle_callback() {
		// Apple 以 POST 方式回傳，wclon_action 仍在 Query String，其餘資料在 $_POST
		if ( ! empty( $_POST['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_POST['error'] ) );
			if ( 'user_cancelled_authorize' === $error ) {
				wp_safe_redirect( home_url() );
				exit;
			}
			wp_die( 'Apple 授權失敗：' . esc_html( $error ) );
		}

		if ( empty( $_POST['code'] ) || empty( $_POST['state'] ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$flow     = WCLON_OAuth::finish_state( self::STATE_TRANSIENT, sanitize_text_field( wp_unslash( $_POST['state'] ) ), 'Apple' );
		$redirect = $flow['redirect'];
		$intent   = $flow['intent'];

		// 向 Apple 換取 token
		$code          = sanitize_text_field( wp_unslash( $_POST['code'] ) );
		$client_secret = self::generate_client_secret();
		if ( ! $client_secret ) {
			wp_die( '無法產生 Apple client secret，請確認金鑰設定是否正確。' );
		}

		$response = wp_remote_post( 'https://appleid.apple.com/auth/token', array(
			'timeout' => 15,
			'body'    => array(
				'client_id'     => WCLON_Settings::get( 'apple_client_id' ),
				'client_secret' => $client_secret,
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => home_url( '/?wclon_action=apple_callback' ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_die( 'Apple 連線失敗：' . esc_html( $response->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['id_token'] ) ) {
			$err = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : '未知錯誤' );
			wp_die( 'Apple 授權失敗：' . esc_html( $err ) );
		}

		// 從 id_token 解析 Apple User ID 與 email（不驗簽，已透過伺服器端 token 交換取得）
		$claims               = self::parse_id_token( $body['id_token'] );
		$apple_id             = isset( $claims['sub'] ) ? sanitize_text_field( $claims['sub'] ) : '';
		$apple_email          = isset( $claims['email'] ) ? sanitize_email( $claims['email'] ) : '';
		// Apple 的 email_verified 可能是布林值或字串 "true"/"false"
		$apple_email_verified = isset( $claims['email_verified'] ) && filter_var( $claims['email_verified'], FILTER_VALIDATE_BOOLEAN );

		if ( ! $apple_id ) {
			wp_die( '無法從 Apple id_token 取得使用者 ID。' );
		}

		// 取得顯示名稱（僅首次 Apple 授權才附帶 user JSON，之後 Apple 不再傳送）
		$display_name = '';
		$first_name   = '';
		$last_name    = '';
		if ( ! empty( $_POST['user'] ) ) {
			// 先解析 JSON，再個別 sanitize，避免 sanitize_textarea_field 破壞 JSON 結構
			$user_json = json_decode( wp_unslash( $_POST['user'] ), true );
			if ( is_array( $user_json ) && isset( $user_json['name'] ) ) {
				$first_name   = isset( $user_json['name']['firstName'] ) ? sanitize_text_field( $user_json['name']['firstName'] ) : '';
				$last_name    = isset( $user_json['name']['lastName'] ) ? sanitize_text_field( $user_json['name']['lastName'] ) : '';
				$display_name = trim( $first_name . ' ' . $last_name );
			}
		}

		// ── 依 intent 決定行為 ─────────────────────────────────────────────────

		if ( 'login' === $intent || 'register' === $intent || 'checkout' === $intent ) {
			$wp_user_id = self::find_user_by_apple_id( $apple_id );

			// 未驗證的 email 不可信任，若以此比對既有帳號會有帳號接管風險
			if ( ! $wp_user_id && $apple_email && $apple_email_verified ) {
				$user = get_user_by( 'email', $apple_email );
				if ( $user ) {
					$wp_user_id = $user->ID;
				}
			}

			if ( $wp_user_id ) {
				update_user_meta( $wp_user_id, self::USER_META_KEY, $apple_id );
				if ( $display_name ) {
					update_user_meta( $wp_user_id, self::USER_META_NAME, $display_name );
				}
				WCLON_OAuth::log_in( $wp_user_id );
				wp_safe_redirect( $redirect );
				exit;
			}

			if ( is_user_logged_in() ) {
				if ( WCLON_Settings::email_conflicts( $apple_email, get_current_user_id() ) ) {
					wc_add_notice( '此 Apple 帳號的 Email 與您目前帳號的 Email 不符，無法綁定。', 'error' );
					wp_safe_redirect( $redirect );
					exit;
				}
				update_user_meta( get_current_user_id(), self::USER_META_KEY, $apple_id );
				if ( $display_name ) {
					update_user_meta( get_current_user_id(), self::USER_META_NAME, $display_name );
				}
				wp_safe_redirect( add_query_arg( 'wclon_apple_connected', '1', $redirect ) );
				exit;
			}

			// 未登入且無對應帳號 → 自動建立帳號
			$new_user_id = self::create_user_from_apple( $apple_id, $display_name, $apple_email, $first_name, $last_name );
			if ( is_wp_error( $new_user_id ) ) {
				wp_die( 'Apple 帳號建立失敗：' . esc_html( $new_user_id->get_error_message() ) );
			}

			update_user_meta( $new_user_id, self::USER_META_KEY, $apple_id );
			if ( $display_name ) {
				update_user_meta( $new_user_id, self::USER_META_NAME, $display_name );
			}
			WCLON_OAuth::log_in( $new_user_id );

			if ( 'checkout' === $intent ) {
				// 結帳頁 chip 註冊：完成後留在結帳頁
				wp_safe_redirect( add_query_arg( 'wclon_apple_connected', '1', $redirect ) );
			} else {
				wp_safe_redirect( wc_get_account_endpoint_url( 'dashboard' ) );
			}
			exit;
		}

		// ── intent = link（預設）：綁定到目前帳號 ─────────────────────────────

		if ( is_user_logged_in() ) {
			$existing_user_id = self::find_user_by_apple_id( $apple_id );
			if ( $existing_user_id && $existing_user_id !== get_current_user_id() ) {
				wc_add_notice( '此 Apple 帳號已綁定其他會員，請先於該會員帳號解除綁定後再試一次。', 'error' );
				wp_safe_redirect( $redirect );
				exit;
			}
			if ( WCLON_Settings::email_conflicts( $apple_email, get_current_user_id() ) ) {
				wc_add_notice( '此 Apple 帳號的 Email 與您目前帳號的 Email 不符，無法綁定。', 'error' );
				wp_safe_redirect( $redirect );
				exit;
			}
			update_user_meta( get_current_user_id(), self::USER_META_KEY, $apple_id );
			if ( $display_name ) {
				update_user_meta( get_current_user_id(), self::USER_META_NAME, $display_name );
			}
		}

		wp_safe_redirect( add_query_arg( 'wclon_apple_connected', '1', $redirect ) );
		exit;
	}

	private static function handle_disconnect() {
		if (
			is_user_logged_in() &&
			isset( $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wclon_apple_disconnect' )
		) {
			delete_user_meta( get_current_user_id(), self::USER_META_KEY );
			delete_user_meta( get_current_user_id(), self::USER_META_NAME );
		}
		wp_safe_redirect( wp_get_referer() ?: home_url() );
		exit;
	}

	/* ---------- Apple client_secret JWT 產生 ---------- */

	private static function generate_client_secret() {
		$client_id   = WCLON_Settings::get( 'apple_client_id' );
		$team_id     = WCLON_Settings::get( 'apple_team_id' );
		$key_id      = WCLON_Settings::get( 'apple_key_id' );
		$private_key = WCLON_Settings::get( 'apple_private_key' );

		if ( ! $client_id || ! $team_id || ! $key_id || ! $private_key ) {
			return false;
		}

		$now    = time();
		$header  = self::base64url_encode( (string) json_encode( array( 'alg' => 'ES256', 'kid' => $key_id ) ) );
		$payload = self::base64url_encode( (string) json_encode( array(
			'iss' => $team_id,
			'iat' => $now,
			'exp' => $now + 3600,
			'aud' => 'https://appleid.apple.com',
			'sub' => $client_id,
		) ) );

		$signing_input = $header . '.' . $payload;

		$pkey = openssl_pkey_get_private( $private_key );
		if ( ! $pkey ) {
			return false;
		}

		$signature = '';
		if ( ! openssl_sign( $signing_input, $signature, $pkey, OPENSSL_ALGO_SHA256 ) ) {
			return false;
		}

		// openssl_sign 對 EC key 回傳 DER 編碼的 ECDSA 簽名，JWT 需要原始 R|S 格式
		$raw_sig = self::der_to_raw_ecdsa( $signature );
		if ( ! $raw_sig ) {
			return false;
		}

		return $signing_input . '.' . self::base64url_encode( $raw_sig );
	}

	private static function der_to_raw_ecdsa( $der ) {
		$offset = 0;
		if ( strlen( $der ) < 2 || ord( $der[ $offset ] ) !== 0x30 ) {
			return false;
		}
		$offset++; // skip SEQUENCE tag

		$len = ord( $der[ $offset++ ] );
		if ( $len & 0x80 ) {
			// multi-byte length
			$offset += $len & 0x7f;
		}

		// Read r
		if ( ord( $der[ $offset++ ] ) !== 0x02 ) {
			return false;
		}
		$r_len = ord( $der[ $offset++ ] );
		$r     = substr( $der, $offset, $r_len );
		$offset += $r_len;

		// Read s
		if ( ord( $der[ $offset++ ] ) !== 0x02 ) {
			return false;
		}
		$s_len = ord( $der[ $offset++ ] );
		$s     = substr( $der, $offset, $s_len );

		// DER 整數可能有前綴 0x00（用於表示正數），JWT 格式不需要，各自填滿 32 bytes (P-256)
		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		$r = str_pad( $r, 32, "\x00", STR_PAD_LEFT );
		$s = str_pad( $s, 32, "\x00", STR_PAD_LEFT );

		return $r . $s;
	}

	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function parse_id_token( $id_token ) {
		$parts = explode( '.', $id_token );
		if ( count( $parts ) !== 3 ) {
			return null;
		}
		$padding      = str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 );
		$payload_json = base64_decode( strtr( $parts[1], '-_', '+/' ) . $padding );
		if ( ! $payload_json ) {
			return null;
		}
		return json_decode( $payload_json, true );
	}

	/* ---------- Helper ---------- */

	private static function find_user_by_apple_id( $apple_id ) {
		return WCLON_OAuth::find_user_by_meta( self::USER_META_KEY, $apple_id );
	}

	private static function create_user_from_apple( $apple_id, $display_name, $apple_email, $first_name = '', $last_name = '' ) {
		return WCLON_OAuth::create_customer(
			$display_name,
			$apple_email,
			'apple_' . strtolower( substr( preg_replace( '/[^a-zA-Z0-9]/', '', $apple_id ), 0, 16 ) ) . '@noemail.invalid',
			'apple_user',
			array(
				// Apple 授權當下有拆好的姓/名可用時直接對應寫入；非首次授權沒有拆分資料時整串塞 billing_first_name。
				'billing_first_name' => '' !== $first_name ? $first_name : $display_name,
				'billing_last_name'  => $last_name,
			)
		);
	}

	/* ---------- 前台綁定按鈕（結帳 / 我的帳號）---------- */

	public static function echo_connect_button() {
		echo self::render_connect_button(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_connect_button() {
		if ( ! WCLON_Settings::get( 'apple_client_id' ) ) {
			return '';
		}

		$apple_id     = '';
		$connected    = false;
		$display_name = '';

		if ( is_user_logged_in() ) {
			$apple_id     = (string) get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
			$connected    = (bool) $apple_id;
			if ( $connected ) {
				$display_name = (string) get_user_meta( get_current_user_id(), self::USER_META_NAME, true );
				if ( ! $display_name ) {
					$display_name = wp_get_current_user()->display_name;
				}
			}
		}

		$current_url = sanitize_text_field( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) );
		$current     = ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' ) ) . $current_url;

		ob_start();
		?>
		<div class="wclon-connect-box">
			<?php if ( $connected ) : ?>
				<div class="wclon-ios-row">
					<span class="wclon-connected-status wclon-connected-status--apple"><?php echo self::apple_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?> 已綁定 Apple<?php echo $display_name ? '（' . esc_html( $display_name ) . '）' : ''; ?></span>
				</div>
				<?php if ( is_user_logged_in() ) : ?>
					<div class="wclon-ios-row wclon-ios-row--action">
						<a class="wclon-unlink-text" href="<?php echo esc_url( wp_nonce_url( home_url( '/?wclon_action=apple_disconnect' ), 'wclon_apple_disconnect' ) ); ?>">解除綁定</a>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<a class="wclon-connect-btn<?php echo esc_attr( WCLON_Settings::get_btn_classes( 'apple' ) ); ?>" href="<?php echo esc_url( home_url( '/?wclon_action=apple_login&intent=link&redirect=' . rawurlencode( $current ) ) ); ?>" aria-label="用 Apple 綁定帳號">
					<?php echo self::apple_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="wclon-btn-label">用 Apple 綁定帳號</span>
				</a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------- WP 登入頁 / WC 登入 / 註冊頁按鈕 ---------- */

	public static function echo_wp_login_button() {
		// login_form 在 wp-login.php 固定位於「密碼欄位之後、登入按鈕之前」（WP 核心版面，無法更動）。
		// 若這個 hook 是被其他主題自訂表單一併觸發（例如 Blocksy 彈出登入視窗，同時也會觸發
		// woocommerce_login_form_end），就交給稍後的 woocommerce_login_form_end 渲染，讓按鈕
		// 出現在登入按鈕「下方」而非「上方」；只有真正的 wp-login.php 頁面（用只有該頁才會觸發
		// 的 login_init 判斷）才在這裡渲染，因為那裡本來就沒有 woocommerce_login_form_end 可用。
		if ( ! did_action( 'login_init' ) ) {
			return;
		}
		echo self::render_apple_auth_button( // phpcs:ignore WordPress.Security.EscapeOutput
			'login',
			wp_login_url(),
			'用 Apple 登入'
		);
	}

	public static function echo_wc_login_button() {
		$redirect = wc_get_account_endpoint_url( 'dashboard' );
		echo self::render_apple_auth_button( 'login', $redirect, '用 Apple 登入' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function echo_wc_register_button() {
		$redirect = wc_get_page_permalink( 'myaccount' );
		echo self::render_apple_auth_button( 'register', $redirect, '用 Apple 快速註冊' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function render_apple_auth_button( $intent, $redirect, $label ) {
		$url = home_url( '/?wclon_action=apple_login&intent=' . rawurlencode( $intent ) . '&redirect=' . rawurlencode( $redirect ) );
		ob_start();
		?>
		<div class="wclon-auth-wrap">
			<a class="wclon-auth-btn<?php echo esc_attr( WCLON_Settings::get_btn_classes( 'apple' ) ); ?>" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php echo self::apple_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="wclon-btn-label"><?php echo esc_html( $label ); ?></span>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------- 精簡 chip（結帳頁 / 帳戶詳細資料頁共用）---------- */

	private static function render_chip( $intent, $redirect, $label = 'Apple' ) {
		if ( ! WCLON_Settings::get( 'apple_client_id' ) ) {
			return '';
		}
		$shape_class = esc_attr( WCLON_Settings::get_shape_class() );
		if ( is_user_logged_in() && get_user_meta( get_current_user_id(), self::USER_META_KEY, true ) ) {
			return '<span class="wclon-chip wclon-chip--done' . $shape_class . '"><span class="wclon-chip__check">✓</span>Apple</span>';
		}
		$url = home_url( '/?wclon_action=apple_login&intent=' . $intent . '&redirect=' . rawurlencode( $redirect ) );
		return '<a class="wclon-chip wclon-chip--apple' . $shape_class . '" href="' . esc_url( $url ) . '">' . self::apple_icon() . esc_html( $label ) . '</a>';
	}

	public static function echo_checkout_chip() {
		echo self::render_checkout_chip(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_checkout_chip() {
		$current_url = sanitize_text_field( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) );
		$current     = ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' ) ) . $current_url;
		return self::render_chip( 'checkout', $current );
	}

	/* ---------- 帳戶詳細資料頁：獨立一行的綁定 / 解除綁定列 ---------- */

	public static function echo_account_row() {
		echo self::render_account_row(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_account_row() {
		if ( ! WCLON_Settings::get( 'apple_client_id' ) ) {
			return '';
		}
		ob_start();
		if ( is_user_logged_in() && get_user_meta( get_current_user_id(), self::USER_META_KEY, true ) ) {
			$display_name = (string) get_user_meta( get_current_user_id(), self::USER_META_NAME, true );
			$unlink_url   = wp_nonce_url( home_url( '/?wclon_action=apple_disconnect' ), 'wclon_apple_disconnect' );
			?>
			<div class="wclon-account-social__row">
				<span class="wclon-connected-status wclon-connected-status--apple"><?php echo self::apple_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?> 已綁定 Apple<?php echo $display_name ? '（' . esc_html( $display_name ) . '）' : ''; ?></span>
				<a class="wclon-unlink-text" href="<?php echo esc_url( $unlink_url ); ?>">解除綁定</a>
			</div>
			<?php
		} else {
			$bind_url = home_url( '/?wclon_action=apple_login&intent=link&redirect=' . rawurlencode( wc_get_account_endpoint_url( WCLON_ACCOUNT_ENDPOINT ) ) );
			?>
			<div class="wclon-account-social__row">
				<span class="wclon-connected-status wclon-connected-status--apple"><?php echo self::apple_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?> Apple</span>
				<a class="wclon-link-text" href="<?php echo esc_url( $bind_url ); ?>">綁定帳號</a>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	private static function apple_icon() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>';
	}

	/* ---------- 後台使用者頁面 ---------- */

	public static function render_user_profile_section( $user ) {
		if (
			isset( $_GET['wclon_clear_apple'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wclon_clear_apple_' . $user->ID ) &&
			current_user_can( 'edit_user', $user->ID )
		) {
			delete_user_meta( $user->ID, self::USER_META_KEY );
			delete_user_meta( $user->ID, self::USER_META_NAME );
		}

		$apple_id     = (string) get_user_meta( $user->ID, self::USER_META_KEY, true );
		$display_name = (string) get_user_meta( $user->ID, self::USER_META_NAME, true );
		?>
		<h2>Apple ID 帳號綁定</h2>
		<table class="form-table">
			<tr>
				<th>綁定狀態</th>
				<td>
					<?php if ( $apple_id ) : ?>
						<span style="color:#00b300;font-weight:bold;">✓ 已綁定</span>
						<?php if ( $display_name ) : ?>
							<span style="margin-left:8px;color:#555;">Apple 名稱：<strong><?php echo esc_html( $display_name ); ?></strong></span>
						<?php endif; ?>
						<br><span style="font-size:12px;color:#999;margin-top:4px;display:inline-block;">Apple ID：<?php echo esc_html( $apple_id ); ?></span>
					<?php else : ?>
						<span style="color:#999;">尚未綁定</span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $apple_id ) : ?>
			<tr>
				<th>清除綁定</th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'user-edit.php?user_id=' . $user->ID . '&wclon_clear_apple=1' ), 'wclon_clear_apple_' . $user->ID ) ); ?>"
					   onclick="return confirm('確定要清除此帳號的 Apple ID 綁定嗎？');"
					   class="button button-secondary">清除 Apple ID 綁定</a>
					<p class="description">清除後，顧客可自行在前台重新綁定。</p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}
}
