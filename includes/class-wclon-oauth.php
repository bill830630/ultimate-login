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
	const INTENTS       = array( 'link', 'login', 'register', 'checkout' );

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
		return array(
			'redirect' => $data['redirect'] ?? home_url(),
			'intent'   => $data['intent'] ?? 'link',
		);
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
