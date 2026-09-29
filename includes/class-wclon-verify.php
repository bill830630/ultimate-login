<?php
/**
 * 憑證驗證綁定（v1.43.0 新增）：LINE／Google／Apple 登入與 Turnstile 的金鑰要實際驗證通過才生效。
 *
 * 驗證前這幾項只要欄位有填就直接上線，打錯一個字元的後果是：
 *   - 社交登入：前台按鈕照常出現，顧客點下去才在 LINE／Google／Apple 的錯誤頁卡住。
 *   - Turnstile：Secret Key 錯 → siteverify 永遠失敗 → 登入表單全部擋下，管理員也會被鎖在
 *     wp-login.php 外面；Site Key 錯或網域不在允許清單 → 前台 widget 顯示錯誤、表單送不出去。
 *
 * 做法：驗證通過時記下「當時那組憑證 + 網站網址（home 設定）」的 SHA-256 指紋。執行期比對目前設定算出的指紋，
 * 相同才算已驗證；任何一個欄位被改過（或網站換網域、http 換 https——社交登入的 callback URL 和
 * Turnstile 的允許網域都跟著變）就自動變回未驗證，對應功能暫停，直到重新驗證。
 *
 * 指紋不加鹽（不用 wp_salt()）是刻意的：有些資安外掛會定期輪替 salt，加了鹽的指紋會在輪替後
 * 全部對不上，所有社交按鈕一夜消失。被雜湊的內容本身含高熵的 secret，不加鹽也無法反推。
 *
 * 驗證方式：
 *   - LINE／Google／Apple：管理員實際走一次 OAuth（intent=verify），callback 換 token、取得使用者 ID
 *     成功就算通過，**不會**建立帳號、登入或綁定。見 WCLON_OAuth 的 verify 相關方法。
 *   - Turnstile：設定頁用已儲存的 Site Key 渲染一個真的 widget，管理員完成挑戰後把 token 連同
 *     Secret Key 送 siteverify（ajax_verify_turnstile()）。
 *
 * 紀錄存在獨立 option `wclon_verification`，不跟任何設定表單共用，儲存設定時不會被 sanitize() 動到。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Verify {

	const OPTION_KEY   = 'wclon_verification';
	const NONCE_ACTION = 'wclon_verify';

	private static $cache = null;

	public static function init() {
		// 升級前就已經在用的站台：第一次載入新版時把目前填好的憑證視為已驗證，不讓前台功能在
		// 升級後突然消失。必須在前台判斷 ready() 之前完成，所以放在 plugins_loaded（由主檔案呼叫），
		// 不能等 admin_init——升級後第一個前台訪客就會看不到按鈕。
		self::maybe_seed_existing();

		add_action( 'wp_ajax_wclon_verify_turnstile', array( __CLASS__, 'ajax_verify_turnstile' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
	}

	/**
	 * @return array<string, array{label:string, tab:string, action?:string}>
	 */
	public static function providers() {
		return array(
			'line'      => array( 'label' => 'LINE 登入', 'tab' => 'line', 'action' => 'login' ),
			'google'    => array( 'label' => 'Google 登入', 'tab' => 'google', 'action' => 'google_login' ),
			'apple'     => array( 'label' => 'Apple 登入', 'tab' => 'apple', 'action' => 'apple_login' ),
			'turnstile' => array( 'label' => 'Turnstile', 'tab' => 'turnstile' ),
		);
	}

	/**
	 * 參與指紋的欄位（設定 key => 目前的值）。每一個都必填，缺一個就算「尚未填寫」。
	 */
	private static function credentials( $provider ) {
		switch ( $provider ) {
			case 'line':
				$keys = array( 'login_channel_id', 'login_channel_secret' );
				break;
			case 'google':
				$keys = array( 'google_client_id', 'google_client_secret' );
				break;
			case 'apple':
				$keys = array( 'apple_client_id', 'apple_team_id', 'apple_key_id', 'apple_private_key' );
				break;
			case 'turnstile':
				return array(
					'site_key'   => (string) WCLON_Turnstile::get( 'site_key' ),
					'secret_key' => (string) WCLON_Turnstile::get( 'secret_key' ),
				);
			default:
				return array();
		}
		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = (string) WCLON_Settings::get( $key );
		}
		return $values;
	}

	/** 設定頁 JS 用來判斷「欄位改了還沒存」的欄位名稱 */
	public static function field_keys( $provider ) {
		return array_keys( self::credentials( $provider ) );
	}

	public static function has_credentials( $provider ) {
		$values = self::credentials( $provider );
		if ( ! $values ) {
			return false;
		}
		foreach ( $values as $value ) {
			if ( '' === trim( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 網址用 get_option( 'home' ) 而不是 home_url()：多語系外掛（WPML、Polylang）會依語言改寫
	 * home_url()，同一組憑證在不同語言的頁面算出不同指紋，會變成「中文頁有按鈕、英文頁沒有」。
	 */
	private static function fingerprint( $provider ) {
		return hash( 'sha256', wp_json_encode( array( $provider, untrailingslashit( (string) get_option( 'home' ) ), self::credentials( $provider ) ) ) );
	}

	private static function records() {
		if ( null === self::$cache ) {
			$records     = get_option( self::OPTION_KEY, array() );
			self::$cache = is_array( $records ) ? $records : array();
		}
		return self::$cache;
	}

	public static function get_record( $provider ) {
		$records = self::records();
		return isset( $records[ $provider ] ) && is_array( $records[ $provider ] ) ? $records[ $provider ] : array();
	}

	private static function save_record( $provider, array $record ) {
		$records              = self::records();
		$records[ $provider ] = $record;
		update_option( self::OPTION_KEY, $records, true );
		self::$cache = $records;
	}

	public static function is_verified( $provider ) {
		$record = self::get_record( $provider );
		return ! empty( $record['fp'] ) && hash_equals( $record['fp'], self::fingerprint( $provider ) );
	}

	/** 憑證填齊且驗證通過：前台功能只認這個 */
	public static function ready( $provider ) {
		return self::has_credentials( $provider ) && self::is_verified( $provider );
	}

	/**
	 * @return string empty（沒填齊）｜verified｜changed（驗證過但憑證或網址已變）｜unverified
	 */
	public static function status( $provider ) {
		if ( ! self::has_credentials( $provider ) ) {
			return 'empty';
		}
		if ( self::is_verified( $provider ) ) {
			return 'verified';
		}
		return empty( self::get_record( $provider )['fp'] ) ? 'unverified' : 'changed';
	}

	public static function record_success( $provider, array $details = array() ) {
		self::save_record( $provider, array(
			'fp'      => self::fingerprint( $provider ),
			'time'    => time(),
			'user'    => get_current_user_id(),
			'details' => $details,
		) );
	}

	/** 失敗不動既有的驗證紀錄（例如管理員只是在授權頁按了取消），只附上最近一次的錯誤 */
	public static function record_failure( $provider, $message ) {
		$record               = self::get_record( $provider );
		$record['error']      = (string) $message;
		$record['error_time'] = time();
		self::save_record( $provider, $record );
	}

	private static function maybe_seed_existing() {
		if ( false !== get_option( self::OPTION_KEY ) ) {
			return;
		}
		$records = array();
		foreach ( array_keys( self::providers() ) as $provider ) {
			if ( self::has_credentials( $provider ) ) {
				$records[ $provider ] = array(
					'fp'      => self::fingerprint( $provider ),
					'time'    => time(),
					'user'    => 0,
					'details' => array( 'seeded' => true ),
				);
			}
		}
		add_option( self::OPTION_KEY, $records, '', true );
		self::$cache = $records;
	}

	// ─── 網址 ───────────────────────────────────────────────────────────────

	public static function settings_url( $provider ) {
		$providers = self::providers();
		$tab       = $providers[ $provider ]['tab'] ?? '';
		return admin_url( 'admin.php?page=wclon-settings' ) . ( $tab ? '#' . $tab : '' );
	}

	/** 社交登入的驗證起點：沿用各 provider 既有的登入入口，加上 intent=verify 與 nonce */
	public static function oauth_start_url( $provider ) {
		$providers = self::providers();
		return add_query_arg( array(
			'wclon_action' => $providers[ $provider ]['action'],
			'intent'       => 'verify',
			'_wpnonce'     => wp_create_nonce( self::NONCE_ACTION ),
		), home_url( '/' ) );
	}

	// ─── Turnstile ─────────────────────────────────────────────────────────

	public static function ajax_verify_turnstile() {
		if ( ! current_user_can( WCLON_WC::capability() ) ) {
			wp_send_json_error( array( 'message' => '權限不足。' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::has_credentials( 'turnstile' ) ) {
			wp_send_json_error( array( 'message' => '請先填寫並儲存 Site Key 與 Secret Key。' ) );
		}

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => '沒有收到驗證結果，請重新驗證。' ) );
		}

		$response = wp_remote_post( WCLON_Turnstile::VERIFY_ENDPOINT, array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => WCLON_Turnstile::get( 'secret_key' ),
				'response' => $token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			$message = '無法連線到 Cloudflare：' . $response->get_error_message();
			self::record_failure( 'turnstile', $message );
			wp_send_json_error( array( 'message' => $message ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$message = 'Cloudflare 回應無法解析（HTTP ' . wp_remote_retrieve_response_code( $response ) . '），請稍後再試。';
			self::record_failure( 'turnstile', $message );
			wp_send_json_error( array( 'message' => $message ) );
		}

		if ( empty( $body['success'] ) ) {
			$codes   = isset( $body['error-codes'] ) && is_array( $body['error-codes'] ) ? array_map( 'sanitize_text_field', $body['error-codes'] ) : array();
			$message = self::turnstile_error_message( $codes );
			self::record_failure( 'turnstile', $message );
			wp_send_json_error( array( 'message' => $message ) );
		}

		$details = array();
		if ( self::is_turnstile_test_key( WCLON_Turnstile::get( 'site_key' ) ) ) {
			$details['test_key'] = true;
		}
		self::record_success( 'turnstile', $details );
		wp_send_json_success( array( 'message' => '驗證成功。' ) );
	}

	private static function turnstile_error_message( array $codes ) {
		$map = array(
			'missing-input-secret'   => 'Secret Key 不正確，請到 Cloudflare 複製正確的 Secret Key。',
			'invalid-input-secret'   => 'Secret Key 不正確，請到 Cloudflare 複製正確的 Secret Key。',
			'invalid-input-response' => 'Site Key 與 Secret Key 不是同一組，請確認兩把金鑰來自 Cloudflare 的同一個網站設定。',
			'timeout-or-duplicate'   => '驗證已逾時，請重新驗證一次。',
			'internal-error'         => 'Cloudflare 暫時無法驗證，請稍後再試。',
		);
		foreach ( $codes as $code ) {
			if ( isset( $map[ $code ] ) ) {
				return $map[ $code ];
			}
		}
		return '驗證失敗（' . ( $codes ? implode( ', ', $codes ) : '沒有錯誤代碼' ) . '）。';
	}

	/**
	 * Cloudflare 官方的測試用 Site Key（1x／2x／3x 開頭加一串 0）：永遠通過或永遠失敗，
	 * 沒有任何防護效果。驗證照樣放行（開發站會用到），但設定頁會提醒。
	 */
	public static function is_turnstile_test_key( $site_key ) {
		return (bool) preg_match( '/^[123]x0{10,}/', (string) $site_key );
	}

	// ─── 設定頁 UI ─────────────────────────────────────────────────────────

	/**
	 * 在各憑證表格最後輸出一列「驗證狀態」。社交登入的按鈕是連到 OAuth 起點的連結（主表單裡
	 * 不能放 submit 按鈕），Turnstile 是在頁面上渲染 widget 的按鈕。兩者都只驗證「已儲存」的
	 * 值，欄位改了還沒存時由 wclon-admin.js 攔下提醒。
	 */
	public static function render_status_row( $provider ) {
		$providers = self::providers();
		$status    = self::status( $provider );
		$record    = self::get_record( $provider );
		$option    = 'turnstile' === $provider ? WCLON_Turnstile::OPTION_KEY : WCLON_Settings::OPTION_KEY;
		$fields    = array_map( function ( $key ) use ( $option ) {
			return $option . '[' . $key . ']';
		}, self::field_keys( $provider ) );
		$effect    = 'turnstile' === $provider ? '表單保護目前沒有生效' : '前台目前不會顯示 ' . $providers[ $provider ]['label'] . ' 按鈕';
		?>
		<tr class="wclon-verify-row" id="wclon-verify-<?php echo esc_attr( $provider ); ?>">
			<th scope="row">驗證狀態</th>
			<td>
				<p class="wclon-verify-status wclon-verify-status--<?php echo esc_attr( $status ); ?>">
					<?php
					switch ( $status ) {
						case 'verified':
							echo '<span class="dashicons dashicons-yes-alt"></span> 已驗證';
							if ( ! empty( $record['details']['seeded'] ) ) {
								echo '（升級前已在使用，自動視為已驗證）';
							} elseif ( ! empty( $record['time'] ) ) {
								echo '（' . esc_html( wp_date( 'Y-m-d H:i', (int) $record['time'] ) ) . '）';
							}
							break;
						case 'changed':
							echo '<span class="dashicons dashicons-warning"></span> 憑證或網站網址已變更，需要重新驗證；' . esc_html( $effect ) . '。';
							break;
						case 'unverified':
							echo '<span class="dashicons dashicons-warning"></span> 尚未驗證；' . esc_html( $effect ) . '。';
							break;
						default:
							echo '尚未填寫完整的憑證。';
					}
					?>
				</p>
				<?php
				if ( 'verified' === $status && 'line' === $provider && isset( $record['details']['email'] ) && ! $record['details']['email'] ) {
					echo '<p class="description">驗證時沒有取得 Email：LINE Login channel 的 Email address permission 可能尚未核准，顧客帳號會以佔位 Email 建立。</p>';
				}
				if ( 'verified' === $status && ! empty( $record['details']['test_key'] ) ) {
					echo '<p class="description">目前填的是 Cloudflare 的測試金鑰，沒有實際防護效果，正式站請換成自己的金鑰。</p>';
				}
				if ( ! empty( $record['error'] ) && 'empty' !== $status && ( empty( $record['time'] ) || (int) $record['error_time'] > (int) $record['time'] ) ) {
					echo '<p class="description wclon-verify-error">上次驗證失敗（' . esc_html( wp_date( 'Y-m-d H:i', (int) $record['error_time'] ) ) . '）：' . esc_html( $record['error'] ) . '</p>';
				}

				if ( 'empty' !== $status ) {
					$label = 'verified' === $status ? '重新驗證' : '驗證';
					if ( 'turnstile' === $provider ) {
						printf(
							'<p><button type="button" class="button" data-wclon-verify-fields="%s" data-wclon-turnstile-verify data-sitekey="%s">%s</button></p><div class="wclon-turnstile-verify-box" hidden></div><p class="description wclon-turnstile-verify-msg" hidden></p>',
							esc_attr( implode( ',', $fields ) ),
							esc_attr( WCLON_Turnstile::get( 'site_key' ) ),
							esc_html( $label )
						);
					} else {
						printf(
							'<p><a class="button" href="%s" data-wclon-verify-fields="%s">%s</a></p><p class="description">會帶你到 %s 的授權頁完成一次登入流程，只用來確認憑證與 Callback 網址設定正確，不會建立帳號或綁定。</p>',
							esc_url( self::oauth_start_url( $provider ) ),
							esc_attr( implode( ',', $fields ) ),
							esc_html( $label ),
							esc_html( str_replace( ' 登入', '', $providers[ $provider ]['label'] ) )
						);
					}
				}
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * 功能已啟用但憑證沒驗證時，在後台每一頁提醒：驗證機制會讓功能靜默暫停，不提醒的話站主
	 * 可能很久都不知道前台少了登入按鈕、或 Turnstile 根本沒在保護。
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( WCLON_WC::capability() ) ) {
			return;
		}
		$pending = array();
		foreach ( self::providers() as $provider => $info ) {
			$enabled = 'turnstile' === $provider
				? (bool) WCLON_Turnstile::get( 'enabled' )
				: WCLON_Settings::module_enabled( 'social_login' );
			if ( $enabled && self::has_credentials( $provider ) && ! self::is_verified( $provider ) ) {
				$pending[] = sprintf( '<a href="%s">%s</a>', esc_url( self::settings_url( $provider ) ), esc_html( $info['label'] ) );
			}
		}
		if ( ! $pending ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>終極登入：</strong>%s 的憑證尚未驗證，驗證通過前不會生效。點名稱前往驗證。</p></div>',
			implode( '、', $pending ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面已逐一跳脫
		);
	}
}
