<?php
/**
 * 壓制 WordPress 核心寄給管理員的通知信：新使用者註冊、密碼變更、外掛自動更新完成。
 *
 * 純粹是「關掉 WP 核心既有的信」，不是新增通知管道。v1.19.0～v1.20.x 曾是本外掛內建功能，
 * v1.21.0 一度整個移除、獨立成不依賴本外掛的 wp-system-mail-control 外掛，供「不想安裝一整套
 * 社交登入外掛、只想要這三個信件開關」的站台單獨安裝。v1.22.0 起本外掛重新內建這個功能——
 * 兩邊並存：已經在用本外掛（社交登入功能）的站台不需要另外裝一個外掛就有這三個開關；沒有安裝
 * 本外掛、只想要信件開關的站台則安裝 wp-system-mail-control 即可。兩者各自獨立運作（不同
 * option），若同一站台兩者都裝，效果是互不干擾的「只要任一邊開啟即壓制」（皆為冪等的
 * suppress-only 邏輯，不會互相覆蓋成「未壓制」)，只是介面上會有兩份重複設定，不建議同站台
 * 兩者都裝，但技術上不會衝突。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_System_Email_Settings {

	const OPTION_KEY = 'wclon_system_email_settings';

	/** 若站台曾安裝 wp-system-mail-control（獨立外掛版本），供一次性帶入起始值用 */
	const SIBLING_PLUGIN_OPTION_KEY = 'wsmc_settings';

	private static $cache = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed_from_sibling_plugin' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_cache' ) );

		add_filter( 'wp_new_user_notification_email_admin', array( __CLASS__, 'maybe_disable_new_user_admin_email' ) );
		add_filter( 'wp_password_change_notification_email', array( __CLASS__, 'maybe_disable_password_change_admin_email' ) );
		add_filter( 'auto_plugin_update_send_email', array( __CLASS__, 'maybe_disable_plugin_update_email' ), 10, 3 );
	}

	/**
	 * 一次性帶入起始值：若本外掛尚未儲存過自己的設定、且站台同時裝著 wp-system-mail-control
	 * 且該外掛已有設定值，把那份值複製過來當起始狀態（**不刪除**對方的 option——那是完全獨立
	 * 運作的另一個外掛，不能因為這裡讀過一次就清掉它自己的資料）。這只是避免「站台原本已經
	 * 透過獨立外掛把三個開關都打開了，結果本外掛內建功能一恢復卻預設全部關閉」這種功能倒退的
	 * 情況，跟 v1.21.0 拆分時的一次性「遷移＋刪除舊 option」語意不同，這裡是「複製＋保留」。
	 */
	public static function maybe_seed_from_sibling_plugin() {
		if ( false !== get_option( self::OPTION_KEY, false ) ) {
			return; // 本外掛已有自己的設定，不需要帶入
		}
		$sibling = get_option( self::SIBLING_PLUGIN_OPTION_KEY, false );
		if ( false === $sibling ) {
			return; // 站台沒裝 wp-system-mail-control，或該外掛也還沒存過設定
		}
		update_option( self::OPTION_KEY, $sibling );
		self::flush_cache();
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	public static function get( $key, $default = 0 ) {
		if ( self::$cache === null ) {
			self::$cache = get_option( self::OPTION_KEY, array() );
		}
		$value = self::$cache[ $key ] ?? null;
		return ( $value !== null && $value !== '' ) ? $value : $default;
	}

	public static function register_settings() {
		register_setting( 'wclon_system_email_settings_group', self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
	}

	public static function sanitize( $input ) {
		$clean                                        = array();
		$clean['disable_new_user_admin_email']        = ! empty( $input['disable_new_user_admin_email'] ) ? 1 : 0;
		$clean['disable_password_change_admin_email'] = ! empty( $input['disable_password_change_admin_email'] ) ? 1 : 0;
		$clean['disable_plugin_update_admin_email']   = ! empty( $input['disable_plugin_update_admin_email'] ) ? 1 : 0;
		return $clean;
	}

	// ─── Filter callbacks ──────────────────────────────────────────────────

	/**
	 * WordPress 核心「新使用者註冊」通知信有兩封（分寄會員與管理員，各自獨立的 filter），
	 * 這裡只關管理員那一封。開關關閉（預設）時原樣放行 $email 陣列，不影響會員收到的那封。
	 * 停用手法：把 'to' 設成空字串——wp_mail() 收到空收件人會直接失敗、不寄出郵件
	 * （會觸發 wp_mail_failed action，但沒有實際寄送行為），比整段跳過 apply_filters
	 * 更單純，也不需要碰 wp_new_user_notification() 內部其餘邏輯（例如寫入 activation key）。
	 */
	public static function maybe_disable_new_user_admin_email( $email ) {
		if ( self::get( 'disable_new_user_admin_email' ) ) {
			$email['to'] = '';
		}
		return $email;
	}

	/**
	 * 使用者密碼變更後，WordPress 核心預設會另外寄一封通知信給管理員信箱
	 * （wp_password_change_notification()，與寄給使用者本人的那封是各自獨立的流程，
	 * 沒有共用同一個 filter），手法同上——把 'to' 清空讓 wp_mail() 不寄出。
	 */
	public static function maybe_disable_password_change_admin_email( $email ) {
		if ( self::get( 'disable_password_change_admin_email' ) ) {
			$email['to'] = '';
		}
		return $email;
	}

	/**
	 * WordPress 核心背景自動更新（WP-Cron）完成後，會用 auto_plugin_update_send_email
	 * filter 判斷要不要寄出「自動更新完成」信給管理員（WP 5.5+，僅涵蓋有啟用自動更新的
	 * 外掛；沒有「有更新可用」信，只有這一種完成信）。回傳 false 即完全不寄。
	 */
	public static function maybe_disable_plugin_update_email( $send, $successful_updates = array(), $failed_updates = array() ) {
		if ( self::get( 'disable_plugin_update_admin_email' ) ) {
			return false;
		}
		return $send;
	}

	// ─── 設定頁渲染（嵌入 WCLON_Settings 設定頁的「系統信件」分頁，見該檔案 render_page()） ──

	public static function render_tab_content() {
		$new_user   = self::get( 'disable_new_user_admin_email' );
		$pw_change  = self::get( 'disable_password_change_admin_email' );
		$plugin_upd = self::get( 'disable_plugin_update_admin_email' );
		?>
		<form method="post" action="options.php" id="wclon-system-email-settings-form">
			<?php settings_fields( 'wclon_system_email_settings_group' ); ?>
			<div class="wclon-card">
				<h2 class="wclon-card__title">WordPress 管理員通知信件</h2>
				<p class="wclon-card__desc">關閉 WordPress 核心（含其他外掛透過標準機制觸發）預設寄給管理員信箱的通知信，僅影響管理員收到的那一封，可減少 Email 發送量。</p>
				<table class="form-table">
					<tr>
						<th scope="row">新使用者註冊通知</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_new_user_admin_email]" value="1" <?php checked( $new_user, 1 ); ?>> 停用「有新使用者註冊」寄給管理員的通知信</label></td>
					</tr>
					<tr>
						<th scope="row">使用者密碼變更通知</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_password_change_admin_email]" value="1" <?php checked( $pw_change, 1 ); ?>> 停用「使用者密碼已變更」寄給管理員的通知信</label></td>
					</tr>
					<tr>
						<th scope="row">外掛自動更新通知</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_plugin_update_admin_email]" value="1" <?php checked( $plugin_upd, 1 ); ?>> 停用「外掛自動更新完成」寄給管理員的通知信</label>
							<p class="description">僅影響 WordPress 背景自動更新完成後寄出的外掛更新通知信；不影響其他外掛主動寄送的通知信（例如訂單相關信件）。</p>
						</td>
					</tr>
				</table>
			</div>
		</form>
		<?php
	}
}
