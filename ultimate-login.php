<?php
/**
 * Plugin Name: Ultimate Login
 * Plugin URI:  https://example.com
 * Description: 讓顧客透過 LINE、Google、Apple 登入綁定帳號，並在 WooCommerce 訂單狀態變更時，透過 LINE Messaging API 自動推播訂單通知給顧客；同時可推播新訂單通知到管理員/員工共用的 LINE 群組或聊天室。
 * Version:     1.35.0
 * Author:      NiBill
 * Text Domain: ultimate-login
 * Requires Plugins: woocommerce
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 主檔案重複載入的防線（v1.30.1 新增，正式站 PHP Warning: Constant WCLON_VERSION already defined）
//
// 這支檔案有可能在同一次請求裡被載入兩次——最常見的是舊資料夾的同一個外掛還在啟用
// （本外掛歷經 wc-line-order-notify → social-login-order-notify → ultimate-login 兩次改名，
// 站台若只上傳新資料夾、沒有停用舊的，兩份就會同時在 active_plugins 裡），
// 其次是主檔案被佈景主題／程式碼片段外掛再 include 一次。
//
// **症狀只有四行 Warning，不會有 class 重複宣告的 fatal error**，所以很容易被當成無害的雜訊：
// 下面的 includes 全是 require_once，而且路徑取自「第一次載入時就定住的」WCLON_PLUGIN_DIR，
// 第二次載入時那些 require 全部被 dedupe 掉。真正的危害在 Warning 後面——這支檔案剩下的
// add_action()／add_filter() 會整批註冊第二遍（訂單通知推播兩次、按鈕輸出兩次）。
//
// 因此偵測到重複載入時**整支檔案直接 return**（第二份的 hook 一個都不註冊），
// 並在後台顯示一則診斷用的提示，把「目前生效的路徑」與「被略過的路徑」都印出來——
// 光看 PHP Warning 只知道有人重複定義，看不出另一份在哪個資料夾。
if ( defined( 'WCLON_VERSION' ) ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>終極登入</strong>：偵測到外掛主檔案在同一次請求中被載入兩次，第二份已略過（不重複註冊任何 hook）。目前生效的版本是 <code>%1$s</code>，路徑 <code>%2$s</code>；被略過的是 <code>%3$s</code>。請到「外掛」頁確認是否有舊資料夾（<code>social-login-order-notify</code> 或 <code>wc-line-order-notify</code>）的同一個外掛仍在啟用，停用並刪除舊的即可。</p></div>',
			esc_html( WCLON_VERSION ),
			esc_html( WCLON_PLUGIN_DIR ),
			esc_html( plugin_dir_path( __FILE__ ) )
		);
	} );
	return;
}

define( 'WCLON_VERSION', '1.35.0' );
define( 'WCLON_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCLON_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// 會員中心「帳號綁定」獨立頁面的 WC Account endpoint slug
define( 'WCLON_ACCOUNT_ENDPOINT', 'social-login' );

require_once WCLON_PLUGIN_DIR . 'includes/class-wclon-settings.php';
require_once WCLON_PLUGIN_DIR . 'includes/class-wclon-line-login.php';
require_once WCLON_PLUGIN_DIR . 'includes/class-wclon-google-login.php';
require_once WCLON_PLUGIN_DIR . 'includes/class-wclon-apple-login.php';
require_once WCLON_PLUGIN_DIR . 'includes/class-wclon-notifier.php';
// 管理員/員工 LINE 群組通知（v1.8.0 起與原本獨立的 wc-line-admin-notify 外掛合併）
require_once WCLON_PLUGIN_DIR . 'includes/class-wcan-settings.php';
require_once WCLON_PLUGIN_DIR . 'includes/class-wcan-webhook.php';
require_once WCLON_PLUGIN_DIR . 'includes/class-wcan-notifier.php';
// WP 核心管理員通知信件壓制（v1.19.0 起，含原 twshop 外掛移入的新使用者註冊／密碼變更兩項；
// v1.21.0 曾整個移到獨立的 wp-system-mail-control 外掛，v1.22.0 起兩邊並存，見該檔案開頭註解）
require_once WCLON_PLUGIN_DIR . 'includes/class-wclon-system-email-settings.php';
// Cloudflare Turnstile 人機驗證（v1.24.0 新增，保護登入／註冊／忘記密碼三種表單）
require_once WCLON_PLUGIN_DIR . 'includes/class-wclon-turnstile.php';
require_once WCLON_PLUGIN_DIR . 'includes/class-wclon-updater.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>WC LINE Order Notify 需要啟用 WooCommerce 才能運作。</p></div>';
		} );
		return;
	}
	WCLON_Settings::init();
	WCLON_Line_Login::init();
	WCLON_Google_Login::init();
	WCLON_Apple_Login::init();
	WCLON_Notifier::init();
	WCAN_Settings::init();
	WCAN_Webhook::init();
	WCAN_Notifier::init();
	WCLON_System_Email_Settings::init();
	WCLON_Turnstile::init();
	WCLON_Updater::init();

	// 社交登入按鈕共用 wrapper：priority 9 開啟、20 關閉（按鈕本身掛在預設的 10）
	//
	// **以下這段講的是「按鈕在表單下方」（below，預設）的情形**；v1.29.0 起位置可設定，
	// 選「上方」（above）時三個 hook 全部換成對應的 _start 版本，見下方 social_hook() 的使用處。
	//
	// login_form 在 wp-login.php 固定位於「密碼欄位之後、登入按鈕之前」（WP 核心版面，無法更動）；
	// woocommerce_login_form_end 則位於 WooCommerce 登入表單「登入按鈕之後」。有些主題（如 Blocksy
	// 彈出登入視窗）不是走 wp-login.php，卻同時把這兩個 action 都觸發一次——這種情況下只在
	// woocommerce_login_form_end（登入按鈕之後）輸出，避免社交按鈕出現在帳密登入按鈕「上方」；
	// 只有真正的 wp-login.php 頁面（用只有該頁才會觸發的 login_init 判斷）才在 login_form 位置
	// 輸出，因為那裡本來就沒有 woocommerce_login_form_end 可用。見各登入 class 的
	// echo_wp_login_button() 的 login_init 判斷。
	//
	// 開/關只用「目前是否已開啟」（$wclon_login_row_open）配對，**不用**一次性的「本請求已渲染過」
	// 旗標——後者會在同一頁同時有多個登入表單時（例如 Blocksy 把登入表單同時放在 header 彈窗與
	// 頁面主內容的「我的帳號」頁）害到第二個表單：header 彈窗先渲染就把旗標鎖死，主內容的登入表單
	// 反而拿不到按鈕。改成每個 woocommerce_login_form_end（每張表單各一次）都自成一組開/關，與註冊
	// 表單 wrapper 的行為一致（見下方 woocommerce_register_form_end）。login_form 與
	// woocommerce_login_form_end 在同一張表單內同時觸發的重複問題，已由 login_init 判斷擋掉
	// （非 wp-login.php 頁面時 login_form 分支完全不輸出），不需要跨表單的一次性旗標。
	// v1.29.0：按鈕相對於帳密表單的位置（above／below）改為可設定，實際 hook 名稱一律問
	// WCLON_Settings::social_hook()，三個登入 class 也是（見該方法的對照表）。
	//
	// 分隔線（「或使用其他方式」）跟著位置換邊：放在表單**下方**時是「分隔線→按鈕」，
	// 放在**上方**時要變成「按鈕→分隔線」，分隔線才會緊貼著它下面的帳號密碼欄位，符合一般
	// 「社群登入在上、分隔線、帳密表單在下」的版面慣例。所以分隔線不是固定寫在 open 裡，
	// 而是依位置決定由 open 還是 close 輸出。
	$wclon_btn_position = WCLON_Settings::get_btn_position();
	$wclon_divider_html = '<div class="wclon-divider"><hr><span>或使用其他方式</span><hr></div>';

	// 整列的 class 只在這裡組一次，登入／註冊兩處 wrapper 共用。位置 modifier 之外還帶上版型
	// modifier（`--full` / `--icon`），因為「純 ICON 時三顆圖示要併成一組置中」是**整列**的排版
	// 決定（wrap 不再平分寬度 + justify-content:center），只靠按鈕自己的 `.wclon-btn--icon`
	// class 做不到——CSS 沒辦法從子元素反推去改父層 flex 容器的對齊方式。
	$wclon_row_class = 'wclon-social-row wclon-social-row--' . $wclon_btn_position
		. ' wclon-social-row--' . WCLON_Settings::get_layout()
		. ' wclon-social-row--align-' . WCLON_Settings::get_align();

	$wclon_social_enabled = function () {
		return WCLON_Settings::get( 'login_channel_id' ) || WCLON_Settings::get( 'google_client_id' ) || WCLON_Settings::get( 'apple_client_id' );
	};

	$wclon_login_row_open = false;

	$wclon_open_login_row = function () use ( &$wclon_login_row_open, $wclon_social_enabled, $wclon_row_class, $wclon_btn_position, $wclon_divider_html ) {
		if ( $wclon_login_row_open ) {
			return; // 同一張表單已開啟，避免重複開（例如同時觸發兩個 hook）
		}
		if ( ! $wclon_social_enabled() ) {
			return;
		}
		$wclon_login_row_open = true;
		echo '<div class="' . esc_attr( $wclon_row_class ) . '">';
		if ( 'below' === $wclon_btn_position ) {
			echo $wclon_divider_html; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	};
	$wclon_close_login_row = function () use ( &$wclon_login_row_open, $wclon_btn_position, $wclon_divider_html ) {
		if ( ! $wclon_login_row_open ) {
			return;
		}
		$wclon_login_row_open = false;
		if ( 'above' === $wclon_btn_position ) {
			echo $wclon_divider_html; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	};

	// wp-login.php：below 模式用核心的 login_form（該頁才觸發，其他主題也觸發時交給
	// woocommerce_login_form_end，所以要 did_action('login_init') 把關）；above 模式改用本外掛
	// 自訂的 wclon_wp_login_form_top，它只會被下面那個 login_message 橋接觸發一次，不需要把關。
	$wclon_wp_login_hook  = WCLON_Settings::social_hook( 'wp_login' );
	$wclon_needs_wp_guard = ( 'login_form' === $wclon_wp_login_hook );

	add_action( $wclon_wp_login_hook, function () use ( $wclon_open_login_row, $wclon_needs_wp_guard ) {
		if ( $wclon_needs_wp_guard && ! did_action( 'login_init' ) ) {
			return;
		}
		$wclon_open_login_row();
	}, 9 );
	add_action( $wclon_wp_login_hook, function () use ( $wclon_close_login_row, $wclon_needs_wp_guard ) {
		if ( $wclon_needs_wp_guard && ! did_action( 'login_init' ) ) {
			return;
		}
		$wclon_close_login_row();
	}, 20 );

	add_action( WCLON_Settings::social_hook( 'wc_login' ), $wclon_open_login_row, 9 );
	add_action( WCLON_Settings::social_hook( 'wc_login' ), $wclon_close_login_row, 20 );

	$wclon_register_hook = WCLON_Settings::social_hook( 'wc_register' );
	add_action( $wclon_register_hook, function () use ( $wclon_social_enabled, $wclon_row_class, $wclon_btn_position, $wclon_divider_html ) {
		if ( ! $wclon_social_enabled() ) {
			return;
		}
		echo '<div class="' . esc_attr( $wclon_row_class ) . '">';
		if ( 'below' === $wclon_btn_position ) {
			echo $wclon_divider_html; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}, 9 );
	add_action( $wclon_register_hook, function () use ( $wclon_social_enabled, $wclon_btn_position, $wclon_divider_html ) {
		if ( ! $wclon_social_enabled() ) {
			return;
		}
		if ( 'above' === $wclon_btn_position ) {
			echo $wclon_divider_html; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}, 20 );

	// wp-login.php「上方」模式的橋接：核心在那張表單裡**只有** login_form 一個 hook，而它固定
	// 位於密碼欄之後、登入按鈕之前（`wp-login.php` 寫死的版面，外掛動不了），沒有任何位於帳號
	// 欄位之前的位置可用。唯一能輸出在整張表單正上方的是 login_message filter，於是這裡把它
	// 接到自訂 action wclon_wp_login_form_top 上——三個登入 class 與上面的 wrapper 都只認這個
	// action，不必各自去分辨「現在該用 action 還是 filter」。
	//
	// priority 20 是為了排在 WCLON_Line_Login::render_login_page_notice()（同一個 filter，
	// priority 10）之後，讓 LINE 的引導訊息維持在最上面。
	// 只在 action=login 時輸出：忘記密碼／重設密碼／註冊頁也會跑這個 filter，但那些表單本來就
	// 沒有社交按鈕（below 模式下 login_form 只在登入表單觸發），要維持一樣的行為。
	if ( 'wclon_wp_login_form_top' === $wclon_wp_login_hook ) {
		add_filter( 'login_message', function ( $message ) {
			$login_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'login' !== $login_action ) {
				return $message;
			}
			ob_start();
			do_action( 'wclon_wp_login_form_top' );
			return $message . ob_get_clean();
		}, 20 );
	}

	// 結帳頁綁定列（v1.30.0 起是 LINE 與 Google/Apple 兩個獨立區塊，見
	// WCLON_Settings::render_social_bar()）。
	//
	// **改版前這裡是 priority 4 開 wrapper、5/6/7 各平台自己掛 chip、8 關 wrapper**，而且那段
	// wrapper markup 與標籤字串跟 render_social_bar() 裡的一模一樣、各寫一份。拆成兩區後若沿用
	// 那套，會變成八個要彼此對齊的 priority 散在主檔案與三個 class 裡；改成直接呼叫同一個
	// 渲染器之後，結帳頁／購物車頁／短代碼三條路徑走的是完全相同的程式碼。
	add_action( 'woocommerce_before_checkout_form', function () {
		if ( ! WCLON_Settings::get( 'show_on_checkout', 1 ) ) {
			return;
		}
		echo WCLON_Settings::render_social_bar(); // phpcs:ignore WordPress.Security.EscapeOutput
	}, 5 );

	// 傳統短代碼版購物車頁（[woocommerce_cart]）綁定列：與結帳頁共用 render_social_bar() 輸出，
	// chip 用 $_SERVER['REQUEST_URI'] 當導回目標，完成 OAuth 後留在購物車頁
	add_action( 'woocommerce_before_cart', function () {
		if ( ! WCLON_Settings::get( 'show_on_cart', 0 ) ) {
			return;
		}
		echo WCLON_Settings::render_social_bar(); // phpcs:ignore WordPress.Security.EscapeOutput
	} );

	// 結帳頁綁定列有顯示時，隱藏 WC 原生「老客戶？點擊登入」提示（改由社交按鈕提供登入 / 註冊入口）。
	//
	// v1.30.0 起判斷條件從「有任一平台憑證」改成 has_social_bar_content()：拆成兩區之後會出現
	// 「LINE 已綁定所以整區隱藏、又沒設定 Google/Apple ⇒ 整條列什麼都沒有」的組合，這種情況下
	// 還把 WC 的登入提示藏起來，等於顧客連一個登入入口都沒有。
	add_filter( 'pre_option_woocommerce_enable_checkout_login_reminder', function ( $pre ) {
		if ( is_checkout() && WCLON_Settings::get( 'show_on_checkout', 1 ) && WCLON_Settings::has_social_bar_content() ) {
			return 'no';
		}
		return $pre;
	} );

	// 會員中心「帳號綁定」獨立頁面（v1.9.4 起與「帳戶詳細資料」分開）
	$wclon_account_social_enabled = function () {
		if ( ! WCLON_Settings::get( 'show_on_myaccount', 1 ) ) {
			return false;
		}
		return WCLON_Settings::get( 'login_channel_id' ) || WCLON_Settings::get( 'google_client_id' ) || WCLON_Settings::get( 'apple_client_id' );
	};

	add_action( 'init', function () {
		add_rewrite_endpoint( WCLON_ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES );
	} );
	add_filter( 'query_vars', function ( $vars ) {
		$vars[] = WCLON_ACCOUNT_ENDPOINT;
		return $vars;
	} );
	// 新增 endpoint 後需要 flush 一次 rewrite rules，之後不再需要
	if ( ! get_option( 'wclon_social_endpoint_flushed' ) ) {
		add_action( 'init', function () {
			flush_rewrite_rules();
			update_option( 'wclon_social_endpoint_flushed', 1 );
		}, 20 );
	}

	// 我的帳號選單新增「帳號綁定」項目，插在「登出」之前
	add_filter( 'woocommerce_account_menu_items', function ( $items ) use ( $wclon_account_social_enabled ) {
		if ( ! $wclon_account_social_enabled() ) {
			return $items;
		}
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ WCLON_ACCOUNT_ENDPOINT ] = '帳號綁定';
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	} );
	add_filter( 'woocommerce_endpoint_' . WCLON_ACCOUNT_ENDPOINT . '_title', function () {
		return '帳號綁定';
	} );

	// 「帳號綁定」頁面內容：社交帳號綁定區塊（priority 9/20 開關 wrapper，各 provider 於 10/11/12 輸出）
	add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', function () use ( $wclon_account_social_enabled ) {
		if ( ! $wclon_account_social_enabled() ) {
			echo '<p>' . esc_html__( '目前尚未開放社群帳號綁定。', 'ultimate-login' ) . '</p>';
			return;
		}
		echo '<div class="wclon-account-social"><p class="wclon-account-social__label">綁定帳號</p><div class="wclon-account-social__rows">';
	}, 9 );
	add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', function () use ( $wclon_account_social_enabled ) {
		if ( ! $wclon_account_social_enabled() ) {
			return;
		}
		echo '</div></div>';
	}, 20 );

	// 「帳號綁定」頁面內容：通知設定區塊（LINE 訂單通知僅已綁定 LINE 時顯示 priority 41，Email 訂單通知 priority 42），與上方社交帳號綁定區塊各自獨立
	add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', function () {
		echo '<div class="wclon-account-notify"><p class="wclon-account-social__label">通知設定</p><div class="wclon-account-social__rows">';
	}, 40 );
	add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', function () {
		echo '</div></div>';
	}, 50 );
} );

foreach ( array( 'wp_enqueue_scripts', 'login_enqueue_scripts' ) as $_wclon_hook ) {
	add_action( $_wclon_hook, function () {
		wp_enqueue_style(
			'wclon-frontend',
			WCLON_PLUGIN_URL . 'assets/css/wclon-frontend.css',
			array(),
			WCLON_VERSION
		);
		wp_enqueue_script(
			'wclon-auth-nav',
			WCLON_PLUGIN_URL . 'assets/js/wclon-auth-nav.js',
			array(),
			WCLON_VERSION,
			true
		);
	} );
}
unset( $_wclon_hook );

// 宣告支援 HPOS（高效能訂單儲存）
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
