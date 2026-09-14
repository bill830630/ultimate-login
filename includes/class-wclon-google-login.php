<?php
/**
 * Google Login：讓顧客透過 Google 授權並快速登入 / 綁定帳號
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Google_Login {

	const USER_META_KEY   = '_wclon_google_user_id';
	const USER_META_NAME  = '_wclon_google_display_name';
	const STATE_TRANSIENT = 'wclon_gstate_';


	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_request' ) );
		add_shortcode( 'wclon_google_connect', array( __CLASS__, 'render_connect_button' ) );

		// v1.30.0 起結帳頁 chip 改由 WCLON_Settings::render_social_bar() 直接呼叫，不再各自掛 hook
		if ( WCLON_Settings::get( 'show_on_myaccount', 1 ) ) {
			add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', array( __CLASS__, 'echo_account_row' ), 11 );
		}

		if ( WCLON_Settings::get( 'google_client_id' ) ) {
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

		if ( 'google_login' === $action ) {
			self::redirect_to_google();
		} elseif ( 'google_callback' === $action ) {
			self::handle_callback();
		} elseif ( 'google_disconnect' === $action ) {
			self::handle_disconnect();
		}
	}

	private static function redirect_to_google() {
		$client_id = WCLON_Settings::get( 'google_client_id' );
		if ( ! $client_id ) {
			wp_die( '尚未設定 Google Client ID。' );
		}

		$state      = wp_generate_password( 24, false );
		$redirect   = ! empty( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : wc_get_checkout_url();
		$raw_intent = sanitize_text_field( wp_unslash( $_GET['intent'] ?? 'link' ) );
		$intent     = in_array( $raw_intent, array( 'link', 'login', 'register', 'checkout' ), true ) ? $raw_intent : 'link';

		set_transient( self::STATE_TRANSIENT . $state, array(
			'redirect' => $redirect,
			'intent'   => $intent,
		), 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => home_url( '/?wclon_action=google_callback' ),
			'response_type' => 'code',
			'scope'         => 'openid email profile',
			'state'         => $state,
			'access_type'   => 'online',
		);

		wp_redirect( 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params ) );
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
			wp_die( '驗證逾時，請重新操作 Google 登入。' );
		}
		delete_transient( self::STATE_TRANSIENT . $state );

		$redirect = $data['redirect'] ?? home_url();
		$intent   = $data['intent'] ?? 'link';

		// 向 Google 換取 access token
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 15,
			'body'    => array(
				'code'          => $code,
				'client_id'     => WCLON_Settings::get( 'google_client_id' ),
				'client_secret' => WCLON_Settings::get( 'google_client_secret' ),
				'redirect_uri'  => home_url( '/?wclon_action=google_callback' ),
				'grant_type'    => 'authorization_code',
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_die( 'Google 連線失敗：' . esc_html( $response->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			wp_die( 'Google 授權失敗，請確認 Client ID / Secret 與 Callback URI 設定是否正確。' );
		}

		// 取得 Google 使用者資料
		$userinfo_res = wp_remote_get( 'https://www.googleapis.com/oauth2/v3/userinfo', array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ),
		) );

		if ( is_wp_error( $userinfo_res ) ) {
			wp_die( '取得 Google 使用者資料失敗。' );
		}

		$userinfo = json_decode( wp_remote_retrieve_body( $userinfo_res ), true );
		if ( empty( $userinfo['sub'] ) ) {
			wp_die( '無法取得 Google 使用者 ID。' );
		}

		$google_id             = sanitize_text_field( $userinfo['sub'] );
		$google_email          = sanitize_email( $userinfo['email'] ?? '' );
		$google_email_verified = ! empty( $userinfo['email_verified'] );
		$display_name          = sanitize_text_field( $userinfo['name'] ?? '' );

		// ── 依 intent 決定行為 ─────────────────────────────────────────────────

		if ( 'login' === $intent || 'register' === $intent || 'checkout' === $intent ) {
			// 先以 Google ID 查找，找不到再以「已驗證」的 email 查找
			// 未驗證的 email 不可信任，若以此比對既有帳號會有帳號接管風險
			$wp_user_id = self::find_user_by_google_id( $google_id );

			if ( ! $wp_user_id && $google_email && $google_email_verified ) {
				$user = get_user_by( 'email', $google_email );
				if ( $user ) {
					$wp_user_id = $user->ID;
				}
			}

			if ( $wp_user_id ) {
				// 找到對應帳號 → 確保 Google ID 已綁定後登入
				update_user_meta( $wp_user_id, self::USER_META_KEY, $google_id );
				update_user_meta( $wp_user_id, self::USER_META_NAME, $display_name );
				wp_set_current_user( $wp_user_id );
				wp_set_auth_cookie( $wp_user_id, true );
				$user_data = get_userdata( $wp_user_id );
				do_action( 'wp_login', $user_data->user_login, $user_data );
				wp_safe_redirect( $redirect );
				exit;
			}

			// 已登入（帳號尚未綁定 Google）→ 直接綁定
			if ( is_user_logged_in() ) {
				if ( WCLON_Settings::email_conflicts( $google_email, get_current_user_id() ) ) {
					wc_add_notice( '此 Google 帳號的 Email 與您目前帳號的 Email 不符，無法綁定。', 'error' );
					wp_safe_redirect( $redirect );
					exit;
				}
				update_user_meta( get_current_user_id(), self::USER_META_KEY, $google_id );
				update_user_meta( get_current_user_id(), self::USER_META_NAME, $display_name );
				wp_safe_redirect( add_query_arg( 'wclon_google_connected', '1', $redirect ) );
				exit;
			}

			// 未登入且無對應帳號 → 自動建立帳號
			$new_user_id = self::create_user_from_google( $google_id, $display_name, $google_email );
			if ( is_wp_error( $new_user_id ) ) {
				wp_die( 'Google 帳號建立失敗：' . esc_html( $new_user_id->get_error_message() ) );
			}

			update_user_meta( $new_user_id, self::USER_META_KEY, $google_id );
			update_user_meta( $new_user_id, self::USER_META_NAME, $display_name );
			wp_set_current_user( $new_user_id );
			wp_set_auth_cookie( $new_user_id, true );
			$user_data = get_userdata( $new_user_id );
			do_action( 'wp_login', $user_data->user_login, $user_data );

			if ( 'checkout' === $intent ) {
				// 結帳頁 chip 註冊：完成後留在結帳頁
				wp_safe_redirect( add_query_arg( 'wclon_google_connected', '1', $redirect ) );
			} else {
				wp_safe_redirect( wc_get_account_endpoint_url( 'dashboard' ) );
			}
			exit;
		}

		// ── intent = link（預設）：綁定到目前帳號 ─────────────────────────────

		if ( is_user_logged_in() ) {
			$existing_user_id = self::find_user_by_google_id( $google_id );
			if ( $existing_user_id && $existing_user_id !== get_current_user_id() ) {
				wc_add_notice( '此 Google 帳號已綁定其他會員，請先於該會員帳號解除綁定後再試一次。', 'error' );
				wp_safe_redirect( $redirect );
				exit;
			}
			if ( WCLON_Settings::email_conflicts( $google_email, get_current_user_id() ) ) {
				wc_add_notice( '此 Google 帳號的 Email 與您目前帳號的 Email 不符，無法綁定。', 'error' );
				wp_safe_redirect( $redirect );
				exit;
			}
			update_user_meta( get_current_user_id(), self::USER_META_KEY, $google_id );
			update_user_meta( get_current_user_id(), self::USER_META_NAME, $display_name );
		}

		wp_safe_redirect( add_query_arg( 'wclon_google_connected', '1', $redirect ) );
		exit;
	}

	private static function handle_disconnect() {
		if (
			is_user_logged_in() &&
			isset( $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wclon_google_disconnect' )
		) {
			delete_user_meta( get_current_user_id(), self::USER_META_KEY );
			delete_user_meta( get_current_user_id(), self::USER_META_NAME );
		}
		wp_safe_redirect( wp_get_referer() ?: home_url() );
		exit;
	}

	/* ---------- Helper ---------- */

	private static function find_user_by_google_id( $google_id ) {
		$users = get_users( array(
			'meta_key'   => self::USER_META_KEY,
			'meta_value' => $google_id,
			'number'     => 1,
			'fields'     => 'ids',
		) );
		return ! empty( $users ) ? (int) $users[0] : 0;
	}

	private static function create_user_from_google( $google_id, $display_name, $google_email ) {
		$base = sanitize_user( remove_accents( $display_name ), true );
		if ( empty( $base ) ) {
			$base = 'google_user';
		}
		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . '_' . $i++;
		}

		if ( $google_email && is_email( $google_email ) && ! email_exists( $google_email ) ) {
			$email = $google_email;
		} else {
			$email = 'google_' . strtolower( substr( $google_id, 0, 12 ) ) . '@noemail.invalid';
		}

		return wp_insert_user( array(
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
	}

	/* ---------- 前台綁定按鈕（結帳 / 我的帳號）---------- */

	public static function echo_connect_button() {
		echo self::render_connect_button(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_connect_button() {
		if ( ! WCLON_Settings::get( 'google_client_id' ) ) {
			return '';
		}

		$google_id    = '';
		$connected    = false;
		$display_name = '';

		if ( is_user_logged_in() ) {
			$google_id    = (string) get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
			$connected    = (bool) $google_id;
			if ( $connected ) {
				$display_name = (string) get_user_meta( get_current_user_id(), self::USER_META_NAME, true );
			}
		}

		$current_url = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$current     = ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . $current_url;

		ob_start();
		?>
		<div class="wclon-connect-box">
			<?php if ( $connected ) :
				$g_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>';
			?>
				<div class="wclon-ios-row">
					<span class="wclon-connected-status wclon-connected-status--google"><?php echo $g_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> 已綁定 Google<?php echo $display_name ? '（' . esc_html( $display_name ) . '）' : ''; ?></span>
				</div>
				<?php if ( is_user_logged_in() ) : ?>
					<div class="wclon-ios-row wclon-ios-row--action">
						<a class="wclon-unlink-text" href="<?php echo esc_url( wp_nonce_url( home_url( '/?wclon_action=google_disconnect' ), 'wclon_google_disconnect' ) ); ?>">解除綁定</a>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<a class="wclon-connect-btn<?php echo esc_attr( WCLON_Settings::get_btn_classes( 'google' ) ); ?>" href="<?php echo esc_url( home_url( '/?wclon_action=google_login&intent=link&redirect=' . rawurlencode( $current ) ) ); ?>" aria-label="用 Google 綁定帳號">
					<?php echo self::google_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="wclon-btn-label">用 Google 綁定帳號</span>
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
		echo self::render_google_auth_button( // phpcs:ignore WordPress.Security.EscapeOutput
			'login',
			wp_login_url(),
			'用 Google 登入'
		);
	}

	public static function echo_wc_login_button() {
		$redirect = wc_get_account_endpoint_url( 'dashboard' );
		echo self::render_google_auth_button( 'login', $redirect, '用 Google 登入' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function echo_wc_register_button() {
		$redirect = wc_get_page_permalink( 'myaccount' );
		echo self::render_google_auth_button( 'register', $redirect, '用 Google 快速註冊' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function render_google_auth_button( $intent, $redirect, $label ) {
		$url = home_url( '/?wclon_action=google_login&intent=' . rawurlencode( $intent ) . '&redirect=' . rawurlencode( $redirect ) );
		ob_start();
		?>
		<div class="wclon-auth-wrap">
			<a class="wclon-auth-btn<?php echo esc_attr( WCLON_Settings::get_btn_classes( 'google' ) ); ?>" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php echo self::google_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="wclon-btn-label"><?php echo esc_html( $label ); ?></span>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------- 精簡 chip（結帳頁 / 帳戶詳細資料頁共用）---------- */

	private static function render_chip( $intent, $redirect, $label = 'Google' ) {
		if ( ! WCLON_Settings::get( 'google_client_id' ) ) {
			return '';
		}
		$shape_class = esc_attr( WCLON_Settings::get_shape_class() );
		if ( is_user_logged_in() && get_user_meta( get_current_user_id(), self::USER_META_KEY, true ) ) {
			return '<span class="wclon-chip wclon-chip--done' . $shape_class . '"><span class="wclon-chip__check">✓</span>Google</span>';
		}
		$url = home_url( '/?wclon_action=google_login&intent=' . $intent . '&redirect=' . rawurlencode( $redirect ) );
		return '<a class="wclon-chip wclon-chip--google' . $shape_class . '" href="' . esc_url( $url ) . '">' . self::google_icon() . esc_html( $label ) . '</a>';
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
		if ( ! WCLON_Settings::get( 'google_client_id' ) ) {
			return '';
		}
		ob_start();
		if ( is_user_logged_in() && get_user_meta( get_current_user_id(), self::USER_META_KEY, true ) ) {
			$display_name = (string) get_user_meta( get_current_user_id(), self::USER_META_NAME, true );
			$unlink_url   = wp_nonce_url( home_url( '/?wclon_action=google_disconnect' ), 'wclon_google_disconnect' );
			?>
			<div class="wclon-account-social__row">
				<span class="wclon-connected-status wclon-connected-status--google"><?php echo self::google_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?> 已綁定 Google<?php echo $display_name ? '（' . esc_html( $display_name ) . '）' : ''; ?></span>
				<a class="wclon-unlink-text" href="<?php echo esc_url( $unlink_url ); ?>">解除綁定</a>
			</div>
			<?php
		} else {
			$bind_url = home_url( '/?wclon_action=google_login&intent=link&redirect=' . rawurlencode( wc_get_account_endpoint_url( WCLON_ACCOUNT_ENDPOINT ) ) );
			?>
			<div class="wclon-account-social__row">
				<span class="wclon-connected-status wclon-connected-status--google"><?php echo self::google_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?> Google</span>
				<a class="wclon-link-text" href="<?php echo esc_url( $bind_url ); ?>">綁定帳號</a>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	private static function google_icon() {
		// 配色固定官方白底標準，一律使用彩色 G icon
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>';
	}

	/* ---------- 後台使用者頁面 ---------- */

	public static function render_user_profile_section( $user ) {
		if (
			isset( $_GET['wclon_clear_google'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wclon_clear_google_' . $user->ID ) &&
			current_user_can( 'edit_user', $user->ID )
		) {
			delete_user_meta( $user->ID, self::USER_META_KEY );
			delete_user_meta( $user->ID, self::USER_META_NAME );
		}

		$google_id    = (string) get_user_meta( $user->ID, self::USER_META_KEY, true );
		$display_name = (string) get_user_meta( $user->ID, self::USER_META_NAME, true );
		?>
		<h2>Google 帳號綁定</h2>
		<table class="form-table">
			<tr>
				<th>綁定狀態</th>
				<td>
					<?php if ( $google_id ) : ?>
						<span style="color:#00b300;font-weight:bold;">✓ 已綁定</span>
						<?php if ( $display_name ) : ?>
							<span style="margin-left:8px;color:#555;">Google 名稱：<strong><?php echo esc_html( $display_name ); ?></strong></span>
						<?php endif; ?>
						<br><span style="font-size:12px;color:#999;margin-top:4px;display:inline-block;">Google ID：<?php echo esc_html( $google_id ); ?></span>
					<?php else : ?>
						<span style="color:#999;">尚未綁定</span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $google_id ) : ?>
			<tr>
				<th>清除綁定</th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'user-edit.php?user_id=' . $user->ID . '&wclon_clear_google=1' ), 'wclon_clear_google_' . $user->ID ) ); ?>"
					   onclick="return confirm('確定要清除此帳號的 Google 綁定嗎？');"
					   class="button button-secondary">清除 Google 綁定</a>
					<p class="description">清除後，顧客可自行在前台重新綁定。</p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}
}
