<?php
/**
 * 懸浮 LINE 聊天按鈕（v1.44.0 新增）
 *
 * 前台每個頁面左／右下角固定一顆 LINE 綠圓形按鈕，點擊：未登入且 LINE 登入已就緒→LINE 登入；否則開啟官方帳號聊天。
 * 跨平台的全域設定放在「一般設定」頁籤（wclon_settings: line_float_enabled / line_float_url）。
 * 配色固定 LINE 官方綠；位置可選左／右下角（line_float_position）；不跟 btn_shape（那是登入按鈕的形狀設定）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Line_Float {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	/**
	 * 把設定值正規化成 LINE 聊天連結；不合法回空字串。
	 * 接受：@basicId／basicId（轉成 line.me/R/ti/p/@xxx），或 line.me／lin.ee 網域的 https 連結。
	 */
	public static function normalize_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^@?[A-Za-z0-9._-]{3,}$/', $raw ) ) {
			return 'https://line.me/R/ti/p/@' . ltrim( $raw, '@' );
		}
		$url  = esc_url_raw( $raw, array( 'https' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $url || ! preg_match( '/(^|\.)(line\.me|lin\.ee)$/', $host ) ) {
			return '';
		}
		return $url;
	}

	public static function url() {
		return self::normalize_url( WCLON_Settings::get( 'line_float_url', '' ) );
	}

	public static function position() {
		return 'left' === WCLON_Settings::get( 'line_float_position', 'right' ) ? 'left' : 'right';
	}

	/**
	 * 未登入且 LINE 登入已就緒：按鈕改成「用 LINE 登入」，登入後回到目前頁面；
	 * 其餘情況（已登入、或沒設定 LINE 登入）開啟官方帳號聊天。
	 */
	private static function target() {
		if ( ! is_user_logged_in() && WCLON_Verify::ready( 'line' ) ) {
			$current = set_url_scheme( home_url( add_query_arg( null, null ) ) );
			return array(
				'url'   => home_url( '/?wclon_action=login&intent=login&redirect=' . rawurlencode( $current ) ),
				'label' => '使用 LINE 登入',
				'blank' => false,
			);
		}
		$chat = self::url();
		if ( '' === $chat ) {
			return null;
		}
		return array( 'url' => $chat, 'label' => '使用 LINE 聊天', 'blank' => true );
	}

	public static function active() {
		return (bool) WCLON_Settings::get( 'line_float_enabled', 0 );
	}

	public static function enqueue() {
		if ( self::active() ) {
			wp_enqueue_style( 'wclon-frontend', WCLON_PLUGIN_URL . 'assets/css/wclon-frontend.css', array(), WCLON_VERSION );
		}
	}

	public static function render() {
		$target = self::active() ? self::target() : null;
		if ( ! $target ) {
			return;
		}
		?>
		<a class="wclon-line-float wclon-line-float--<?php echo esc_attr( self::position() ); ?>" href="<?php echo esc_url( $target['url'] ); ?>"<?php echo $target['blank'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> aria-label="<?php echo esc_attr( $target['label'] ); ?>">
			<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2C6.48 2 2 5.75 2 10.36c0 4.13 3.56 7.6 8.37 8.26.33.07.77.22.88.5.1.25.07.65.03.9l-.14.85c-.04.25-.2.99.87.54 1.07-.45 5.76-3.39 7.86-5.8C21.3 13.9 22 12.2 22 10.36 22 5.75 17.52 2 12 2zM8.2 12.9H6.1a.5.5 0 0 1-.5-.5V8.5a.5.5 0 0 1 1 0v3.4h1.6a.5.5 0 0 1 0 1zm2.5-.5a.5.5 0 0 1-1 0V8.5a.5.5 0 0 1 1 0v3.9zm5 0a.5.5 0 0 1-.9.3l-2.1-2.85v2.55a.5.5 0 0 1-1 0V8.5a.5.5 0 0 1 .9-.3l2.1 2.85V8.5a.5.5 0 0 1 1 0v3.9zm3.1-2.3a.5.5 0 0 1 0 1h-1.6v.8h1.6a.5.5 0 0 1 0 1h-2.1a.5.5 0 0 1-.5-.5V8.5a.5.5 0 0 1 .5-.5h2.1a.5.5 0 0 1 0 1h-1.6v1.1h1.6z"/></svg>
		</a>
		<?php
	}
}
