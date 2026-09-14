<?php
/**
 * 管理員 LINE 群組通知設定：LINE 憑證與群組/聊天室接收名單
 *
 * v1.8.0 起與 wc-line-order-notify 合併為同一個外掛，設定畫面改為
 * WCLON_Settings 設定頁裡的「管理員通知」分頁（見 render_tab_content()），
 * 不再有獨立的後台選單項目。option key（`wcan_settings` / `wcan_group_names`）
 * 沿用原本獨立外掛時期的命名，避免資料遺失，不隨這次合併重新命名。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAN_Settings {

	const OPTION_KEY = 'wcan_settings';

	private static $cache = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_wcan_test_push', array( __CLASS__, 'ajax_test_push' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_cache' ) );
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	public static function get( $key, $default = '' ) {
		if ( self::$cache === null ) {
			self::$cache = get_option( self::OPTION_KEY, array() );
		}
		$value = self::$cache[ $key ] ?? null;
		return ( $value !== null && $value !== '' ) ? $value : $default;
	}

	const NAMES_OPTION_KEY = 'wcan_group_names';

	/**
	 * 由 Webhook 呼叫，把群組/聊天室 ID 加入名單（不經過表單 sanitize）
	 * $name 有值時一併存入獨立的 wcan_group_names option（故意不放進 wcan_settings，
	 * 避免每次存設定表單時被 sanitize() 的回傳值整個覆蓋掉）
	 */
	public static function add_group_id( $group_id, $name = '' ) {
		$group_id = sanitize_text_field( $group_id );
		if ( ! $group_id ) {
			return;
		}
		$opts = get_option( self::OPTION_KEY, array() );
		$ids  = isset( $opts['group_ids'] ) && is_array( $opts['group_ids'] ) ? $opts['group_ids'] : array();
		if ( ! in_array( $group_id, $ids, true ) ) {
			$ids[] = $group_id;
		}
		$opts['group_ids'] = array_values( $ids );
		update_option( self::OPTION_KEY, $opts );
		self::flush_cache();

		if ( $name ) {
			$names               = get_option( self::NAMES_OPTION_KEY, array() );
			$names[ $group_id ]  = sanitize_text_field( $name );
			update_option( self::NAMES_OPTION_KEY, $names );
		}
	}

	/**
	 * 呼叫 LINE Messaging API 查詢群組顯示名稱。多人聊天室（room，ID 以 R 開頭）
	 * 在 LINE 裡沒有名稱概念，直接回傳空字串。
	 */
	public static function fetch_group_name( $group_id ) {
		if ( 'C' !== substr( $group_id, 0, 1 ) ) {
			return '';
		}
		// Channel Access Token 為顧客／管理員通知共用欄位，v1.8.2 起集中存在 wclon_settings
		$token = WCLON_Settings::get( 'channel_access_token' );
		if ( ! $token ) {
			return '';
		}
		$response = wp_remote_get( 'https://api.line.me/v2/bot/group/' . rawurlencode( $group_id ) . '/summary', array(
			'timeout' => 10,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return sanitize_text_field( $body['groupName'] ?? '' );
	}

	public static function register_settings() {
		register_setting( 'wcan_settings_group', self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
	}

	public static function sanitize( $input ) {
		$clean                             = array();
		$clean['enabled']                  = ! empty( $input['enabled'] ) ? 1 : 0;
		$clean['messaging_channel_secret'] = trim( sanitize_text_field( $input['messaging_channel_secret'] ?? '' ) );
		$clean['group_ids']                = self::parse_id_list( $input['group_ids'] ?? '' );

		// 新訂單通知文案（{site_name} 可用）
		$clean['title']       = sanitize_text_field( $input['title'] ?? '' ) ?: '🔔 新訂單通知';
		$clean['button_text'] = sanitize_text_field( $input['button_text'] ?? '' ) ?: '前往後台查看';

		self::prune_group_names( $clean['group_ids'] );

		return $clean;
	}

	/**
	 * 移除 group_ids 已被拿掉的群組所留下的名稱快取
	 */
	private static function prune_group_names( array $keep_ids ) {
		$names = get_option( self::NAMES_OPTION_KEY, array() );
		if ( empty( $names ) ) {
			return;
		}
		$pruned = array_intersect_key( $names, array_flip( $keep_ids ) );
		if ( $pruned !== $names ) {
			update_option( self::NAMES_OPTION_KEY, $pruned );
		}
	}

	/**
	 * 解析 ID 清單：接受設定頁 textarea 送出的多行字串，也接受已經是陣列的情況
	 * （WordPress 的 update_option() 在 option 尚不存在時會經由 add_option() 對同一個值
	 * 二次呼叫 sanitize_option 過濾器，導致這個 sanitize() 在同一次儲存中被呼叫兩次；
	 * 第二次收到的就已經是陣列，此處必須能同時處理兩種輸入型態才不會把陣列誤轉成字串 "Array"）
	 */
	private static function parse_id_list( $raw ) {
		$items = is_array( $raw ) ? $raw : preg_split( '/[\r\n]+/', (string) $raw );
		$items = array_filter( array_map( 'trim', $items ) );
		return array_values( array_unique( array_map( 'sanitize_text_field', $items ) ) );
	}

	/**
	 * 設定頁渲染前補齊尚未快取名稱的群組（例如舊資料、或第一次擷取時 API 剛好失敗）
	 */
	private static function backfill_group_names( array $group_ids ) {
		$names   = get_option( self::NAMES_OPTION_KEY, array() );
		$changed = false;
		foreach ( $group_ids as $gid ) {
			if ( empty( $names[ $gid ] ) && 'C' === substr( $gid, 0, 1 ) ) {
				$fetched = self::fetch_group_name( $gid );
				if ( $fetched ) {
					$names[ $gid ] = $fetched;
					$changed       = true;
				}
			}
		}
		if ( $changed ) {
			update_option( self::NAMES_OPTION_KEY, $names );
		}
		return $names;
	}

	// ─── 測試推播 AJAX ──────────────────────────────────────────────────────

	public static function ajax_test_push() {
		check_ajax_referer( 'wcan_admin_action', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => '權限不足。' ) );
		}

		$target_id = sanitize_text_field( wp_unslash( $_POST['line_user_id'] ?? '' ) );
		if ( ! $target_id ) {
			wp_send_json_error( array( 'message' => '請輸入群組/聊天室 ID。' ) );
		}

		$result = WCAN_Notifier::test_push( $target_id );
		if ( true === $result ) {
			wp_send_json_success( array( 'message' => '✓ 測試訊息已發送，請至 LINE 確認。' ) );
		} else {
			wp_send_json_error( array( 'message' => $result ) );
		}
	}

	// ─── 設定頁渲染（嵌入 WCLON_Settings 設定頁的「管理員通知」分頁，見該檔案 render_page()） ──

	public static function render_tab_content() {
		$webhook_url = home_url( '/?wcan_action=webhook' );
		$group_ids   = (array) self::get( 'group_ids', array() );
		$names       = self::backfill_group_names( $group_ids );
		$title       = self::get( 'title', '🔔 新訂單通知' );
		$button_text = self::get( 'button_text', '前往後台查看' );
		?>
		<div class="wclon-callout">
			<div class="wclon-callout__body">
				<strong>管理員 LINE 群組通知</strong>
				<p style="margin:4px 0 0;">把官方帳號跟需要收到通知的員工一起加入同一個 LINE 群組（或多人聊天室），新訂單會用官方帳號的身分發到群組裡，所有成員都看得到；與上方「顧客 LINE 訂單通知」是兩套獨立設定，但共用同一組 Channel Access Token。</p>
				<ol>
					<li>到 <a href="https://developers.line.biz/console/" target="_blank">LINE Developers Console</a> 建立 Provider。</li>
					<li>在同一個 Provider 下建立 <strong>Messaging API channel</strong>（綁定官方帳號），發行長效 Channel Access Token（填在「顧客通知」分頁的「LINE 串接設定」卡片），並在該 channel 頁籤查到 Channel Secret（填在下方）。</li>
					<li>完成 Linked OA 設定，綁定官方帳號。</li>
				</ol>
			</div>
		</div>

		<form method="post" action="options.php" id="wcan-settings-form">
			<?php settings_fields( 'wcan_settings_group' ); ?>

			<div class="wclon-card">
				<h2 class="wclon-card__title">啟用與憑證</h2>
				<table class="form-table">
					<tr>
						<th scope="row">啟用管理員群組通知</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( self::get( 'enabled', 1 ), 1 ); ?>> 有新訂單時推播到下方已記錄的 LINE 群組/聊天室</label>
							<p class="description">關閉後即使有已記錄的群組/聊天室，也不會推播新訂單通知；Webhook 擷取群組 ID 的功能不受影響。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label>Messaging API<br>Channel Secret</label></th>
						<td>
							<input type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[messaging_channel_secret]" value="<?php echo esc_attr( self::get( 'messaging_channel_secret' ) ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">用來驗證下方 Webhook 收到的請求確實來自 LINE，在 LINE Developers Console 的 Messaging API channel 頁籤即可查到。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcan_title">推播標題</label></th>
						<td><input type="text" id="wcan_title" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[title]" value="<?php echo esc_attr( $title ); ?>" class="regular-text" placeholder="🔔 新訂單通知"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcan_button_text">按鈕文字</label></th>
						<td><input type="text" id="wcan_button_text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[button_text]" value="<?php echo esc_attr( $button_text ); ?>" class="regular-text" placeholder="前往後台查看"></td>
					</tr>
				</table>
			</div>

			<div class="wclon-card">
				<h2 class="wclon-card__title">LINE 群組/聊天室名單</h2>
				<div class="wclon-callout" style="margin-bottom:16px;">
					<div class="wclon-callout__body">
						<strong>設定步驟</strong>
						<ol>
							<li>到 LINE Developers Console 的 <strong>Messaging API</strong> channel 頁籤，找到 Webhook settings，貼上下方的 Webhook URL 並開啟「Use webhook」。</li>
							<li>在同一頁籤把「Allow bot to join group chats」開啟（否則官方帳號無法被加入群組）。</li>
							<li>建立一個 LINE 群組（或多人聊天室），把官方帳號與需要收到通知的員工都加入。</li>
							<li>官方帳號被加入群組後（或群組內任何人發言一次），外掛會自動擷取該群組/聊天室 ID 並記錄在下方名單。</li>
						</ol>
					</div>
				</div>
				<table class="form-table" style="max-width:600px;">
					<tr>
						<th scope="row">Webhook URL</th>
						<td><input type="text" readonly value="<?php echo esc_attr( $webhook_url ); ?>" class="large-text" onclick="this.select();"></td>
					</tr>
				</table>
				<table class="form-table" style="max-width:600px;">
					<tr>
						<th scope="row">已記錄的群組/聊天室</th>
						<td>
							<?php if ( empty( $group_ids ) ) : ?>
								<p class="description">尚未記錄任何群組/聊天室，請依上方步驟把官方帳號加入 LINE 群組。</p>
							<?php else : ?>
								<?php foreach ( $group_ids as $gid ) :
									$label = $names[ $gid ] ?? '';
									if ( ! $label ) {
										$label = ( 'R' === substr( $gid, 0, 1 ) ) ? '多人聊天室（LINE 不支援顯示名稱）' : '（尚未取得名稱）';
									}
									?>
									<label style="display:block;margin-bottom:8px;">
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[group_ids][]" value="<?php echo esc_attr( $gid ); ?>" checked>
										<strong><?php echo esc_html( $label ); ?></strong>
										<span style="color:#888;font-size:12px;">(<?php echo esc_html( $gid ); ?>)</span>
									</label>
								<?php endforeach; ?>
								<p class="description">取消勾選後按「儲存設定」即可移除該群組/聊天室。</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>
		</form>

		<div class="wclon-card">
			<h2 class="wclon-card__title">測試推播（管理員群組）</h2>
			<p class="wclon-card__desc">發送一則含新訂單卡片樣式的測試 Flex Message，確認訊息外觀與 Token 設定是否正確。</p>
			<table class="form-table" style="max-width:600px;">
				<tr>
					<th scope="row"><label for="wcan_test_line_id">群組/聊天室 ID</label></th>
					<td>
						<input type="text" id="wcan_test_line_id" class="regular-text" placeholder="Cxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
						<p class="description">留空則無法測試，請輸入上方已記錄的其中一組群組/聊天室 ID。</p>
					</td>
				</tr>
			</table>
			<button type="button" id="wcan_test_btn" class="button button-secondary">發送測試訊息</button>
			<span id="wcan_test_msg" class="wclon-test-msg"></span>
		</div>

		<script>
		(function ($) {
			$('#wcan_test_btn').on('click', function () {
				var btn    = $(this);
				var msg    = $('#wcan_test_msg');
				var lineId = $('#wcan_test_line_id').val().trim();
				btn.prop('disabled', true).text('發送中⋯');
				msg.css('color', '#555').text('');
				$.post(ajaxurl, {
					action:       'wcan_test_push',
					nonce:        '<?php echo esc_js( wp_create_nonce( 'wcan_admin_action' ) ); ?>',
					line_user_id: lineId,
				}, function (res) {
					btn.prop('disabled', false).text('發送測試訊息');
					if (res.success) {
						msg.css('color', '#00a32a').text(res.data.message);
					} else {
						msg.css('color', '#d63638').text(res.data.message || '發送失敗');
					}
				});
			});
		}(jQuery));
		</script>
		<?php
	}
}
