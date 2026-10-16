<?php
/**
 * 從 Nextend Social Login 轉移（v1.47.0 新增）
 *
 * 讀 Nextend 的 `{prefix}social_users` 表（ID／type／identifier），把 Google／LINE／Apple 的綁定
 * 寫成終極登入的 user meta；另可把 `nsl_{provider}` option 裡的憑證帶入空白欄位。
 * 只在偵測到該表時出現在設定頁（系統設定 → Nextend 轉移），不需要 Nextend 外掛仍啟用。
 *
 * 原則：只新增不覆蓋、可重複執行、可整批復原。Facebook／X 等終極登入不支援的平台不匯入。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Nextend_Import {

	const LOG_OPTION = 'wclon_nextend_import_log';
	const NONCE      = 'wclon_nextend_import';

	/**
	 * Nextend type → 終極登入 meta key 與 identifier 格式（依 Nextend 3.1.26 免費版＋Pro 原始碼）。
	 * Google 存 userinfo `id`（= OIDC `sub`，跨 OAuth client 不變）；LINE 存 `/profile` 的 `userId`
	 * （依 Provider 發放）；Apple 存 id_token 原始 `sub`（依 Team ID）。三者都跟終極登入存的是同一個值。
	 * Apple sub 格式沒有官方文件，只擋明顯不是 ID 的值。不符的筆數會在報告列出不匯入。
	 */
	public static function providers() {
		return array(
			'google' => array(
				'label'   => 'Google',
				'meta'    => WCLON_Google_Login::USER_META_KEY,
				'pattern' => '/^\d{5,255}$/',
				'format'  => '純數字',
			),
			'line'   => array(
				'label'   => 'LINE',
				'meta'    => WCLON_Line_Login::USER_META_KEY,
				'pattern' => '/^U[0-9a-f]{32}$/',
				'format'  => 'U + 32 碼小寫十六進位',
			),
			'apple'  => array(
				'label'   => 'Apple',
				'meta'    => WCLON_Apple_Login::USER_META_KEY,
				'pattern' => '/^[0-9A-Za-z._-]{10,255}$/',
				'format'  => '英數字與 . _ -，10 字元以上',
			),
		);
	}

	/**
	 * 憑證對應：終極登入欄位 → Nextend option 的 key（依 Pro 3.1.26 原始碼）。Apple 的 `client_id`
	 * 是 Nextend 儲存時從 `service_identifier` 複製來的（清除憑證時會被清空），所以先讀後者。
	 */
	private static function credential_map() {
		return array(
			'google' => array(
				'google_client_id'     => array( 'client_id' ),
				'google_client_secret' => array( 'client_secret' ),
			),
			'line'   => array(
				'login_channel_id'     => array( 'client_id' ),
				'login_channel_secret' => array( 'client_secret' ),
			),
			'apple'  => array(
				'apple_client_id'   => array( 'service_identifier', 'client_id' ),
				'apple_team_id'     => array( 'team_identifier' ),
				'apple_key_id'      => array( 'private_key_id' ),
				'apple_private_key' => array( 'private_key' ),
			),
		);
	}

	public static function init() {
		add_action( 'admin_post_wclon_nextend_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_wclon_nextend_credentials', array( __CLASS__, 'handle_credentials' ) );
		add_action( 'admin_post_wclon_nextend_undo', array( __CLASS__, 'handle_undo' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'social_users';
	}

	/** 偵測到 Nextend 資料表（或有匯入紀錄可復原）才顯示頁籤 */
	public static function available() {
		static $found = null;
		if ( null === $found ) {
			global $wpdb;
			$table = self::table();
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		}
		return $found || get_option( self::LOG_OPTION );
	}

	// ─── 分析 ────────────────────────────────────────────────────────────────

	/**
	 * 逐筆分類。依序模擬寫入（同一批裡第二筆同會員同平台、或同一個 identifier 綁兩個會員，會被判成重複），
	 * 所以 ready 的筆數就是實際會寫入的筆數。
	 *
	 * @return array{rows: array, unsupported: array, unsupported_only_users: int}
	 */
	public static function analyze() {
		global $wpdb;
		$providers = self::providers();

		$rows = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) ) === self::table() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only
			$rows = $wpdb->get_results( 'SELECT s.ID AS user_id, LOWER(s.type) AS type, s.identifier, u.ID AS exists_id FROM `' . self::table() . '` s LEFT JOIN `' . $wpdb->users . '` u ON u.ID = s.ID ORDER BY s.social_users_id', ARRAY_A );
		}

		// 終極登入目前的綁定：by_user[meta][user_id] = value；by_value[meta][value] = user_id
		$by_user  = array();
		$by_value = array();
		$keys     = wp_list_pluck( $providers, 'meta' );
		$in       = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ($in) AND meta_value <> ''", array_values( $keys ) ), ARRAY_A );
		foreach ( $meta_rows as $m ) {
			$by_user[ $m['meta_key'] ][ (int) $m['user_id'] ] = $m['meta_value'];
			$by_value[ $m['meta_key'] ][ $m['meta_value'] ]   = (int) $m['user_id'];
		}

		$result          = array();
		$unsupported     = array();
		$users_supported = array();
		$users_other     = array();

		foreach ( $rows as $r ) {
			$type = $r['type'];
			$uid  = (int) $r['user_id'];
			$id   = trim( (string) $r['identifier'] );

			if ( ! isset( $providers[ $type ] ) ) {
				$unsupported[ $type ] = ( $unsupported[ $type ] ?? 0 ) + 1;
				if ( $r['exists_id'] ) $users_other[ $uid ] = true;
				continue;
			}
			$meta = $providers[ $type ]['meta'];

			if ( ! $r['exists_id'] ) {
				$status = 'missing_user';
			} elseif ( ! preg_match( $providers[ $type ]['pattern'], $id ) ) {
				$status = 'bad_format';
			} elseif ( ( $by_user[ $meta ][ $uid ] ?? '' ) === $id ) {
				$status = 'done';
			} elseif ( isset( $by_user[ $meta ][ $uid ] ) ) {
				$status = 'conflict_user';
			} elseif ( isset( $by_value[ $meta ][ $id ] ) ) {
				$status = 'conflict_id';
			} else {
				$status                      = 'ready';
				$by_user[ $meta ][ $uid ]    = $id;
				$by_value[ $meta ][ $id ]    = $uid;
			}
			if ( $r['exists_id'] && 'bad_format' !== $status ) $users_supported[ $uid ] = true;

			$result[] = array(
				'user_id'    => $uid,
				'type'       => $type,
				'identifier' => $id,
				'status'     => $status,
			);
		}

		return array(
			'rows'                   => $result,
			'unsupported'            => $unsupported,
			'unsupported_only_users' => count( array_diff_key( $users_other, $users_supported ) ),
		);
	}

	public static function status_labels() {
		return array(
			'ready'         => '可匯入',
			'done'          => '已綁定（相同）',
			'conflict_user' => '會員已綁定其他帳號',
			'conflict_id'   => '已綁定其他會員',
			'bad_format'    => '格式不符',
			'missing_user'  => '會員已刪除',
		);
	}

	// ─── 處理 ────────────────────────────────────────────────────────────────

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '權限不足。' );
		}
		check_admin_referer( $nonce_action );
	}

	private static function back( $result ) {
		wp_safe_redirect( add_query_arg( 'wclon_nextend', $result, admin_url( 'admin.php?page=wclon-settings' ) ) . '#nextend' );
		exit;
	}

	public static function handle_import() {
		self::guard( self::NONCE );

		$notify = sanitize_key( $_POST['line_notify'] ?? '' );
		if ( ! in_array( $notify, array( 'on', 'off' ), true ) ) {
			self::back( 'need_notify' );
		}
		$mark_claimed = ! empty( $_POST['mark_coupon_claimed'] );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$providers = self::providers();
		$analysis  = self::analyze();
		$imported  = array();
		$claimed   = (array) get_option( WCLON_Line_Login::LINE_ID_COUPON_CLAIMED_OPTION, array() );
		$claimed_added = array();

		foreach ( $analysis['rows'] as $r ) {
			if ( 'ready' !== $r['status'] ) continue;
			$meta = $providers[ $r['type'] ]['meta'];
			update_user_meta( $r['user_id'], $meta, $r['identifier'] );
			$item = array( $r['user_id'], $r['type'], $r['identifier'] );

			if ( 'line' === $r['type'] ) {
				update_user_meta( $r['user_id'], WCLON_Line_Login::NOTIFY_META_KEY, 'on' === $notify ? 1 : 0 );
				// 標記「這個 LINE 帳號已領過歡迎券」：之後加好友（webhook follow 補發）或解綁再綁都不會發券
				if ( $mark_claimed && ! in_array( $r['identifier'], $claimed, true ) ) {
					$claimed[]       = $r['identifier'];
					$claimed_added[] = $r['identifier'];
				}
			}
			$imported[] = $item;
		}
		if ( $claimed_added ) {
			update_option( WCLON_Line_Login::LINE_ID_COUPON_CLAIMED_OPTION, $claimed, false );
		}

		$log   = (array) get_option( self::LOG_OPTION, array() );
		$log[] = array(
			'time'          => time(),
			'by'            => get_current_user_id(),
			'line_notify'   => $notify,
			'items'         => $imported,
			'claimed_added' => $claimed_added,
		);
		update_option( self::LOG_OPTION, $log, false );

		self::back( 'imported-' . count( $imported ) );
	}

	/**
	 * 復原所有批次：只刪「值仍是當初匯入的值」的 meta（之後顧客自己解綁再綁別的帳號就不動）。
	 */
	public static function handle_undo() {
		self::guard( self::NONCE . '_undo' );

		$providers = self::providers();
		$log       = (array) get_option( self::LOG_OPTION, array() );
		$removed   = 0;
		$claimed_added = array();

		foreach ( $log as $batch ) {
			foreach ( (array) ( $batch['items'] ?? array() ) as $item ) {
				list( $uid, $type, $id ) = $item;
				if ( ! isset( $providers[ $type ] ) ) continue;
				if ( get_user_meta( $uid, $providers[ $type ]['meta'], true ) !== $id ) continue;
				delete_user_meta( $uid, $providers[ $type ]['meta'] );
				if ( 'line' === $type ) {
					delete_user_meta( $uid, WCLON_Line_Login::NOTIFY_META_KEY );
				}
				$removed++;
			}
			$claimed_added = array_merge( $claimed_added, (array) ( $batch['claimed_added'] ?? array() ) );
		}

		if ( $claimed_added ) {
			$claimed = (array) get_option( WCLON_Line_Login::LINE_ID_COUPON_CLAIMED_OPTION, array() );
			// 匯入後真的發過券的（會員 meta 有券號）不能從清單拿掉，否則可能重複發券；
			// 這裡只看清單本身無法分辨，保守起見：該 LINE ID 目前若仍綁在有券號的會員上就保留
			$keep = array();
			foreach ( $claimed_added as $id ) {
				$uid = WCLON_OAuth::find_user_by_meta( WCLON_Line_Login::USER_META_KEY, $id );
				if ( $uid && get_user_meta( $uid, WCLON_Line_Login::COUPON_CODE_META_KEY, true ) ) {
					$keep[] = $id;
				}
			}
			$claimed = array_values( array_diff( $claimed, array_diff( $claimed_added, $keep ) ) );
			update_option( WCLON_Line_Login::LINE_ID_COUPON_CLAIMED_OPTION, $claimed, false );
		}

		delete_option( self::LOG_OPTION );
		self::back( 'undone-' . $removed );
	}

	/** 讀 Nextend 的憑證 option（maybe_serialize 存的陣列） */
	private static function nextend_settings( $provider ) {
		$raw = get_option( 'nsl_' . $provider );
		if ( false === $raw ) return array();
		$raw = maybe_unserialize( $raw );
		if ( is_string( $raw ) ) {
			$json = json_decode( $raw, true );
			$raw  = is_array( $json ) ? $json : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @return array<string, array{field: string, from_key: string, nsl: string, current: string, action: string}[]>
	 */
	public static function credential_plan() {
		$plan = array();
		foreach ( self::credential_map() as $provider => $fields ) {
			$nsl = self::nextend_settings( $provider );
			if ( ! $nsl ) continue;
			foreach ( $fields as $field => $candidates ) {
				$value    = '';
				$from_key = '';
				foreach ( $candidates as $key ) {
					if ( isset( $nsl[ $key ] ) && is_scalar( $nsl[ $key ] ) && '' !== trim( (string) $nsl[ $key ] ) ) {
						$value    = trim( (string) $nsl[ $key ] );
						$from_key = $key;
						break;
					}
				}
				$current = (string) WCLON_Settings::get( $field );
				if ( '' === $value ) {
					$action = 'missing';
				} elseif ( '' === $current ) {
					$action = 'fill';
				} elseif ( $current === $value ) {
					$action = 'same';
				} else {
					$action = 'differ';
				}
				$plan[ $provider ][] = compact( 'field', 'from_key', 'value', 'current', 'action' );
			}
		}
		return $plan;
	}

	public static function handle_credentials() {
		self::guard( self::NONCE . '_cred' );

		$settings = get_option( WCLON_Settings::OPTION_KEY, array() );
		$filled   = 0;
		foreach ( self::credential_plan() as $items ) {
			foreach ( $items as $item ) {
				if ( 'fill' !== $item['action'] ) continue;
				$settings[ $item['field'] ] = 'apple_private_key' === $item['field']
					? sanitize_textarea_field( $item['value'] )
					: sanitize_text_field( $item['value'] );
				$filled++;
			}
		}
		if ( $filled ) {
			// 設定頁的 sanitize() 是以表單送出的整包欄位重建 option，這裡只改幾個欄位，跳過它直接寫入
			remove_filter( 'sanitize_option_' . WCLON_Settings::OPTION_KEY, array( 'WCLON_Settings', 'sanitize' ) );
			update_option( WCLON_Settings::OPTION_KEY, $settings );
			WCLON_Settings::flush_cache();
		}
		self::back( 'cred-' . $filled );
	}

	// ─── 畫面 ────────────────────────────────────────────────────────────────

	private static function mask( $value, $field = '' ) {
		if ( '' === $value ) return '—';
		if ( 'apple_private_key' === $field ) return '（已填，' . strlen( $value ) . ' 字元）';
		if ( strlen( $value ) <= 10 ) return substr( $value, 0, 2 ) . '…' . substr( $value, -2 );
		return substr( $value, 0, 4 ) . '…' . substr( $value, -4 );
	}

	public static function render_tab_content() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$providers = self::providers();
		$labels    = self::status_labels();
		$analysis  = self::analyze();
		$log       = (array) get_option( self::LOG_OPTION, array() );

		// 統計：counts[type][status]
		$counts  = array();
		$samples = array();
		foreach ( $analysis['rows'] as $r ) {
			$counts[ $r['type'] ][ $r['status'] ] = ( $counts[ $r['type'] ][ $r['status'] ] ?? 0 ) + 1;
			if ( 'bad_format' === $r['status'] && count( $samples[ $r['type'] ] ?? array() ) < 5 ) {
				$samples[ $r['type'] ][] = $r;
			}
		}
		$ready_total = 0;
		foreach ( $counts as $c ) $ready_total += $c['ready'] ?? 0;

		$result = sanitize_text_field( wp_unslash( $_GET['wclon_nextend'] ?? '' ) );
		if ( $result ) {
			list( $kind, $num ) = array_pad( explode( '-', $result, 2 ), 2, '0' );
			$msg = array(
				'imported'    => array( 'success', '已匯入 ' . (int) $num . ' 筆綁定。' ),
				'undone'      => array( 'success', '已復原，移除 ' . (int) $num . ' 筆匯入的綁定。' ),
				'cred'        => array( 'success', (int) $num ? '已帶入 ' . (int) $num . ' 個憑證欄位，請到各平台頁籤重新驗證。' : '沒有需要帶入的欄位。' ),
				'need_notify' => array( 'error', '請先選擇匯入的 LINE 會員是否開啟訂單通知。' ),
			)[ $kind ] ?? null;
			if ( $msg ) {
				echo '<div class="notice notice-' . esc_attr( $msg[0] ) . ' inline"><p>' . esc_html( $msg[1] ) . '</p></div>';
			}
		}

		$nsl_active = function_exists( 'is_plugin_active' ) && ( is_plugin_active( 'nextend-facebook-connect/nextend-facebook-connect.php' ) || is_plugin_active( 'nextend-social-login-pro/nextend-social-login-pro.php' ) );
		?>
		<div class="wclon-card">
			<h2 class="wclon-card__title">從 Nextend Social Login 轉移</h2>
			<p class="wclon-card__desc">把 Nextend 記錄的社群帳號綁定（<code><?php echo esc_html( self::table() ); ?></code> 表）匯入終極登入，會員就能用原本的 Google／LINE／Apple 帳號直接登入原本的會員帳號。只新增、不覆蓋已有的綁定，可以重複執行，也可以整批復原。</p>
			<ol class="wclon-nextend-steps">
				<li><strong>先在各平台後台「新增」終極登入的回呼網址</strong>（Nextend 的舊網址先不要刪）：
					<ul>
						<li>LINE Login channel：<code><?php echo esc_html( home_url( '/?wclon_action=callback' ) ); ?></code></li>
						<li>Google OAuth 用戶端：<code><?php echo esc_html( home_url( '/?wclon_action=google_callback' ) ); ?></code></li>
						<li>Apple Services ID：<code><?php echo esc_html( home_url( '/?wclon_action=apple_callback' ) ); ?></code></li>
					</ul>
				</li>
				<li>帶入憑證（下方），到 LINE／Google／Apple 頁籤按「驗證」。<strong>LINE 一定要用 Nextend 原本那個 Provider 底下的 Login channel</strong>，LINE User ID 依 Provider 發放，換 Provider 會對不上；Apple 要用同一個開發者帳號（Team ID）。</li>
				<li>匯入綁定（下方）。</li>
				<li>確認終極登入的按鈕登入正常後，再停用 Nextend（停用不會刪除它的資料表，之後仍可再匯入或比對）。<?php if ( $nsl_active ) : ?><br><span class="description">目前 Nextend 仍在啟用中，前台會同時出現兩套登入按鈕。</span><?php endif; ?></li>
			</ol>
		</div>

		<?php $plan = self::credential_plan(); ?>
		<div class="wclon-card">
			<h2 class="wclon-card__title">憑證</h2>
			<?php if ( ! $plan ) : ?>
				<p class="wclon-card__desc">找不到 Nextend 的 Google／LINE／Apple 憑證設定（<code>nsl_google</code>／<code>nsl_line</code>／<code>nsl_apple</code>）。</p>
			<?php else : ?>
				<p class="wclon-card__desc">只帶入終極登入目前<strong>空白</strong>的欄位；已經填了不同值的不會覆蓋。帶入後該平台會變成「未驗證」，需要到對應頁籤按「驗證」才會啟用。</p>
				<table class="widefat striped wclon-nextend-table">
					<thead><tr><th>平台／欄位</th><th>Nextend</th><th>終極登入目前</th><th>結果</th></tr></thead>
					<tbody>
					<?php
					$action_labels = array( 'fill' => '會帶入', 'same' => '相同', 'differ' => '不同，不覆蓋', 'missing' => 'Nextend 沒有' );
					$can_fill      = false;
					foreach ( $plan as $provider => $items ) :
						foreach ( $items as $item ) :
							$can_fill = $can_fill || 'fill' === $item['action'];
							?>
							<tr>
								<td><?php echo esc_html( $providers[ $provider ]['label'] ); ?>／<code><?php echo esc_html( $item['field'] ); ?></code></td>
								<td><?php echo esc_html( self::mask( $item['value'], $item['field'] ) ); ?><?php if ( $item['from_key'] ) : ?> <span class="description">(<?php echo esc_html( $item['from_key'] ); ?>)</span><?php endif; ?></td>
								<td><?php echo esc_html( self::mask( $item['current'], $item['field'] ) ); ?></td>
								<td><?php echo esc_html( $action_labels[ $item['action'] ] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $can_fill ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wclon-nextend-form">
					<input type="hidden" name="action" value="wclon_nextend_credentials">
					<?php wp_nonce_field( self::NONCE . '_cred' ); ?>
					<?php submit_button( '帶入憑證', 'secondary', 'submit', false ); ?>
				</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="wclon-card">
			<h2 class="wclon-card__title">綁定資料</h2>
			<?php if ( ! $counts && ! $analysis['unsupported'] ) : ?>
				<p class="wclon-card__desc">Nextend 資料表裡沒有任何綁定資料。</p>
			<?php else : ?>
				<table class="widefat striped wclon-nextend-table">
					<thead><tr><th>平台</th><?php foreach ( $labels as $label ) : ?><th><?php echo esc_html( $label ); ?></th><?php endforeach; ?></tr></thead>
					<tbody>
					<?php foreach ( $providers as $type => $p ) : if ( empty( $counts[ $type ] ) ) continue; ?>
						<tr>
							<td><?php echo esc_html( $p['label'] ); ?></td>
							<?php foreach ( array_keys( $labels ) as $status ) : ?>
								<td><?php echo esc_html( number_format_i18n( $counts[ $type ][ $status ] ?? 0 ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<ul class="wclon-nextend-notes">
					<li>「會員已綁定其他帳號」「已綁定其他會員」：終極登入裡已經有不同的綁定，以終極登入現有的為準，不會改動。</li>
					<li>「會員已刪除」：Nextend 表裡留下的舊紀錄，略過。</li>
					<?php foreach ( $samples as $type => $rows ) : ?>
					<li>
						<?php echo esc_html( $providers[ $type ]['label'] ); ?>「格式不符」：預期格式是 <?php echo esc_html( $providers[ $type ]['format'] ); ?>，這些不會匯入。前幾筆：
						<?php foreach ( $rows as $r ) : ?><code><?php echo esc_html( self::mask( $r['identifier'] ) ); ?>（<?php echo esc_html( strlen( $r['identifier'] ) ); ?> 字元）</code> <?php endforeach; ?>
					</li>
					<?php endforeach; ?>
					<?php if ( $analysis['unsupported'] ) : ?>
					<li>終極登入不支援、不匯入的平台：
						<?php
						$parts = array();
						foreach ( $analysis['unsupported'] as $type => $n ) $parts[] = $type . ' ' . number_format_i18n( $n ) . ' 筆';
						echo esc_html( implode( '、', $parts ) );
						?>。
						<?php if ( $analysis['unsupported_only_users'] ) : ?>
						其中 <?php echo esc_html( number_format_i18n( $analysis['unsupported_only_users'] ) ); ?> 位會員只綁了這些平台，轉移後要改用 Email／密碼登入（可用「忘記密碼」設定密碼）或重新綁定其他平台。
						<?php endif; ?>
					</li>
					<?php endif; ?>
				</ul>

				<?php if ( $ready_total ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wclon-nextend-form">
					<input type="hidden" name="action" value="wclon_nextend_import">
					<?php wp_nonce_field( self::NONCE ); ?>
					<table class="form-table">
						<?php if ( ! empty( $counts['line']['ready'] ) ) : ?>
						<tr>
							<th scope="row">LINE 訂單通知</th>
							<td>
								<label><input type="radio" name="line_notify" value="on" required> 開啟</label><br>
								<label><input type="radio" name="line_notify" value="off"> 關閉（顧客可在會員中心自行開啟）</label>
								<p class="description">匯入的 <?php echo esc_html( number_format_i18n( $counts['line']['ready'] ) ); ?> 位 LINE 會員是否預設收 LINE 訂單通知。開啟後會用掉官方帳號的訊息額度，且顧客要是官方帳號好友才收得到。</p>
							</td>
						</tr>
						<tr>
							<th scope="row">LINE 綁定歡迎優惠券</th>
							<td>
								<label><input type="checkbox" name="mark_coupon_claimed" value="1" checked> 把匯入的 LINE 帳號標記為「已領過」</label>
								<p class="description">建議勾選。不勾的話，這些顧客之後加官方帳號好友、或解除綁定再重新綁定時，會各自收到一張歡迎優惠券。</p>
							</td>
						</tr>
						<?php else : ?>
						<input type="hidden" name="line_notify" value="off">
						<?php endif; ?>
					</table>
					<?php submit_button( '匯入 ' . number_format_i18n( $ready_total ) . ' 筆綁定', 'primary', 'submit', false ); ?>
				</form>
				<?php else : ?>
				<p><strong>沒有可匯入的綁定。</strong></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php if ( $log ) : ?>
		<div class="wclon-card">
			<h2 class="wclon-card__title">匯入紀錄</h2>
			<ul class="wclon-nextend-notes">
				<?php foreach ( $log as $batch ) : $by = get_userdata( (int) ( $batch['by'] ?? 0 ) ); ?>
				<li><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $batch['time'] ) ); ?>：匯入 <?php echo esc_html( number_format_i18n( count( (array) $batch['items'] ) ) ); ?> 筆（LINE 訂單通知<?php echo 'on' === ( $batch['line_notify'] ?? '' ) ? '開啟' : '關閉'; ?>）<?php echo $by ? '，' . esc_html( $by->display_name ) : ''; ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wclon-nextend-form">
				<input type="hidden" name="action" value="wclon_nextend_undo">
				<?php wp_nonce_field( self::NONCE . '_undo' ); ?>
				<?php submit_button( '復原所有匯入', 'secondary', 'submit', false ); ?>
				<span class="description">移除匯入時寫入、且到現在都沒被改過的綁定。顧客之後自己重新綁定的不會動。</span>
			</form>
		</div>
		<?php endif; ?>
		<?php
	}
}
