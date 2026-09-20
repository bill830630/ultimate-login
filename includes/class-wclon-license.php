<?php
/** Cloudflare Workers 授權客戶端。 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCLON_License {
	const API_URL    = 'https://nibill-license-api.bill830630.workers.dev';
	const PRODUCT_ID = 'ultimate-login';
	const OPTION_KEY = 'wclon_license';
	const CACHE_TTL  = DAY_IN_SECONDS;
	const GRACE_TTL  = 14 * DAY_IN_SECONDS;

	public static function init() {
		add_action( 'admin_post_wclon_license_activate', array( __CLASS__, 'handle_activate' ) );
		add_action( 'admin_post_wclon_license_deactivate', array( __CLASS__, 'handle_deactivate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function data() {
		$data = get_option( self::OPTION_KEY, array() );
		$data = is_array( $data ) ? $data : array();
		if ( empty( $data['instance_id'] ) ) {
			$data['instance_id'] = wp_generate_uuid4();
			update_option( self::OPTION_KEY, $data, false );
		}
		return $data;
	}

	private static function update( array $changes ) {
		$data = array_merge( self::data(), $changes );
		update_option( self::OPTION_KEY, $data, false );
		return $data;
	}

	private static function payload( array $data ) {
		return array(
			'license_key' => (string) ( $data['license_key'] ?? '' ),
			'product_id'  => self::PRODUCT_ID,
			'instance_id' => (string) ( $data['instance_id'] ?? '' ),
			'site_url'    => home_url( '/' ),
		);
	}

	private static function request( $endpoint, array $body ) {
		$response = wp_remote_post(
			self::API_URL . $endpoint,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) return $response;
		$status  = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status >= 500 ) return new WP_Error( 'license_server_error', '授權伺服器暫時無法使用。' );
		return is_array( $decoded ) ? $decoded : new WP_Error( 'invalid_response', '授權伺服器回應格式不正確。' );
	}

	private static function error_message( $code ) {
		$messages = array(
			'license_not_found'        => '找不到授權金鑰。',
			'activation_not_found'     => '這個網站尚未啟用授權。',
			'activation_limit_reached' => '授權可啟用的網站數量已達上限。',
			'license_expired'          => '授權已到期。',
			'license_inactive'         => '授權已停用。',
			'license_not_active'       => '授權已停用。',
			'missing_fields'           => '授權資料不完整。',
			'invalid_site_url'         => '網站網址格式不正確。',
		);
		return $messages[ $code ] ?? '授權驗證失敗，請確認金鑰後再試一次。';
	}

	private static function store_response( array $response, $license_key = null ) {
		$now   = time();
		$valid = ! empty( $response['valid'] );
		$error = sanitize_key( $response['error'] ?? '' );
		$data  = array(
			'status'         => $valid ? 'active' : ( $error ?: sanitize_key( $response['status'] ?? 'invalid' ) ),
			'last_checked'   => $now,
			'expires_at'     => sanitize_text_field( $response['expires_at'] ?? '' ),
			'latest_version' => sanitize_text_field( $response['latest_version'] ?? '' ),
			'error'          => $valid ? '' : self::error_message( $error ),
		);
		if ( $valid ) $data['last_success'] = $now;
		if ( null !== $license_key ) $data['license_key'] = sanitize_text_field( $license_key );
		return self::update( $data );
	}

	public static function is_active( $force = false ) {
		$data = self::data();
		if ( empty( $data['license_key'] ) ) return false;
		$now = time();
		if ( ! $force && ! empty( $data['last_checked'] ) && ( $now - (int) $data['last_checked'] ) < self::CACHE_TTL ) {
			return in_array( $data['status'] ?? '', array( 'active', 'grace' ), true );
		}
		$lock = 'wclon_license_check_lock';
		if ( ! $force && get_transient( $lock ) ) {
			return ! empty( $data['last_success'] ) && ( $now - (int) $data['last_success'] ) < self::GRACE_TTL;
		}
		set_transient( $lock, '1', MINUTE_IN_SECONDS );
		$response = self::request( '/v1/licenses/validate', self::payload( $data ) );
		delete_transient( $lock );
		if ( is_wp_error( $response ) ) {
			$grace = ! empty( $data['last_success'] ) && ( $now - (int) $data['last_success'] ) < self::GRACE_TTL;
			self::update( array(
				'status'       => $grace ? 'grace' : 'unreachable',
				'last_checked' => $now,
				'error'        => $grace ? '授權伺服器暫時無法連線，目前使用離線寬限期。' : '無法連線授權伺服器。',
			) );
			return $grace;
		}
		$data = self::store_response( $response );
		return 'active' === ( $data['status'] ?? '' );
	}

	private static function redirect( $result ) {
		wp_safe_redirect( admin_url( 'admin.php?page=wclon-settings&license_result=' . rawurlencode( $result ) . '#license' ) );
		exit;
	}

	public static function handle_activate() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );
		check_admin_referer( 'wclon_license_activate' );
		$key = strtoupper( sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) ) );
		if ( '' === $key ) self::redirect( 'missing' );
		$data     = self::update( array( 'license_key' => $key ) );
		$response = self::request( '/v1/licenses/activate', self::payload( $data ) );
		if ( is_wp_error( $response ) ) {
			self::update( array( 'status' => 'unreachable', 'last_checked' => time(), 'error' => '無法連線授權伺服器。' ) );
			self::redirect( 'error' );
		}
		$stored = self::store_response( $response, $key );
		self::redirect( 'active' === ( $stored['status'] ?? '' ) ? 'activated' : 'invalid' );
	}

	public static function handle_deactivate() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );
		check_admin_referer( 'wclon_license_deactivate' );
		$data = self::data();
		if ( ! empty( $data['license_key'] ) ) {
			$response = self::request( '/v1/licenses/deactivate', self::payload( $data ) );
			if ( is_wp_error( $response ) ) self::redirect( 'error' );
		}
		self::update( array( 'license_key' => '', 'status' => 'inactive', 'last_checked' => time(), 'last_success' => 0, 'error' => '' ) );
		self::redirect( 'deactivated' );
	}

	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || self::is_active() ) return;
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( in_array( $page, array( 'wc-general-settings', 'wclon-settings' ), true ) ) return;
		echo '<div class="notice notice-error"><p><strong>終極登入尚未啟用授權。</strong> 外掛功能目前停用，請前往 <a href="' . esc_url( admin_url( 'admin.php?page=wclon-settings#license' ) ) . '">授權設定</a> 輸入有效金鑰。</p></div>';
	}

	public static function render_inline_notice() {
		if ( ! current_user_can( 'manage_options' ) || self::is_active() ) return;
		echo '<div id="wclon-license-inline-notice" class="notice notice-error inline"><p><strong>尚未啟用授權。</strong> 外掛功能目前停用，請前往 <a href="' . esc_url( admin_url( 'admin.php?page=wclon-settings#license' ) ) . '">授權設定</a> 輸入有效金鑰。</p></div>';
	}

	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$data   = self::data();
		$active = self::is_active();
		$status = $active ? ( 'grace' === ( $data['status'] ?? '' ) ? '離線寬限中' : '已啟用' ) : '未啟用';
		$result = sanitize_key( $_GET['license_result'] ?? '' );
		$messages = array(
			'activated'   => array( 'success', '授權已啟用。' ),
			'deactivated' => array( 'success', '授權已解除。' ),
			'missing'     => array( 'error', '請輸入授權金鑰。' ),
			'invalid'     => array( 'error', $data['error'] ?? '授權驗證失敗。' ),
			'error'       => array( 'error', '無法連線授權伺服器，請稍後再試。' ),
		);
		?>
		<?php if ( isset( $messages[ $result ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $messages[ $result ][0] ); ?> inline"><p><?php echo esc_html( $messages[ $result ][1] ); ?></p></div>
		<?php endif; ?>
		<div class="wclon-card">
			<h2 class="wclon-card__title">外掛授權</h2>
			<p class="wclon-card__desc">授權綁定目前網站；驗證成功後會快取 24 小時，服務暫時中斷時保留 14 天離線寬限。</p>
			<table class="form-table"><tr><th scope="row">授權狀態</th><td><strong><?php echo esc_html( $status ); ?></strong>
			<?php if ( ! empty( $data['expires_at'] ) ) : ?><p class="description">到期時間：<?php echo esc_html( $data['expires_at'] ); ?></p><?php endif; ?>
			<?php if ( ! empty( $data['error'] ) ) : ?><p class="description" style="color:#b32d2e;"><?php echo esc_html( $data['error'] ); ?></p><?php endif; ?>
			</td></tr></table>
			<?php if ( $active ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wclon_license_deactivate"><?php wp_nonce_field( 'wclon_license_deactivate' ); ?>
				<?php submit_button( '解除授權', 'secondary', 'submit', false ); ?>
			</form>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wclon_license_activate"><?php wp_nonce_field( 'wclon_license_activate' ); ?>
				<p><label for="wclon_license_key"><strong>授權金鑰</strong></label></p>
				<input id="wclon_license_key" name="license_key" type="text" class="regular-text" autocomplete="off" placeholder="NIBILL-XXXXX-XXXXX-XXXXX-XXXXX" required>
				<?php submit_button( '啟用授權', 'primary', 'submit', false ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
