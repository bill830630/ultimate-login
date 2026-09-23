<?php
/**
 * 後台設定頁：LINE 憑證、觸發狀態、Flex Message 樣式
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Settings {

	const OPTION_KEY = 'wclon_settings';

	/**
	 * 模組開關 option（v1.36.0 新增，仿效終極電商的模組開關系統）。
	 *
	 * 跟 self::OPTION_KEY 刻意分開存成獨立 option：模組開關要在 WCLON_Settings::init()
	 * 都還沒跑到主表單那些欄位之前就能查詢（main 檔案要用它決定該不該呼叫其他 class 的
	 * ::init()），放進同一包大 option 會有先有蛋的問題；獨立出來也讓「模組」頁籤可以是自己的
	 * 獨立 <form>，不會被主表單的 sanitize() 牽連。
	 */
	const MODULE_OPTION_KEY = 'wclon_module_settings';

	private static $cache = null;

	private static $module_cache = null;

	/**
	 * add_menu_page() 回傳的 hook suffix，供 enqueue_admin_assets() 比對用。
	 *
	 * 不寫死字串（曾經是 `woocommerce_page_wclon-settings`，v1.24.0 改成頂層選單後變成
	 * `toplevel_page_wclon-settings`）——選單位置一旦搬動，寫死的字串會靜默失效：後台設定頁
	 * 的 CSS 完全不載入、版面整個跑掉，卻不會有任何錯誤訊息。改成記住 add_menu_page() 當下
	 * 實際回傳的值，日後再怎麼搬都不會對不上。
	 */
	private static $page_hook = '';

	/**
	 * 與終極電商共用的「快捷鍵」父選單 icon（v1.37.0 新增）。
	 *
	 * 逐字複製自終極電商 includes/admin/menus.php 裡 twshop_register_menus()
	 * add_menu_page() 用的同一串 base64 SVG——兩個外掛各自獨立、不能互相 require
	 * 對方檔案，只能各自維護一份相同內容的複本。若終極電商更新這個 icon（換圖案、
	 * 換底色…），這裡要記得手動同步，否則兩站會因為哪個外掛先建立父選單而顯示
	 * 不同圖示。
	 */
	const SHORTCUT_ICON = 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyBmaWxsPSJ3aGl0ZSIgaWQ9Il/lnJblsaRfMiIgZGF0YS1uYW1lPSLlnJblsaQgMiIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB2aWV3Qm94PSIwIDAgNDEzLjExIDQxMy4xMSI+CiAgPGcgaWQ9Il/lnJblsaRfMS0yIiBkYXRhLW5hbWU9IuWcluWxpCAxIj4KICAgIDxnPgogICAgICA8cGF0aCBkPSJNMjA2LjU1LDBDMTM5LjI1LDAsNzkuNDcsMzIuMiw0MS43Niw4Mi4wMmw4MC40Niw4MC40Niw0NC4wNy00NC4wN2MxMy42NC0xMy42NCwyOS42NS0yMy40NCw0Ni43LTI5LjQ0LDEwLjIzLTMuNTksMjAuODMtNS44MiwzMS41NC02LjY3LDM1LjExLTIuNzksNzEuMTksOS4yNSw5OC4wNCwzNi4xMSw0OC42OSw0OC42OCw0OC42OCwxMjcuNjIsMCwxNzYuMy0yNi44NSwyNi44Ny02Mi45NCwzOC45MS05OC4wNSwzNi4xMS0xMC42Mi0uODQtMjEuMTYtMy4wMy0zMS4zMy02LjU4LTE3LjE0LTUuOTktMzMuMjItMTUuODQtNDYuOTEtMjkuNTMtLjA0LS4wMy0uMDYtLjA3LS4xLS4xMmwtNDMuOTYtNDMuOTYtODAuNDYsODAuNDZjMzcuNzEsNDkuODIsOTcuNDksODIuMDIsMTY0Ljc5LDgyLjAyLDExNC4wOCwwLDIwNi41NS05Mi40OCwyMDYuNTUtMjA2LjU1UzMyMC42MywwLDIwNi41NSwwWiIvPgogICAgICA8cGF0aCBkPSJNMTEuMTMsMTM5LjUzQzMuOTIsMTYwLjU1LDAsMTgzLjA5LDAsMjA2LjU1czMuOTIsNDYuMDEsMTEuMTMsNjcuMDJsNjcuMDItNjcuMDJMMTEuMTMsMTM5LjUzWiIvPgogICAgICA8cGF0aCBkPSJNMjQ0LjUzLDI2OC4wOGMxOS4wNywzLjA3LDM5LjI5LTIuNzUsNTMuOTktMTcuNDYsMjQuMzMtMjQuMzMsMjQuMzItNjMuOC0uMDEtODguMTQtMTQuNy0xNC43LTM0LjkxLTIwLjUxLTUzLjk3LTE3LjQ1LTExLjM3LDEuODEtMjIuMzMsNi43Ny0zMS40NiwxNC45Mi0uOTMuODEtMS44MywxLjY2LTIuNzEsMi41NGwtNDQuMDYsNDQuMDcsNDQuMDcsNDQuMDdjLjkuOSwxLjgzLDEuNzcsMi43NywyLjYxLDkuMTQsOC4wOCwyMC4wNiwxMy4wNCwzMS4zOSwxNC44NFoiLz4KICAgIDwvZz4KICA8L2c+Cjwvc3ZnPg==';

	/**
	 * 與終極電商互相 fallback 決定「快捷鍵」父選單 slug（v1.37.0 新增）。
	 *
	 * 兩邊都各自嘗試建立父選單、用 $admin_page_hooks 判斷是否已存在，不依賴載入順序。
	 * 見終極電商 includes/admin/menus.php 的 twshop_shortcut_parent_slug()（鏡射邏輯）
	 * 與兩邊 CLAUDE.md「快捷鍵父選單合併」一節。
	 */
	public static function shortcut_parent_slug() {
		global $admin_page_hooks;
		if ( isset( $admin_page_hooks['wc-general-settings'] ) ) {
			return 'wc-general-settings';
		}
		return 'wclon-settings';
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_module_settings' ) );
		add_action( 'wp_ajax_wclon_test_push', array( __CLASS__, 'ajax_test_push' ) );
		// 儲存後清除靜態快取，確保同次請求取得最新值
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_cache' ) );
		add_action( 'update_option_' . self::MODULE_OPTION_KEY, array( __CLASS__, 'flush_module_cache' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_shortcode( 'wclon_social_bar', array( __CLASS__, 'render_social_bar' ) );
	}

	// ─── 模組開關（v1.36.0 新增） ───────────────────────────────────────────
	//
	// 範圍刻意比照使用者的分法，不是逐頁籤一一對應：LINE／Google／Apple 三個登入 provider
	// 合併成一個「社交登入」開關（一起開、一起關，三者都是同一件事——讓顧客用社群帳號登入）；
	// 「顧客通知」（WCLON_Notifier）與「管理員通知」（WCAN_*）合併成一個「訂單通知」開關
	// （兩者都是「訂單發生變化時推播 LINE」，差別只是推給顧客本人還是店家群組）；「系統信件」
	// （WCLON_System_Email_Settings）獨立一個開關。Turnstile／更新檢查器／設定頁本身維持
	// 「基本功能」，不在模組開關的管轄範圍內——Turnstile 已經有自己的 enabled 開關＋金鑰兩把
	// 都填了才生效的判斷（is_active()），沒有必要疊床架屋再包一層模組開關。

	/**
	 * 模組定義：key 對應下面存進 wclon_module_settings 的欄位、也對應
	 * ultimate-login.php 主檔案 plugins_loaded 時判斷要不要呼叫對應 class 的 ::init()。
	 */
	public static function get_module_definitions() {
		return array(
			'social_login' => array(
				'label' => '社交登入',
				'desc'  => 'LINE／Google／Apple 登入、註冊、帳號綁定。關閉後「LINE」「Google」「Apple」頁籤、前台社交登入按鈕、會員中心「帳號綁定」的綁定功能整個不會出現。',
			),
			'order_notify' => array(
				'label' => '訂單通知',
				'desc'  => '訂單狀態、備注、物流狀態推播給下單顧客本人；新訂單推播給管理員/員工共用的 LINE 群組。關閉後「顧客通知」「管理員通知」頁籤整個不會出現。',
			),
			'system_email' => array(
				'label' => '系統信件',
				'desc'  => '停用 WordPress 核心與外掛自動更新寄給管理員的通知信。關閉後「系統信件」頁籤整個不會出現。',
			),
		);
	}

	/**
	 * 沒有存過設定時預設全部啟用——既有站台升級後行為不變，不能比照終極電商後來改成的
	 * 「預設關閉」（那是給全新安裝的乾淨體驗設計的，對已經在用這些功能的既有站台是功能倒退
	 * 風險，終極電商自己的 CLAUDE.md 也把這個改動標記為未處理的升級風險）。
	 */
	public static function module_enabled( $module ) {
		if ( null === self::$module_cache ) {
			self::$module_cache = get_option( self::MODULE_OPTION_KEY, array() );
		}
		return ( self::$module_cache[ $module ] ?? '1' ) === '1';
	}

	public static function flush_module_cache() {
		self::$module_cache = null;
	}

	public static function register_module_settings() {
		register_setting( 'wclon_module_settings_group', self::MODULE_OPTION_KEY, array( __CLASS__, 'sanitize_modules' ) );
	}

	public static function sanitize_modules( $input ) {
		$clean = array();
		foreach ( array_keys( self::get_module_definitions() ) as $module ) {
			$clean[ $module ] = ! empty( $input[ $module ] ) ? '1' : '0';
		}
		return $clean;
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( ! self::$page_hook || self::$page_hook !== $hook ) {
			return;
		}
		// 先載入 WooCommerce 後台樣式，按鈕與表格沿用原生元件；本外掛 CSS 只補頁面佈局。
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'wclon-frontend', WCLON_PLUGIN_URL . 'assets/css/wclon-frontend.css', array(), WCLON_VERSION );
		wp_enqueue_style( 'wclon-admin', WCLON_PLUGIN_URL . 'assets/css/wclon-admin.css', array( 'woocommerce_admin_styles' ), filemtime( WCLON_PLUGIN_DIR . 'assets/css/wclon-admin.css' ) );
		// WordPress 內建色票選擇器（Iris），供 Flex Message 標題色欄位使用：比原生 <input type="color">
		// 多一個可直接輸入/貼上色號的文字欄位，不用額外引入第三方函式庫或自己刻一個。
		wp_enqueue_style( 'wp-color-picker' );
		// 設定頁的互動腳本（頁籤、預覽、合併儲存、測試推播），v1.39.0 前是頁面裡的內嵌 <script>
		wp_enqueue_script( 'wclon-admin', WCLON_PLUGIN_URL . 'assets/js/wclon-admin.js', array( 'jquery', 'wp-color-picker' ), filemtime( WCLON_PLUGIN_DIR . 'assets/js/wclon-admin.js' ), true );
		wp_localize_script( 'wclon-admin', 'wclonAdmin', array(
			'siteName'  => get_bloginfo( 'name' ),
			'nonce'     => wp_create_nonce( 'wclon_admin_action' ),
			'wcanNonce' => wp_create_nonce( 'wcan_admin_action' ),
		) );
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * 全域按鈕形狀 modifier class（配色與版型已固定，僅形狀可調）
	 */
	public static function get_shape_class() {
		$shape = self::get( 'btn_shape', 'rounded' );
		if ( ! in_array( $shape, array( 'rounded', 'pill', 'square' ), true ) ) {
			$shape = 'rounded';
		}
		return ' wclon-btn--' . $shape;
	}

	/**
	 * 全域按鈕配色 modifier（'light' 或 'dark'）。LINE 官方僅提供綠色一種按鈕，不受此設定影響；
	 * Google / Apple 各自的官方按鈕素材都有淺色／深色兩種變體，跟著此設定切換。
	 */
	public static function get_color_scheme() {
		$scheme = self::get( 'btn_color_scheme', 'light' );
		return in_array( $scheme, array( 'light', 'dark' ), true ) ? $scheme : 'light';
	}

	/**
	 * 全域按鈕版型（'full' 全寬含文字 或 'icon' 純 ICON，不顯示文字）。
	 */
	public static function get_layout() {
		$layout = self::get( 'btn_layout', 'full' );
		return in_array( $layout, array( 'full', 'icon' ), true ) ? $layout : 'full';
	}

	/**
	 * 純 ICON 版型的水平對齊（'center' 預設＝置中 / 'left'＝靠左），v1.31.0 新增。
	 *
	 * **只對 `btn_layout=icon` 有意義**：全寬版型的按鈕本來就撐滿整列，沒有剩餘空間可以對齊。
	 * 設定值仍然照存照回傳（不因為目前版型是 full 就強制回 center），這樣管理員先調對齊、
	 * 之後再切成純 ICON 時，看到的會是自己選過的那個值。
	 */
	public static function get_align() {
		$align = self::get( 'btn_align', 'center' );
		return in_array( $align, array( 'left', 'center' ), true ) ? $align : 'center';
	}

	/**
	 * 社交按鈕相對於帳密表單的位置（'below' 預設＝表單下方 / 'above'＝表單上方），v1.29.0 新增。
	 */
	public static function get_btn_position() {
		$position = self::get( 'btn_position', 'below' );
		return in_array( $position, array( 'above', 'below' ), true ) ? $position : 'below';
	}

	/**
	 * 依「社交按鈕位置」設定回傳該渲染在哪個 hook 上（v1.29.0 新增）。
	 *
	 * 三個登入 class 與主檔案的 wrapper 全部改用這個方法取得 hook 名稱，位置設定才會一次
	 * 套用到所有輸出點，不會有某個 class 漏改而按鈕跑到奇怪位置的情況。
	 *
	 * | context       | below（預設）                    | above                          |
	 * |---------------|----------------------------------|--------------------------------|
	 * | `wp_login`    | `login_form`                     | `wclon_wp_login_form_top`      |
	 * | `wc_login`    | `woocommerce_login_form_end`     | `woocommerce_login_form_start` |
	 * | `wc_register` | `woocommerce_register_form_end`  | `woocommerce_register_form_start` |
	 *
	 * `wclon_wp_login_form_top` 是本外掛自訂的 action：wp-login.php 的表單裡**沒有**任何位於
	 * 帳號欄位之前的 hook（`login_form` 固定在密碼欄之後、登入按鈕之前，是 WP 核心寫死的版面），
	 * 所以「上方」只能改用 `login_message` filter——它輸出的位置就在 `<form id="loginform">`
	 * 正上方。主檔案在 above 模式下掛那個 filter、用 output buffer 接住這個自訂 action 的輸出
	 * （見主檔案的橋接註解）。三個 class 因此不必各自去分辨「現在是 action 還是 filter」。
	 *
	 * WooCommerce 那兩組的 `_start` 版本，WooCommerce 自己的 `myaccount/form-login.php` 與
	 * Blocksy 標頭彈窗（`blocksy-companion/framework/features/header/modal/login.php` 第 36 行、
	 * `register.php` 第 47 行）都有觸發，兩種來源一起涵蓋。
	 *
	 * @param string $context `wp_login`｜`wc_login`｜`wc_register`
	 */
	public static function social_hook( $context ) {
		$above = ( 'above' === self::get_btn_position() );
		switch ( $context ) {
			case 'wp_login':
				return $above ? 'wclon_wp_login_form_top' : 'login_form';
			case 'wc_login':
				return $above ? 'woocommerce_login_form_start' : 'woocommerce_login_form_end';
			case 'wc_register':
				return $above ? 'woocommerce_register_form_start' : 'woocommerce_register_form_end';
		}
		return '';
	}

	/**
	 * 檢查社群帳號的 email 是否與指定會員目前的 email 不同。
	 * 任一方缺少可比對的 email（含系統產生的佔位 email `xxx@noemail.invalid`）時視為不衝突，放行綁定。
	 */
	public static function email_conflicts( $social_email, $user_id ) {
		if ( ! $social_email ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email || str_contains( $user->user_email, '@noemail.invalid' ) ) {
			return false;
		}
		return 0 !== strcasecmp( $social_email, $user->user_email );
	}

	/**
	 * 購物車／結帳頁的社交綁定列。**v1.30.0 起拆成兩個獨立區塊**：
	 *
	 *   1. LINE 區（`render_line_block()`）——通知導向。只有 LINE 綁定同時是訂單通知管道
	 *      （`_wclon_line_notify_enabled` + `WCLON_Notifier` 推播），Google / Apple 完全沒有
	 *      通知功能，所以改版前那句共用標籤「綁定社交帳號，即時接收訂單通知」對三分之二的
	 *      平台是錯的。已綁定 LINE 的顧客整區隱藏（那句行動呼籲對他們已經沒有意義）。
	 *   2. Google / Apple 區（`render_social_block()`）——單純的快速登入／註冊。
	 *
	 * **這是結帳頁、購物車頁、`[wclon_social_bar]` 短代碼三條路徑共用的唯一輸出點**：改版前
	 * 結帳頁那份 markup 與標籤字串是直接寫死在主檔案的 hook 裡、與這裡逐字重複，改文案時
	 * 得記得兩邊一起改才不會走鐘。現在主檔案只呼叫這個方法。
	 *
	 * 各平台的 `render_checkout_chip()` 用 `$_SERVER['REQUEST_URI']` 動態取得目前網址當作 OAuth
	 * 完成後的導回目標，所以不限定要在結帳頁使用，放在購物車頁時會導回購物車頁。
	 */
	public static function render_social_bar() {
		$out = self::render_line_block() . self::render_social_block();
		if ( '' === $out ) {
			return ''; // 兩塊都空時回傳空字串，不會留下一個空的 group 容器
		}
		// v1.31.3：包一層 group 容器，讓 CSS 有單一的 flex 容器可以在桌機把兩塊排成一排
		// （見 wclon-frontend.css 的 .wclon-checkout-bar-group），手機寬度不夠時維持疊放。
		return '<div class="wclon-checkout-bar-group">' . $out . '</div>';
	}

	/**
	 * 這條列到底會不會輸出東西（不組 HTML 的輕量版）。
	 *
	 * 給主檔案的 `pre_option_woocommerce_enable_checkout_login_reminder` filter 用：只有真的
	 * 有東西可以讓顧客登入時，才該把 WooCommerce 原生的「老客戶？點擊登入」提示藏起來。
	 * **不要改成「呼叫 render_social_bar() 看看是不是空字串」**——那會讓同一次請求把整條列
	 * 組兩遍。
	 */
	public static function has_social_bar_content() {
		return self::line_block_visible() || self::social_block_visible();
	}

	private static function line_block_visible() {
		if ( ! class_exists( 'WCLON_Line_Login' ) || ! self::get( 'login_channel_id' ) ) {
			return false;
		}
		// 已綁定就整區隱藏。get_current_line_user_id() 除了會員 meta 也看 WC session，
		// 所以「OAuth 走到一半、還沒建帳號」的訪客同樣算已綁定（正確：他們馬上就會被登入）。
		return ! WCLON_Line_Login::get_current_line_user_id();
	}

	private static function social_block_visible() {
		return (bool) ( self::get( 'google_client_id' ) || self::get( 'apple_client_id' ) );
	}

	public static function default_line_bar_title() {
		return '訂單狀態，用 LINE 即時通知你';
	}

	public static function default_social_bar_title() {
		return '快速登入或註冊';
	}

	/**
	 * LINE 區：通知導向，已綁定時整區不輸出。
	 *
	 * 與 Google/Apple 區一樣用小 chip——開發過程中曾做成「大按鈕／小 chip」可選（預設大按鈕），
	 * 但實際看過版面後決定不要，選項與大按鈕的程式碼都已移除，兩區的按鈕樣式維持一致，
	 * 差別只在標題文案與「已綁定就隱藏」這個行為。
	 */
	private static function render_line_block() {
		if ( ! self::line_block_visible() ) {
			return '';
		}
		$chip = WCLON_Line_Login::render_checkout_chip();
		if ( '' === trim( (string) $chip ) ) {
			return ''; // 平台端自己判斷不輸出時（例如憑證被清空），不要留一個只有標題的空盒子
		}

		return '<div class="wclon-checkout-bar wclon-checkout-bar--line">'
			. '<span class="wclon-checkout-bar__label">' . esc_html( self::get( 'line_bar_title', self::default_line_bar_title() ) ) . '</span>'
			. '<span class="wclon-checkout-bar__chips">' . $chip . '</span>'
			. '</div>';
	}

	/** Google / Apple 區：單純的快速登入，沒有通知功能，文案刻意與 LINE 區分開 */
	private static function render_social_block() {
		if ( ! self::social_block_visible() ) {
			return '';
		}
		$chips = '';
		if ( class_exists( 'WCLON_Google_Login' ) ) {
			$chips .= WCLON_Google_Login::render_checkout_chip();
		}
		if ( class_exists( 'WCLON_Apple_Login' ) ) {
			$chips .= WCLON_Apple_Login::render_checkout_chip();
		}
		if ( '' === trim( $chips ) ) {
			return '';
		}

		return '<div class="wclon-checkout-bar wclon-checkout-bar--social">'
			. '<span class="wclon-checkout-bar__label">' . esc_html( self::get( 'social_bar_title', self::default_social_bar_title() ) ) . '</span>'
			. '<span class="wclon-checkout-bar__chips">' . $chips . '</span>'
			. '</div>';
	}

	/**
	 * 簡易樣板變數替換：{key} 換成 $vars['key']。所有可自訂文案（問候語/標題/說明句等）
	 * 共用同一套替換邏輯，避免每個推播訊息各自寫一次 str_replace。$vars 裡沒對應到的
	 * {xxx} token 會原樣保留，方便管理員發現自己打錯變數名稱（不會被靜默清空）。
	 */
	public static function render_template( $template, array $vars ) {
		$replacements = array();
		foreach ( $vars as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = (string) $value;
		}
		return strtr( (string) $template, $replacements );
	}

	/**
	 * 折扣數值的顯示文字：百分比類型顯示「N%」，固定金額類型用 wc_price() 格式化。
	 * LINE 綁定歡迎優惠券、購物車棄單提醒優惠券共用同一套格式，避免各自實作出現落差。
	 */
	public static function format_coupon_amount( $type, $amount ) {
		$amount = (float) $amount;
		return 'percent' === $type
			? rtrim( rtrim( sprintf( '%.2f', $amount ), '0' ), '.' ) . '%'
			: html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
	}

	/**
	 * 建立一張限定指定會員使用一次的優惠券（LINE 綁定歡迎優惠券、購物車棄單提醒優惠券共用）。
	 *
	 * 同時寫入 twshop 外掛認得的 `_visual_coupon_title` / `_visual_coupon_desc` post meta——
	 * twshop 的「視覺化優惠券」模組（`twshop_auto_display_coupons()`）用 `_visual_coupon_title`
	 * 是否存在判斷要不要把優惠券列進會員中心「專屬優惠券」頁面，這是本站唯一的「我的優惠券」前台
	 * 入口（WooCommerce 核心本身沒有對應功能）。沒裝 twshop 或未啟用該模組時，這兩筆 meta 單純
	 * 不會被讀取，無副作用。詳見 `twshop/CLAUDE.md`「_visual_coupon_title 被外部外掛依賴」。
	 *
	 * @param int    $user_id
	 * @param string $code_prefix  優惠券代碼前綴（例如 'LINE'、'CART'），會接上隨機字串組成完整代碼
	 * @param string $type         'fixed_cart' | 'percent'
	 * @param float  $amount
	 * @param int    $expiry_days  幾天後過期，0 = 不過期
	 * @param float  $min_spend    最低消費金額，0 = 無限制
	 * @param string $visual_title twshop 視覺化優惠券標題
	 * @param string $visual_desc  twshop 視覺化優惠券說明
	 * @param string $internal_desc WC 優惠券本身的後台描述欄位（非必填）
	 * @return WC_Coupon|null
	 */
	public static function create_customer_coupon(
		$user_id,
		$code_prefix,
		$type,
		$amount,
		$expiry_days,
		$min_spend,
		$visual_title,
		$visual_desc,
		$internal_desc = ''
	) {
		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return null;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$code = $code_prefix . strtoupper( wp_generate_password( 8, false, false ) );
		while ( wc_get_coupon_id_by_code( $code ) ) {
			$code = $code_prefix . strtoupper( wp_generate_password( 8, false, false ) );
		}

		$type = 'percent' === $type ? 'percent' : 'fixed_cart';

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $type );
		$coupon->set_amount( $amount );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		if ( $internal_desc ) {
			$coupon->set_description( $internal_desc );
		}

		// email 是系統產生的佔位符（例如 LINE 快速註冊未取得真實 email）時不設限制，避免顧客
		// 之後補填真實 email 後反而用不了自己的優惠券。
		if ( $user->user_email && ! str_contains( $user->user_email, '@noemail.invalid' ) ) {
			$coupon->set_email_restrictions( array( $user->user_email ) );
		}

		if ( $expiry_days > 0 ) {
			$coupon->set_date_expires( time() + (int) $expiry_days * DAY_IN_SECONDS );
		}
		if ( $min_spend > 0 ) {
			$coupon->set_minimum_amount( (float) $min_spend );
		}

		$coupon->update_meta_data( '_visual_coupon_title', $visual_title );
		$coupon->update_meta_data( '_visual_coupon_desc', $visual_desc );

		$coupon->save();

		return $coupon->get_id() ? $coupon : null;
	}

	public static function get_btn_classes( $provider = 'line' ) {
		// 形狀、版型（全寬含文字 / 純 ICON）跟隨全域設定；配色（Google / Apple）跟隨全域配色設定，LINE 固定綠色
		$dark = 'dark' === self::get_color_scheme();
		if ( 'google' === $provider ) {
			$color = $dark ? 'google-dark' : 'google-light';
		} elseif ( 'apple' === $provider ) {
			$color = $dark ? 'apple-white' : 'apple-black';
		} else {
			$color = 'green';
		}
		return ' wclon-btn--' . $color . self::get_shape_class() . ' wclon-btn--' . self::get_layout()
			. ' wclon-btn--align-' . self::get_align();
	}

	public static function get( $key, $default = '' ) {
		if ( self::$cache === null ) {
			self::$cache = get_option( self::OPTION_KEY, array() );
		}
		$value = self::$cache[ $key ] ?? null;
		return ( $value !== null && $value !== '' ) ? $value : $default;
	}

	/**
	 * v1.24.0 起改為**頂層選單**（原本掛在 WooCommerce 底下的子選單）。
	 *
	 * 頁面 slug（`wclon-settings`）與網址（`admin.php?page=wclon-settings`）都沒有變動，
	 * 既有的書籤／連結不受影響——add_menu_page() 與 add_submenu_page() 產生的網址格式相同。
	 * 選單位置 56.5 是刻意的：WooCommerce 約在 55.5、同站的 wc-marketing-automation 佔了 56，
	 * 用小數插在它後面既不會蓋掉別人（同一個整數位置會互相覆蓋），視覺上也跟電商相關的選單排在一起。
	 */
	public static function add_menu() {
		$parent_slug = self::shortcut_parent_slug();
		$is_owner    = ( 'wclon-settings' === $parent_slug );

		if ( $is_owner ) {
			add_menu_page(
				'快捷鍵', '快捷鍵', 'manage_woocommerce', $parent_slug,
				array( __CLASS__, 'render_page' ), self::SHORTCUT_ICON, 56
			);
			remove_submenu_page( $parent_slug, $parent_slug );
		}

		// 不管是不是 owner，這一行都要執行——這是終極登入唯一的子選單項目。owner 情境下，
		// 這是第一筆 add_submenu_page(slug===parent)，避免 WordPress 自動插入重複項目；
		// attach 情境下，這是掛在終極電商父選單底下的普通一筆。絕對不能在這裡呼叫
		// remove_submenu_page( $parent_slug, $parent_slug )——那會刪掉 owner（終極電商）
		// 的第一筆子選單「儀表板」。
		self::$page_hook = add_submenu_page(
			$parent_slug, '終極登入', '終極登入', 'manage_woocommerce',
			'wclon-settings', array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'wclon_settings_group', self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
	}

	public static function sanitize( $input ) {
		$clean                        = array();
		$clean['channel_access_token'] = trim( sanitize_text_field( $input['channel_access_token'] ?? '' ) );
		$clean['login_channel_id']     = trim( sanitize_text_field( $input['login_channel_id'] ?? '' ) );
		$clean['login_channel_secret'] = trim( sanitize_text_field( $input['login_channel_secret'] ?? '' ) );
		// 授權網址是否加上 prompt=consent（強制重新顯示同意畫面，見設定頁說明與 CLAUDE.md 踩坑記錄）
		$clean['line_force_consent']   = ! empty( $input['line_force_consent'] ) ? 1 : 0;

		// LINE 綁定歡迎優惠券
		$clean['line_bind_coupon_enabled'] = ! empty( $input['line_bind_coupon_enabled'] ) ? 1 : 0;
		$allowed_coupon_types              = array( 'fixed_cart', 'percent' );
		$clean['line_bind_coupon_type']    = in_array( $input['line_bind_coupon_type'] ?? '', $allowed_coupon_types, true )
			? $input['line_bind_coupon_type']
			: 'fixed_cart';
		$clean['line_bind_coupon_amount']      = max( 0, (float) ( $input['line_bind_coupon_amount'] ?? 0 ) );
		if ( 'percent' === $clean['line_bind_coupon_type'] ) {
			$clean['line_bind_coupon_amount'] = min( 100, $clean['line_bind_coupon_amount'] ); // 百分比折扣最多 100%
		}
		$clean['line_bind_coupon_expiry_days'] = max( 0, (int) ( $input['line_bind_coupon_expiry_days'] ?? 30 ) );
		$clean['line_bind_coupon_min_spend']   = max( 0, (float) ( $input['line_bind_coupon_min_spend'] ?? 0 ) );
		$clean['statuses']             = isset( $input['statuses'] ) && is_array( $input['statuses'] )
			? array_map( 'sanitize_text_field', $input['statuses'] )
			: array();
		// 社交按鈕顯示位置（全域，套用至所有登入平台）
		$clean['show_on_checkout']     = ! empty( $input['show_on_checkout'] ) ? 1 : 0;
		$clean['show_on_cart']         = ! empty( $input['show_on_cart'] ) ? 1 : 0;
		$clean['show_on_myaccount']    = ! empty( $input['show_on_myaccount'] ) ? 1 : 0;

		// 購物車／結帳頁綁定列（v1.30.0：LINE 與 Google/Apple 拆成兩區，文案各自可自訂）
		// 清空欄位時回退到預設字串，避免前台出現只有按鈕、沒有標題的裸區塊
		$clean['line_bar_title']   = sanitize_text_field( $input['line_bar_title'] ?? '' ) ?: self::default_line_bar_title();
		$clean['social_bar_title'] = sanitize_text_field( $input['social_bar_title'] ?? '' ) ?: self::default_social_bar_title();

		$clean['note_notify']          = ! empty( $input['note_notify'] ) ? 1 : 0;
		$clean['logistics_notify_enabled'] = ! empty( $input['logistics_notify_enabled'] ) ? 1 : 0;
		$clean['notify_customer_enabled'] = ! empty( $input['notify_customer_enabled'] ) ? 1 : 0;

		// Google 登入
		$clean['google_client_id']         = trim( sanitize_text_field( $input['google_client_id'] ?? '' ) );
		$clean['google_client_secret']     = trim( sanitize_text_field( $input['google_client_secret'] ?? '' ) );

		// 按鈕外觀（形狀、配色、版型皆可統一調整；LINE 配色固定不受影響）
		$allowed_shapes     = array( 'rounded', 'pill', 'square' );
		$clean['btn_shape'] = in_array( $input['btn_shape'] ?? '', $allowed_shapes, true ) ? $input['btn_shape'] : 'rounded';
		$allowed_schemes           = array( 'light', 'dark' );
		$clean['btn_color_scheme'] = in_array( $input['btn_color_scheme'] ?? '', $allowed_schemes, true ) ? $input['btn_color_scheme'] : 'light';
		$allowed_layouts     = array( 'full', 'icon' );
		$clean['btn_layout'] = in_array( $input['btn_layout'] ?? '', $allowed_layouts, true ) ? $input['btn_layout'] : 'full';
		$allowed_positions     = array( 'above', 'below' );
		$clean['btn_position'] = in_array( $input['btn_position'] ?? '', $allowed_positions, true ) ? $input['btn_position'] : 'below';
		$allowed_aligns     = array( 'left', 'center' );
		$clean['btn_align'] = in_array( $input['btn_align'] ?? '', $allowed_aligns, true ) ? $input['btn_align'] : 'center';

		// Apple 登入
		$clean['apple_client_id']          = trim( sanitize_text_field( $input['apple_client_id'] ?? '' ) );
		$clean['apple_team_id']            = trim( sanitize_text_field( $input['apple_team_id'] ?? '' ) );
		$clean['apple_key_id']             = trim( sanitize_text_field( $input['apple_key_id'] ?? '' ) );
		$clean['apple_private_key']        = trim( sanitize_textarea_field( $input['apple_private_key'] ?? '' ) );

		// Flex Message 樣式
		$raw_color = sanitize_hex_color( $input['header_color'] ?? '' );
		$clean['header_color'] = $raw_color ?: '#00C300';
		$raw_note_color = sanitize_hex_color( $input['note_color'] ?? '' );
		$clean['note_color']   = $raw_note_color ?: '#FF9800';
		$clean['button_text']  = sanitize_text_field( $input['button_text'] ?? '查看訂單詳情' ) ?: '查看訂單詳情';

		// 訂單狀態／備注通知的可自訂文案（{customer_name}／{site_name} 可用）
		$clean['greeting_template'] = sanitize_text_field( $input['greeting_template'] ?? '' ) ?: '您好，{customer_name}！';
		$clean['note_title']        = sanitize_text_field( $input['note_title'] ?? '' ) ?: '店家留言';
		$clean['logistics_title']   = sanitize_text_field( $input['logistics_title'] ?? '' ) ?: '🚚 物流狀態更新';

		// LINE 綁定歡迎優惠券的可自訂文案（{site_name} 可用）
		$clean['bind_coupon_title']       = sanitize_text_field( $input['bind_coupon_title'] ?? '' ) ?: '🎁 專屬優惠券';
		$clean['bind_coupon_greeting']    = sanitize_text_field( $input['bind_coupon_greeting'] ?? '' ) ?: '感謝您綁定 LINE 帳號！';
		$clean['bind_coupon_desc']        = sanitize_textarea_field( $input['bind_coupon_desc'] ?? '' ) ?: '結帳時輸入上方代碼即可折抵，僅限本人帳號使用一次。';
		$clean['bind_coupon_button_text'] = sanitize_text_field( $input['bind_coupon_button_text'] ?? '' ) ?: '前往購物';

		return $clean;
	}

	// ─── 測試推播 AJAX ──────────────────────────────────────────────────────

	public static function ajax_test_push() {
		check_ajax_referer( 'wclon_admin_action', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => '權限不足。' ) );
		}

		$line_user_id = sanitize_text_field( wp_unslash( $_POST['line_user_id'] ?? '' ) );

		// 若未填，嘗試用目前登入的管理員綁定帳號
		if ( ! $line_user_id ) {
			$line_user_id = (string) get_user_meta( get_current_user_id(), WCLON_Line_Login::USER_META_KEY, true );
		}

		if ( ! $line_user_id ) {
			wp_send_json_error( array( 'message' => '請輸入 LINE User ID，或先在前台綁定您自己的帳號。' ) );
		}

		$result = WCLON_Notifier::test_push( $line_user_id );
		if ( true === $result ) {
			wp_send_json_success( array( 'message' => '✓ 測試訊息已發送，請至 LINE 確認。' ) );
		} else {
			wp_send_json_error( array( 'message' => $result ) );
		}
	}

	// ─── 設定頁渲染 ─────────────────────────────────────────────────────────

	public static function render_page() {
		$statuses            = wc_get_order_statuses();
		$selected_statuses   = (array) self::get( 'statuses', array( 'wc-processing' ) );
		$callback_url        = home_url( '/?wclon_action=callback' );
		$google_callback_url = home_url( '/?wclon_action=google_callback' );
		$header_color        = self::get( 'header_color', '#00C300' );
		$note_color          = self::get( 'note_color', '#FF9800' );
		$button_text         = self::get( 'button_text', '查看訂單詳情' );
		$greeting_template   = self::get( 'greeting_template', '您好，{customer_name}！' );
		$note_title          = self::get( 'note_title', '店家留言' );
		$logistics_title     = self::get( 'logistics_title', '🚚 物流狀態更新' );
		$bind_coupon_title       = self::get( 'bind_coupon_title', '🎁 專屬優惠券' );
		$bind_coupon_greeting    = self::get( 'bind_coupon_greeting', '感謝您綁定 LINE 帳號！' );
		$bind_coupon_desc        = self::get( 'bind_coupon_desc', '結帳時輸入上方代碼即可折抵，僅限本人帳號使用一次。' );
		$bind_coupon_button_text = self::get( 'bind_coupon_button_text', '前往購物' );
		$btn_shape           = self::get( 'btn_shape', 'rounded' );
		$btn_color_scheme    = self::get_color_scheme();
		$btn_layout          = self::get_layout();
		$btn_position        = self::get_btn_position();
		$btn_align           = self::get_align();
		$my_line_id          = (string) get_user_meta( get_current_user_id(), WCLON_Line_Login::USER_META_KEY, true );

		$coupon_enabled   = self::get( 'line_bind_coupon_enabled' );
		$coupon_type      = self::get( 'line_bind_coupon_type', 'fixed_cart' );
		$coupon_amount    = self::get( 'line_bind_coupon_amount', 100 );
		$coupon_expiry    = self::get( 'line_bind_coupon_expiry_days', 30 );
		$coupon_min_spend = self::get( 'line_bind_coupon_min_spend', 0 );

		$apple_callback_url   = home_url( '/?wclon_action=apple_callback' );
		$apple_host           = parse_url( home_url(), PHP_URL_HOST );
		$apple_reverse_domain = implode( '.', array_reverse( explode( '.', $apple_host ) ) );

		$line_svg   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="#ffffff" aria-hidden="true"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>';
		$apple_svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>';
		$google_svg_color = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>';

		// 模組開關：關閉的模組，對應頁籤在下方 nav 與 pane 都不會輸出（見「模組」頁籤與
		// WCLON_Settings::module_enabled()）。LINE／Google／Apple／顧客通知的 pane 即使頁籤被
		// 隱藏也**仍然照常渲染**（只是沒有 nav 連結指向、使用者到不了）——因為它們的欄位跟「一般設定」
		// 同屬一個 <form>／同一個 wclon_settings option，若整段不渲染，儲存「一般設定」時這些欄位
		// 會從 $_POST 裡消失，sanitize() 會把它們當成使用者清空、寫回空字串，等於靜默清掉已存的
		// LINE/Google/Apple 憑證。管理員通知／系統信件是各自獨立的 <form>／option，沒有這個風險，
		// 才整段不渲染。
		$mod_social  = self::module_enabled( 'social_login' );
		$mod_notify  = self::module_enabled( 'order_notify' );
		$mod_sysmail = self::module_enabled( 'system_email' );
		// 模組開關比照終極電商，僅限 Administrator（manage_options）操作：模組開關會整批啟用/
		// 停用外掛功能，影響範圍比其餘設定頁（皆為 manage_woocommerce）大，不該讓 Shop Manager
		// 這類角色碰。「模組」頁籤的表單本身走標準的 register_setting()/options.php（見
		// register_module_settings()），WordPress 核心的 options.php 預設就要求 manage_options
		// 才能送出（跟本外掛其餘表單一樣，這是 WP 核心一直都有的行為，不是這次才加的保護）；
		// 這裡額外控制的是「畫面看不看得到」——沒有 manage_options 的角色乾脆連頁籤都看不到，
		// 不會出現「點得進去、填了勾選、按下儲存卻被告知權限不足」這種體驗。
		$can_manage_modules = current_user_can( 'manage_options' );
		$can_manage_license = current_user_can( 'manage_options' );
		$tab_groups = array(
			'general' => array( 'label' => '一般設定', 'tabs' => array( 'general' => '一般設定' ) ),
		);
		if ( $mod_social ) {
			$tab_groups['social'] = array( 'label' => '社交登入', 'tabs' => array( 'line' => 'LINE', 'google' => 'Google', 'apple' => 'Apple' ) );
		}
		if ( $mod_notify ) {
			$tab_groups['notifications'] = array( 'label' => '通知', 'tabs' => array( 'customer' => '顧客通知', 'adminline' => '管理員通知' ) );
		}
		$tab_groups['security'] = array( 'label' => '安全性', 'tabs' => array( 'turnstile' => 'Turnstile' ) );
		$system_tabs = array();
		if ( $can_manage_license ) $system_tabs['license'] = '授權';
		if ( $mod_sysmail ) $system_tabs['sysmail'] = '系統信件';
		if ( $can_manage_modules ) $system_tabs['modules'] = '模組';
		if ( $system_tabs ) {
			$tab_groups['system'] = array( 'label' => '系統設定', 'tabs' => $system_tabs );
		}
		?>
		<div class="wrap wclon-admin-wrap">
			<div class="wclon-admin-header">
				<h1>終極登入</h1>
			</div>

			<nav class="nav-tab-wrapper" aria-label="終極登入功能分類">
				<?php foreach ( $tab_groups as $group_id => $group ) : $default_tab = array_key_first( $group['tabs'] ); ?>
				<a href="#<?php echo esc_attr( $default_tab ); ?>" class="nav-tab<?php echo 'general' === $group_id ? ' nav-tab-active' : ''; ?>" data-wclon-group="<?php echo esc_attr( $group_id ); ?>" data-wclon-default-tab="<?php echo esc_attr( $default_tab ); ?>"><?php echo esc_html( $group['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php foreach ( $tab_groups as $group_id => $group ) : ?>
			<nav class="wclon-subtabs<?php echo count( $group['tabs'] ) < 2 ? ' is-single' : ''; ?>" data-wclon-subtabs="<?php echo esc_attr( $group_id ); ?>" aria-label="<?php echo esc_attr( $group['label'] ); ?>設定">
				<ul class="subsubsub">
					<?php foreach ( $group['tabs'] as $tab_id => $tab_label ) : ?>
					<li><a href="#<?php echo esc_attr( $tab_id ); ?>" data-wclon-tab="<?php echo esc_attr( $tab_id ); ?>" data-wclon-tab-group="<?php echo esc_attr( $group_id ); ?>"><?php echo esc_html( $tab_label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<?php endforeach; ?>

			<?php WCLON_License::render_inline_notice(); ?>

			<form method="post" action="options.php" id="wclon-settings-form">
				<?php settings_fields( 'wclon_settings_group' ); ?>

				<!-- ══ 一般設定 Tab ══ -->
				<div id="wclon-tab-general" class="wclon-tab-pane" data-tab="general">
					<div class="wclon-card">
						<h2 class="wclon-card__title">社交按鈕顯示位置</h2>
						<p class="wclon-card__desc">套用至所有已啟用的登入平台（LINE / Google / Apple）。</p>
						<table class="form-table">
							<tr>
								<th scope="row">顯示於</th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_on_checkout]" value="1" <?php checked( self::get( 'show_on_checkout', 1 ), 1 ); ?>> 結帳頁</label><br>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_on_cart]" value="1" <?php checked( self::get( 'show_on_cart', 0 ), 1 ); ?>> 購物車頁（傳統短代碼版 <code>[woocommerce_cart]</code>）</label><br>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_on_myaccount]" value="1" <?php checked( self::get( 'show_on_myaccount', 1 ), 1 ); ?>> 會員中心「社交帳號綁定」頁</label>
									<p class="description">「購物車頁」僅套用於傳統短代碼版購物車（<code>[woocommerce_cart]</code>，掛在 <code>woocommerce_before_cart</code>）；區塊版購物車請改用下方短代碼手動放置。勾選「會員中心」後選單會多出「社交帳號綁定」獨立分頁。也可以在任何頁面使用短代碼 <code>[wclon_line_connect]</code>、<code>[wclon_google_connect]</code>、<code>[wclon_apple_connect]</code> 顯示個別完整版綁定按鈕；或用 <code>[wclon_social_bar]</code> 顯示跟結帳頁一樣的精簡 chip 列。</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">購物車／結帳頁綁定列</h2>
						<p class="wclon-card__desc">分別設定 LINE 綁定與 Google / Apple 登入區塊，套用於購物車、結帳頁及 <code>[wclon_social_bar]</code>。</p>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="wclon_line_bar_title">LINE 區塊標題</label></th>
								<td>
									<input type="text" id="wclon_line_bar_title" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[line_bar_title]" value="<?php echo esc_attr( self::get( 'line_bar_title', self::default_line_bar_title() ) ); ?>">
									<p class="description">留空會回到預設值「<?php echo esc_html( self::default_line_bar_title() ); ?>」。<strong>已經綁定 LINE 的顧客看不到這一區</strong>（行動已完成，不需要再佔結帳頁版面）。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_social_bar_title">Google / Apple 區塊標題</label></th>
								<td>
									<input type="text" id="wclon_social_bar_title" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[social_bar_title]" value="<?php echo esc_attr( self::get( 'social_bar_title', self::default_social_bar_title() ) ); ?>">
									<p class="description">留空會回到預設值「<?php echo esc_html( self::default_social_bar_title() ); ?>」。這兩個平台<strong>不提供訂單通知</strong>，文案刻意與 LINE 區分開，避免誤導顧客。兩者的憑證都沒填時整區不顯示。</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">按鈕外觀</h2>
						<p class="wclon-card__desc">調整社交登入按鈕的形狀與版型；配色僅影響 Google / Apple。</p>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="wclon_btn_shape">形狀</label></th>
								<td>
									<select id="wclon_btn_shape" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[btn_shape]">
										<option value="rounded" <?php selected( $btn_shape, 'rounded' ); ?>>圓角（預設）</option>
										<option value="pill"    <?php selected( $btn_shape, 'pill' ); ?>>膠囊形</option>
										<option value="square"  <?php selected( $btn_shape, 'square' ); ?>>直角</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_btn_color_scheme">配色</label></th>
								<td>
									<select id="wclon_btn_color_scheme" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[btn_color_scheme]">
										<option value="light" <?php selected( $btn_color_scheme, 'light' ); ?>>淺色背景頁面（預設，Google 白底／Apple 黑底）</option>
										<option value="dark"  <?php selected( $btn_color_scheme, 'dark' ); ?>>深色背景頁面（Google 深底／Apple 白底，維持對比避免看不清楚）</option>
									</select>
									<p class="description">依網站登入 / 註冊頁的背景深淺選擇，讓按鈕在背景上仍保有清楚的邊界與對比。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_btn_layout">版型</label></th>
								<td>
									<select id="wclon_btn_layout" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[btn_layout]">
										<option value="full" <?php selected( $btn_layout, 'full' ); ?>>全寬（預設，含文字）</option>
										<option value="icon" <?php selected( $btn_layout, 'icon' ); ?>>純 ICON（不顯示文字，適合空間有限的頁面）</option>
									</select>
									<p class="description">純 ICON 版型會隱藏按鈕文字，僅保留品牌圖示（螢幕閱讀器仍會讀出完整按鈕說明）。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_btn_align">對齊</label></th>
								<td>
									<select id="wclon_btn_align" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[btn_align]">
										<option value="center" <?php selected( $btn_align, 'center' ); ?>>置中（預設）</option>
										<option value="left"   <?php selected( $btn_align, 'left' ); ?>>靠左</option>
									</select>
									<p class="description"><strong>只影響「純 ICON」版型</strong>——全寬版型的按鈕本來就撐滿整列，沒有剩餘空間可以對齊。同時套用到登入／註冊表單的按鈕列與會員中心「綁定帳號」頁的按鈕。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_btn_position">位置</label></th>
								<td>
									<select id="wclon_btn_position" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[btn_position]">
										<option value="below" <?php selected( $btn_position, 'below' ); ?>>帳號密碼表單「下方」（預設）</option>
										<option value="above" <?php selected( $btn_position, 'above' ); ?>>帳號密碼表單「上方」</option>
									</select>
									<p class="description">同時套用到登入與註冊表單（WooCommerce 我的帳號、佈景主題 Blocksy 的標頭帳號彈出視窗、WordPress 登入頁三種來源）。選「上方」時「或使用其他方式」分隔線會自動改排在按鈕<strong>之後</strong>（緊接著帳號密碼欄位），符合一般「社群登入在上、分隔線、帳密表單在下」的版面慣例。<br>WordPress 登入頁（<code>wp-login.php</code>）比較特別：核心把表單內唯一可用的位置寫死在密碼欄之後，選「上方」時按鈕會輸出在整張表單的正上方（表單框外）。</p>
								</td>
							</tr>
							<tr>
								<th scope="row">預覽</th>
								<td>
									<div class="wclon-btn-preview wclon-btn-preview--<?php echo esc_attr( $btn_layout ); ?> wclon-btn-preview--align-<?php echo esc_attr( $btn_align ); ?>">
										<a class="wclon-preview-btn wclon-auth-btn wclon-btn--green wclon-btn--<?php echo esc_attr( $btn_shape ); ?> wclon-btn--<?php echo esc_attr( $btn_layout ); ?>"
										   href="#" onclick="return false;" data-provider="line" aria-label="用 LINE 登入">
											<?php echo $line_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<span class="wclon-btn-label">用 LINE 登入</span>
										</a>
										<a class="wclon-preview-btn wclon-auth-btn wclon-btn--<?php echo esc_attr( 'dark' === $btn_color_scheme ? 'google-dark' : 'google-light' ); ?> wclon-btn--<?php echo esc_attr( $btn_shape ); ?> wclon-btn--<?php echo esc_attr( $btn_layout ); ?>"
										   href="#" onclick="return false;" data-provider="google" aria-label="用 Google 登入">
											<?php echo $google_svg_color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<span class="wclon-btn-label">用 Google 登入</span>
										</a>
										<a class="wclon-preview-btn wclon-auth-btn wclon-btn--<?php echo esc_attr( 'dark' === $btn_color_scheme ? 'apple-white' : 'apple-black' ); ?> wclon-btn--<?php echo esc_attr( $btn_shape ); ?> wclon-btn--<?php echo esc_attr( $btn_layout ); ?>"
										   href="#" onclick="return false;" data-provider="apple" aria-label="用 Apple 登入">
											<?php echo $apple_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<span class="wclon-btn-label">用 Apple 登入</span>
										</a>
									</div>
									<p class="description">選項變更時即時更新；儲存後套用至前台。</p>
								</td>
							</tr>
						</table>
					</div>
				</div><!-- /wclon-tab-general -->

				<!-- ══ LINE Tab ══ -->
				<div id="wclon-tab-line" class="wclon-tab-pane" data-tab="line" style="display:none;">
					<div class="wclon-callout">
						<div class="wclon-callout__body">
							<strong>設定前置作業</strong>
							<ol>
								<li>到 <a href="https://developers.line.biz/console/" target="_blank">LINE Developers Console</a> 建立 Provider。</li>
								<li>在同一個 Provider 下建立 <strong>Messaging API channel</strong>（綁定你的官方帳號），取得 Channel Access Token（長效，填在「顧客通知」分頁）。</li>
								<li>在同一個 Provider 下建立 <strong>LINE Login channel</strong>，取得 Channel ID 與 Channel Secret（填在下方）。</li>
								<li>在 LINE Login channel 的 Callback URL 填入：<code><?php echo esc_html( $callback_url ); ?></code></li>
								<li>在 LINE Login channel 完成「Linked OA」設定，綁定你的官方帳號。</li>
							</ol>
						</div>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">LINE Login 憑證</h2>
						<p class="wclon-card__desc">填入 LINE Login 憑證；訂單推播的 Token 請至「顧客通知」設定。</p>
						<table class="form-table">
							<tr>
								<th scope="row"><label>LINE Login Channel ID</label></th>
								<td><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[login_channel_id]" value="<?php echo esc_attr( self::get( 'login_channel_id' ) ); ?>" class="regular-text"></td>
							</tr>
							<tr>
								<th scope="row"><label>LINE Login Channel Secret</label></th>
								<td><input type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[login_channel_secret]" value="<?php echo esc_attr( self::get( 'login_channel_secret' ) ); ?>" class="regular-text" autocomplete="new-password"></td>
							</tr>
							<tr>
								<th scope="row">強制重新詢問同意</th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[line_force_consent]" value="1" <?php checked( self::get( 'line_force_consent', 0 ), 1 ); ?>> 每次 LINE 授權都重新顯示同意畫面（授權網址加上 <code>prompt=consent</code>）</label>
									<p class="description"><strong>用來解決「Email address permission 已經是 Applied，卻還是抓不到顧客 Email」</strong>：LINE 會記住使用者先前的同意結果，不會重複詢問。在 Email 權限核准<em>之前</em>就授權過本站的顧客，當初同意的是不含 Email 的權限組合，之後就算 channel 拿到了權限，LINE 也不會再問一次，id_token 裡就一直不會有 email。開啟這個選項會強制每次都跳同意畫面，讓這些顧客有機會補同意提供 Email。<br>代價是每次 LINE 登入都多一個同意步驟，建議開一段時間讓既有顧客都重新授權過之後再關掉。<strong>顧客也可以自己處理</strong>：LINE App → 設定 → 帳號 → 我已授權的應用程式 → 移除本站，下次登入就會重新詢問。</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">綁定歡迎優惠券</h2>
						<p class="wclon-card__desc">首次綁定 LINE 後發送專屬優惠券；未加好友時暫緩，加入後補發（需先設定「管理員通知」的 Webhook）。</p>
						<div class="wclon-flex-card-layout">
						<div class="wclon-flex-card-layout__fields">
						<table class="form-table">
							<tr>
								<th scope="row">啟用</th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[line_bind_coupon_enabled]" value="1" <?php checked( $coupon_enabled, 1 ); ?>> 綁定 LINE 成功後自動發送優惠券</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_coupon_type">折扣類型</label></th>
								<td>
									<select id="wclon_coupon_type" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[line_bind_coupon_type]">
										<option value="fixed_cart" <?php selected( $coupon_type, 'fixed_cart' ); ?>>固定金額折扣</option>
										<option value="percent"    <?php selected( $coupon_type, 'percent' ); ?>>百分比折扣</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_coupon_amount">折扣數值</label></th>
								<td>
									<input type="number" id="wclon_coupon_amount" step="0.01" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[line_bind_coupon_amount]" value="<?php echo esc_attr( $coupon_amount ); ?>" class="small-text">
									<p class="description">固定金額折扣請填新台幣金額；百分比折扣請填 0-100 之間的數字。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_coupon_expiry">有效天數</label></th>
								<td>
									<input type="number" id="wclon_coupon_expiry" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[line_bind_coupon_expiry_days]" value="<?php echo esc_attr( $coupon_expiry ); ?>" class="small-text">
									<p class="description">優惠券發出後幾天內有效，0 表示不過期。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_coupon_min_spend">最低消費金額</label></th>
								<td>
									<input type="number" id="wclon_coupon_min_spend" step="0.01" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[line_bind_coupon_min_spend]" value="<?php echo esc_attr( $coupon_min_spend ); ?>" class="small-text">
									<p class="description">訂單金額需達此門檻才可使用，0 表示無最低消費限制。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_bind_coupon_header_color">推播標題色</label></th>
								<td>
									<input type="text" id="wclon_bind_coupon_header_color" class="wclon-color-field" data-wclon-color-mirror="wclon_header_color" value="<?php echo esc_attr( $header_color ); ?>" data-default-color="#00C300">
									<p class="description">與「顧客通知」分頁「Flex Message 樣式」卡片的「訂單狀態通知標題色」是同一個設定，這裡只是方便在「訂單通知」模組關閉、切不到該分頁時仍可調整。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_bind_coupon_title">推播標題</label></th>
								<td><input type="text" id="wclon_bind_coupon_title" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bind_coupon_title]" value="<?php echo esc_attr( $bind_coupon_title ); ?>" class="regular-text" placeholder="🎁 專屬優惠券"></td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_bind_coupon_greeting">開頭問候語</label></th>
								<td><input type="text" id="wclon_bind_coupon_greeting" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bind_coupon_greeting]" value="<?php echo esc_attr( $bind_coupon_greeting ); ?>" class="regular-text" placeholder="感謝您綁定 LINE 帳號！"></td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_bind_coupon_desc">說明文字</label></th>
								<td>
									<textarea id="wclon_bind_coupon_desc" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bind_coupon_desc]" rows="2" class="large-text"><?php echo esc_textarea( $bind_coupon_desc ); ?></textarea>
									<p class="description">顯示在優惠券代碼／折扣內容下方的補充說明。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_bind_coupon_button_text">按鈕文字</label></th>
								<td><input type="text" id="wclon_bind_coupon_button_text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bind_coupon_button_text]" value="<?php echo esc_attr( $bind_coupon_button_text ); ?>" class="regular-text" placeholder="前往購物"></td>
							</tr>
						</table>
						</div>
						<div class="wclon-flex-card-layout__preview">
							<p class="description" style="margin:0 0 12px;">選項變更時即時更新；實際樣式以 LINE App 顯示為準。</p>
							<div id="wclon_flex_preview_coupon" class="wclon-flex-preview"></div>
						</div>
						</div>
					</div>
				</div><!-- /wclon-tab-line -->

				<!-- ══ Google Tab ══ -->
				<div id="wclon-tab-google" class="wclon-tab-pane" data-tab="google" style="display:none;">
					<div class="wclon-callout">
						<div class="wclon-callout__body">
							<strong>設定前置作業</strong>
							<ol>
								<li>前往 <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>，建立或選擇一個專案。</li>
								<li>在「API 和服務 → 憑證」建立 <strong>OAuth 2.0 用戶端 ID</strong>（應用程式類型選「網路應用程式」）。</li>
								<li>在「已授權的重新導向 URI」填入：<code><?php echo esc_html( $google_callback_url ); ?></code></li>
								<li>取得 Client ID 與 Client Secret 填入下方。</li>
							</ol>
						</div>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">Google OAuth 憑證</h2>
						<table class="form-table">
							<tr>
								<th scope="row"><label>Google Client ID</label></th>
								<td><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[google_client_id]" value="<?php echo esc_attr( self::get( 'google_client_id' ) ); ?>" class="regular-text"></td>
							</tr>
							<tr>
								<th scope="row"><label>Google Client Secret</label></th>
								<td><input type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[google_client_secret]" value="<?php echo esc_attr( self::get( 'google_client_secret' ) ); ?>" class="regular-text" autocomplete="new-password"></td>
							</tr>
						</table>
					</div>
				</div><!-- /wclon-tab-google -->

				<!-- ══ Apple Tab ══ -->
				<div id="wclon-tab-apple" class="wclon-tab-pane" data-tab="apple" style="display:none;">
					<div class="wclon-callout">
						<div class="wclon-callout__body">
							<strong>設定前置作業（5 步驟）</strong>
							<p>需要有效的 <a href="https://developer.apple.com/programs/" target="_blank">Apple Developer Program</a> 訂閱（每年 99 USD）。</p>

							<p class="wclon-callout__step">步驟 1：建立 App ID</p>
							<ol>
								<li>登入 <a href="https://developer.apple.com/account/" target="_blank">Apple Developer Console</a>，前往「Certificates, Identifiers &amp; Profiles → Identifiers」。</li>
								<li>點擊「+」，選擇類型 <strong>App IDs</strong>，再選「App」。</li>
								<li>填入說明（隨意），Bundle ID 採反向域名格式（例：<code><?php echo esc_html( $apple_reverse_domain . '.app' ); ?></code>）。</li>
								<li>在 Capabilities 列表中勾選 <strong>Sign In with Apple</strong>，儲存。</li>
							</ol>

							<p class="wclon-callout__step">步驟 2：建立 Key</p>
							<ol>
								<li>前往「Keys」，點擊「+」，輸入金鑰名稱並勾選 <strong>Sign In with Apple</strong>。</li>
								<li>點擊「Configure」，選取步驟 1 建立的 App ID 作為 Primary App ID。</li>
								<li>儲存後先<strong>記下 Key ID</strong>（10 碼），暫時不要下載金鑰。</li>
							</ol>

							<p class="wclon-callout__step">步驟 3：建立 Services ID（Client ID）</p>
							<ol>
								<li>在「Identifiers」點擊「+」，選擇 <strong>Services IDs</strong>。</li>
								<li>填入說明與 Identifier（反向域名格式，例：<code><?php echo esc_html( $apple_reverse_domain . '.signin' ); ?></code>），此即本外掛的 <strong>Services ID（Client ID）</strong>。</li>
								<li>勾選「Sign In with Apple」並點擊「Configure」。</li>
								<li>填入網域名稱（<code><?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ); ?></code>），在「Return URLs」填入：<code><?php echo esc_html( $apple_callback_url ); ?></code>（必須 HTTPS）。</li>
								<li>儲存設定。</li>
							</ol>

							<p class="wclon-callout__step">步驟 4：下載私鑰</p>
							<ol>
								<li>回到「Keys」，找到步驟 2 建立的金鑰，點擊「Download」下載 <strong>.p8 私鑰檔</strong>。</li>
								<li>此檔案<strong>只能下載一次</strong>，請妥善保管。</li>
							</ol>

							<p class="wclon-callout__step">步驟 5：填入憑證</p>
							<ol>
								<li><strong>Team ID</strong>：登入 Apple Developer 後，右上角帳號名稱旁的 10 碼英數字串。</li>
								<li><strong>Key ID</strong>：步驟 2 記下的 10 碼。</li>
								<li><strong>Services ID</strong>：步驟 3 建立的 Identifier（例：<code><?php echo esc_html( $apple_reverse_domain . '.signin' ); ?></code>）。</li>
								<li><strong>私鑰</strong>：開啟 .p8 檔案，複製全部內容（含 <code>-----BEGIN PRIVATE KEY-----</code> 與 <code>-----END PRIVATE KEY-----</code> 行）貼入下方欄位。</li>
							</ol>

							<p class="wclon-callout__step">常見錯誤</p>
							<ul>
								<li><strong>Private key 格式無效</strong>：確認貼上時包含 BEGIN/END 標記行。</li>
								<li><strong>invalid_client</strong>：確認 Key ID、Team ID、Services ID 三者填寫正確。</li>
								<li><strong>Invalid redirect_uri</strong>：確認 Return URL 與上方回呼 URL 完全相符。</li>
								<li><strong>使用者名稱欄位空白</strong>：Apple 僅在<strong>首次授權</strong>時回傳姓名，後續登入不再傳送。</li>
							</ul>

							<p class="wclon-callout__warn">⚠ 注意：Apple Sign In 回呼 URL 必須使用 HTTPS。</p>
						</div>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">Apple 憑證</h2>
						<table class="form-table">
							<tr>
								<th scope="row"><label>Services ID（Client ID）</label></th>
								<td>
									<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_client_id]" value="<?php echo esc_attr( self::get( 'apple_client_id' ) ); ?>" class="regular-text" placeholder="com.example.signin">
									<p class="description">即 Apple Developer Console 中 Services ID 的 Identifier。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label>Team ID</label></th>
								<td><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_team_id]" value="<?php echo esc_attr( self::get( 'apple_team_id' ) ); ?>" class="regular-text" placeholder="XXXXXXXXXX"></td>
							</tr>
							<tr>
								<th scope="row"><label>Key ID</label></th>
								<td><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_key_id]" value="<?php echo esc_attr( self::get( 'apple_key_id' ) ); ?>" class="regular-text" placeholder="XXXXXXXXXX"></td>
							</tr>
							<tr>
								<th scope="row"><label>私鑰（.p8）</label></th>
								<td>
									<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_private_key]" rows="8" class="large-text" placeholder="-----BEGIN PRIVATE KEY-----&#10;MIGTAgEAMBMGB...&#10;-----END PRIVATE KEY-----"><?php echo esc_textarea( self::get( 'apple_private_key' ) ); ?></textarea>
									<p class="description">將 .p8 檔案內容（含 BEGIN / END 行）貼入。金鑰只能從 Apple Developer Console 下載一次，請妥善保存。</p>
								</td>
							</tr>
						</table>
					</div>
				</div><!-- /wclon-tab-apple -->

				<!-- ══ 顧客通知 Tab（仍屬於這個 <form>／wclon_settings） ══ -->
				<div class="wclon-tab-pane" data-tab="customer" style="display:none;">
					<div class="wclon-card">
						<h2 class="wclon-card__title">LINE 串接設定 <span class="wclon-shared-tag">共用</span></h2>
						<table class="form-table">
							<tr>
								<th scope="row"><label>Messaging API<br>Channel Access Token</label></th>
								<td>
									<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[channel_access_token]" rows="3" class="large-text" placeholder="長效 Channel Access Token"><?php echo esc_textarea( self::get( 'channel_access_token' ) ); ?></textarea>
									<p class="description">在 LINE Developers Console 的 Messaging API channel 頁籤取得（設定步驟見「LINE」分頁的前置作業）。只需填一次，下方「顧客通知」與「管理員群組通知」共用同一組 Token。</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">顧客 LINE 訂單通知</h2>
						<p class="wclon-card__desc">訂單狀態變更時通知下單顧客本人。</p>
						<table class="form-table">
							<tr>
								<th scope="row">啟用顧客訂單通知</th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_customer_enabled]" value="1" <?php checked( self::get( 'notify_customer_enabled', 1 ), 1 ); ?>> 訂單狀態變更（或建立）時，推播通知給已綁定 LINE 的顧客</label>
									<p class="description">關閉後即使下方勾選了觸發狀態，也不會推播給顧客；不影響 LINE 登入/綁定功能本身。</p>
								</td>
							</tr>
							<tr>
								<th scope="row">觸發通知的訂單狀態</th>
								<td>
									<?php foreach ( $statuses as $key => $label ) : ?>
										<label style="display:inline-block;margin:0 16px 8px 0;">
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[statuses][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_statuses, true ) ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
									<p class="description">勾選的狀態在訂單「轉換到」該狀態時會發送通知。</p>
								</td>
							</tr>
							<tr>
								<th scope="row">訂單備注通知</th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[note_notify]" value="1" <?php checked( self::get( 'note_notify', 1 ), 1 ); ?>> 新增顧客可見備注時，同步推播 LINE 訊息</label>
								</td>
							</tr>
							<tr>
								<th scope="row">物流狀態通知</th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[logistics_notify_enabled]" value="1" <?php checked( self::get( 'logistics_notify_enabled' ), 1 ); ?>> 物流貨態更新時，同步推播 LINE 訊息</label>
									<p class="description">讀取「ecpay-ecommerce-for-woocommerce」外掛寫入訂單的物流貨態備注（例如已到店、配送中），需該外掛已啟用並完成物流串接才會有內容可推播；未安裝該外掛時這個開關不會有作用。</p>
								</td>
							</tr>
						</table>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">Flex Message 樣式</h2>
						<div class="wclon-flex-card-layout">
						<div class="wclon-flex-card-layout__fields">
						<table class="form-table">
							<tr>
								<th scope="row"><label for="wclon_header_color">訂單狀態通知標題色</label></th>
								<td>
									<input type="text" id="wclon_header_color" class="wclon-color-field" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[header_color]" value="<?php echo esc_attr( $header_color ); ?>" data-default-color="#00C300">
									<p class="description">預設會依訂單狀態自動套用顏色（處理中綠色、完成藍色、取消灰色⋯），設定後固定使用此色。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_note_color">備注通知標題色</label></th>
								<td>
									<input type="text" id="wclon_note_color" class="wclon-color-field" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[note_color]" value="<?php echo esc_attr( $note_color ); ?>" data-default-color="#FF9800">
									<p class="description">預設橘色（#FF9800）。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_button_text">訂單按鈕文字</label></th>
								<td>
									<input type="text" id="wclon_button_text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[button_text]" value="<?php echo esc_attr( $button_text ); ?>" class="regular-text" placeholder="查看訂單詳情">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_greeting_template">問候語</label></th>
								<td>
									<input type="text" id="wclon_greeting_template" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[greeting_template]" value="<?php echo esc_attr( $greeting_template ); ?>" class="regular-text" placeholder="您好，{customer_name}！">
									<p class="description">訂單狀態通知與備注通知開頭共用同一句問候語，可用變數：<code>{customer_name}</code>（顧客姓名）、<code>{site_name}</code>（網站名稱）。</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_note_title">備注通知標題文字</label></th>
								<td>
									<input type="text" id="wclon_note_title" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[note_title]" value="<?php echo esc_attr( $note_title ); ?>" class="regular-text" placeholder="店家留言">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wclon_logistics_title">物流通知標題文字</label></th>
								<td>
									<input type="text" id="wclon_logistics_title" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[logistics_title]" value="<?php echo esc_attr( $logistics_title ); ?>" class="regular-text" placeholder="🚚 物流狀態更新">
								</td>
							</tr>
						</table>
						</div>
						<div class="wclon-flex-card-layout__preview">
							<p style="margin:0 0 8px;"><label for="wclon_flex_preview_type" style="font-weight:600;">預覽訊息類型</label></p>
							<select id="wclon_flex_preview_type">
								<option value="status">訂單狀態通知</option>
								<option value="note">備注通知</option>
								<option value="logistics">物流狀態通知</option>
							</select>
							<p class="description" style="max-width:260px;margin:8px 0 12px;">選項變更時即時更新；實際樣式以 LINE App 顯示為準。</p>
							<div id="wclon_flex_preview_customer" class="wclon-flex-preview"></div>
						</div>
						</div>
					</div>

					<div class="wclon-card">
						<h2 class="wclon-card__title">測試推播（顧客）</h2>
						<p class="wclon-card__desc">發送測試訊息，確認訂單卡片外觀與 Token。</p>
						<table class="form-table" style="max-width:600px;">
							<tr>
								<th scope="row"><label for="wclon_test_line_id">LINE User ID</label></th>
								<td>
									<input type="text" id="wclon_test_line_id" class="regular-text"
										value="<?php echo esc_attr( $my_line_id ); ?>"
										placeholder="Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
									<?php if ( $my_line_id ) : ?>
										<p class="description">已自動填入您目前帳號綁定的 LINE User ID。</p>
									<?php else : ?>
										<p class="description">請先在前台綁定您自己的 LINE，或手動輸入 User ID。</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>
						<button type="button" id="wclon_test_btn" class="button button-secondary">發送測試訊息</button>
						<span id="wclon_test_msg" class="wclon-test-msg"></span>
					</div>
				</div><!-- /wclon-tab-customer -->
			</form>

			<?php if ( $mod_notify ) : ?>
			<!-- ══ 管理員通知 Tab（獨立 <form>／option，共用主表單的「儲存設定」按鈕，見下方 JS） ══ -->
			<div class="wclon-admin-wrap wclon-tab-pane" data-tab="adminline" style="display:none;">
				<?php WCAN_Settings::render_tab_content(); ?>
			</div><!-- /wclon-tab-adminline -->
			<?php endif; ?>

			<!-- ══ Turnstile Tab（獨立 <form>／option，永遠顯示，不受模組開關影響） ══ -->
			<div class="wclon-admin-wrap wclon-tab-pane" data-tab="turnstile" style="display:none;">
				<?php WCLON_Turnstile::render_tab_content(); ?>
			</div><!-- /wclon-tab-turnstile -->

			<?php if ( $can_manage_license ) : ?>
			<div class="wclon-admin-wrap wclon-tab-pane" data-tab="license" style="display:none;">
				<?php WCLON_License::render_tab(); ?>
			</div><!-- /wclon-tab-license -->
			<?php endif; ?>

			<?php if ( $mod_sysmail ) : ?>
			<!-- ══ 系統信件 Tab（獨立 <form>／option） ══ -->
			<div class="wclon-admin-wrap wclon-tab-pane" data-tab="sysmail" style="display:none;">
				<?php WCLON_System_Email_Settings::render_tab_content(); ?>
			</div><!-- /wclon-tab-sysmail -->
			<?php endif; ?>

			<?php if ( $can_manage_modules ) : ?>
			<!-- ══ 模組 Tab（獨立 <form>／option，僅限 manage_options） ══ -->
			<div class="wclon-admin-wrap wclon-tab-pane" data-tab="modules" style="display:none;">
				<form method="post" action="options.php" id="wclon-module-settings-form">
					<?php settings_fields( 'wclon_module_settings_group' ); ?>
					<div class="wclon-card">
						<h2 class="wclon-card__title">功能模組</h2>
						<p class="wclon-card__desc">停用模組會隱藏對應頁籤與前台功能；設定資料會保留，重新啟用後可沿用。</p>
						<?php foreach ( self::get_module_definitions() as $mod_key => $mod_info ) : ?>
						<div class="wclon-module-row">
							<label class="wclon-toggle">
								<input type="checkbox" name="<?php echo esc_attr( self::MODULE_OPTION_KEY ); ?>[<?php echo esc_attr( $mod_key ); ?>]" value="1" <?php checked( self::module_enabled( $mod_key ), true ); ?>>
								<span class="wclon-slider"></span>
							</label>
							<span class="wclon-module-label"><?php echo esc_html( $mod_info['label'] ); ?></span>
							<span class="wclon-module-desc"><?php echo esc_html( $mod_info['desc'] ); ?></span>
						</div>
						<?php endforeach; ?>
					</div>
				</form>
			</div><!-- /wclon-tab-modules -->
			<?php endif; ?>

			<!-- 「儲存設定」按鈕故意放在 wclon-settings-form 的 </form> 之外（用 form="wclon-settings-form"
			     屬性關聯回主表單，HTML5 標準寫法，不影響送出行為）：這樣不管在哪個分頁，這顆按鈕都會落在
			     當下可見內容的最下方。顧客通知仍屬於主表單（wclon-settings-form），「管理員通知」
			     「Turnstile」「系統信件」「模組」四個頁籤各自是獨立的 <form> 區塊、寫在主表單關閉之後，
			     按鈕留在此處才能在所有頁籤都保持可見。 -->
			<div id="wclon-main-submit">
				<?php submit_button( '儲存設定', 'primary', 'submit', true, array( 'form' => 'wclon-settings-form' ) ); ?>
			</div>

		</div>

		<?php
	}
}
