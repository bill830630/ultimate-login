<?php
/**
 * Cloudflare Turnstile 人機驗證（v1.24.0 新增）。
 *
 * 保護登入、註冊、忘記密碼、留言／商品評價四種表單。四者各自有獨立開關，站台可只開其中一項。
 * 結帳頁下單目前不在範圍內（本站是 woocommerce/classic-shortcode 區塊包住的傳統短代碼結帳，
 * 日後要加的話掛 woocommerce_after_checkout_validation 即可，見 CLAUDE.md）。
 *
 * 設定獨立成自己的 option（`wclon_turnstile_settings`）與獨立 <form>，比照
 * WCLON_System_Email_Settings／WCAN_Settings 的既有慣例——欄位自成一塊、跟顧客通知那組
 * 設定沒有共用欄位，混進主表單只會讓 sanitize() 更難維護。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Turnstile {

	const OPTION_KEY = 'wclon_turnstile_settings';

	/** Turnstile widget 產生的 token 欄位名稱（Cloudflare 預設值，前端 render 與後端讀取共用） */
	const TOKEN_FIELD = 'cf-turnstile-response';

	const VERIFY_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	const API_HANDLE = 'wclon-turnstile-api';

	/**
	 * Elementor Pro 表單的自訂欄位型別 key（v1.28.0 新增）。
	 *
	 * Elementor Pro 到目前為止都沒有內建 Turnstile（只有 reCAPTCHA v2/v3 與 hCaptcha），
	 * 但它有正式的擴充 API：`elementor_pro/forms/field_types` 註冊型別、
	 * `elementor_pro/forms/render_field/{type}` 輸出欄位、`elementor_pro/forms/validation`
	 * 做後端驗證。這個字串同時是三個 hook 的共同 key，改動要三處一起改。
	 */
	const ELEMENTOR_FIELD_TYPE = 'wclon_turnstile';

	private static $cache = null;

	/**
	 * 同一次請求內的驗證結果記憶（key = token 的 md5，value = true 或 WP_Error）。
	 *
	 * Cloudflare 的 siteverify token 是**一次性**的：同一個 token 送第二次一定會拿到
	 * `timeout-or-duplicate` 失敗。但同一次註冊請求會經過兩個驗證點——WooCommerce 我的帳號
	 * 註冊先跑 `woocommerce_process_registration_errors`，Blocksy 彈窗註冊則是那個 filter 的
	 * 回傳值被忽略、真正擋得住的是 `wc_create_new_customer()` 內的 `woocommerce_registration_errors`
	 * ——沒有這層記憶，第二個驗證點會把明明有效的 token 誤判成失敗。
	 */
	private static $verified = array();

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_cache' ) );

		// 沒啟用或金鑰沒填齊時，前台完全不掛任何 hook（等同外掛不存在），避免半套狀態把顧客擋在門外
		if ( ! self::is_active() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_loader_tag' ), 10, 2 );

		// ── widget 輸出位置 ──
		// 每一張表單只會命中下列其中一個 hook，不需要「本表單已渲染過」之類的旗標：
		//   wp-login.php          → login_form / register_form / lostpassword_form
		//   WooCommerce 我的帳號   → woocommerce_login_form / woocommerce_register_form / woocommerce_lostpassword_form
		//   Blocksy header 彈窗    → login_form（密碼欄之後）、lostpassword_form；註冊則依註冊策略
		//                            二選一觸發 woocommerce_register_form 或 register_form
		//                            （見 blocksy-companion/framework/features/header/modal/*.php）
		// 也就是說 WooCommerce 的模板不會觸發 WP 原生那三個 hook、反之亦然，兩邊都掛不會重複。
		if ( self::get( 'protect_login', 1 ) ) {
			add_action( 'login_form', array( __CLASS__, 'render_login_widget' ) );
			add_action( 'woocommerce_login_form', array( __CLASS__, 'render_login_widget' ) );
		}
		if ( self::get( 'protect_register', 1 ) ) {
			add_action( 'register_form', array( __CLASS__, 'render_register_widget' ) );
			add_action( 'woocommerce_register_form', array( __CLASS__, 'render_register_widget' ) );
		}
		if ( self::get( 'protect_lostpassword', 1 ) ) {
			add_action( 'lostpassword_form', array( __CLASS__, 'render_lostpassword_widget' ) );
			add_action( 'woocommerce_lostpassword_form', array( __CLASS__, 'render_lostpassword_widget' ) );
		}
		// 留言／商品評價：**不能**用 comment_form action——它在核心 comment_form() 裡的位置是在
		// 送出按鈕**之後**（wp-includes/comment-template.php，submit_field 先輸出、do_action('comment_form')
		// 才跑），widget 會掉到按鈕下面。改用 comment_form_submit_field filter 把 widget 接在送出欄位
		// 前面，登入與未登入兩種狀態都適用（comment_form_after_fields／comment_form_logged_in_after
		// 各自只在其中一種狀態觸發），WooCommerce 商品評價也是走同一支 comment_form()，一起涵蓋。
		if ( self::get( 'protect_comment', 1 ) ) {
			add_filter( 'comment_form_submit_field', array( __CLASS__, 'render_comment_widget' ), 10, 2 );
			add_filter( 'preprocess_comment', array( __CLASS__, 'verify_comment' ), 10, 1 );
		}

		// Elementor Pro 表單（v1.28.0 新增）。跟上面四種表單的模型不同：**不是**「開關一開就
		// 自動套用到所有表單」，而是註冊成一種欄位型別，由站台自己在 Elementor 編輯器裡挑要
		// 保護的表單、把「Cloudflare Turnstile」欄位加進去（做法與 Elementor 內建的 reCAPTCHA
		// 完全一致）。沒有加這個欄位的表單完全不受影響——validation 那一支第一件事就是查
		// 這次送出的表單有沒有這個欄位，沒有就直接 return。
		//
		// 這三個 hook 只有裝了 Elementor Pro 才會有人觸發，沒裝時掛著也不會有任何作用，
		// 所以不需要 class_exists() 之類的偵測（Elementor 是在 plugins_loaded 之後才註冊
		// 這些 hook 的，用偵測反而要處理載入順序問題）。
		if ( self::get( 'protect_elementor_form', 1 ) ) {
			add_filter( 'elementor_pro/forms/field_types', array( __CLASS__, 'register_elementor_field_type' ) );
			add_action( 'elementor_pro/forms/render_field/' . self::ELEMENTOR_FIELD_TYPE, array( __CLASS__, 'render_elementor_field' ), 10, 3 );
			add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'verify_elementor_form' ), 10, 2 );
		}

		// ── 後端驗證 ──
		// 登入：authenticate filter 一次涵蓋 wp-login.php、WooCommerce 我的帳號（WC_Form_Handler
		// ::process_login() 內部呼叫 wp_signon()）、Blocksy 彈窗（AJAX handler 直接
		// require wp-login.php）三條路徑。priority 30 是刻意排在 WP 核心的帳密比對（20）之後：
		// 核心的 wp_authenticate_username_password() 在帳密都非空時會**無視**既有的 WP_Error
		// 直接覆寫掉 $user，比它早跑的話我們的錯誤會被吃掉。排在後面雖然多做一次帳密比對，
		// 但最終回傳的仍是我們的 WP_Error，密碼正確與否對外表現完全相同，不會變成帳號探測管道。
		add_filter( 'authenticate', array( __CLASS__, 'verify_login' ), 30, 3 );

		// 註冊：WP 原生走 registration_errors（register_new_user() 內），WooCommerce 走
		// woocommerce_process_registration_errors（WC_Form_Handler::process_registration()）。
		// **woocommerce_registration_errors 是必要的第三個**：Blocksy 彈窗註冊雖然有跑
		// woocommerce_process_registration_errors，卻沒有檢查回傳值就直接呼叫 wc_create_new_customer()
		// （blocksy-companion/framework/features/account-auth.php 的 implement_user_registration()），
		// 只有掛在 wc_create_new_customer() 內部的 woocommerce_registration_errors 擋得住它。
		add_filter( 'registration_errors', array( __CLASS__, 'verify_wp_registration' ), 10, 3 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'verify_wc_registration' ), 10 );
		add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'verify_wc_registration' ), 10 );

		// 忘記密碼：WP 核心的 retrieve_password() 與 WooCommerce 的
		// WC_Shortcode_My_Account::retrieve_password() 都會觸發 lostpassword_post，
		// Blocksy 彈窗則是直接呼叫 WC 那一支，一個 hook 三條路徑全涵蓋。
		add_action( 'lostpassword_post', array( __CLASS__, 'verify_lostpassword' ), 10, 1 );
	}

	// ─── 設定讀寫 ───────────────────────────────────────────────────────────

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

	/** 總開關開啟且兩把金鑰都填了才算真的啟用 */
	public static function is_active() {
		return (bool) self::get( 'enabled' ) && self::get( 'site_key' ) && self::get( 'secret_key' );
	}

	public static function register_settings() {
		register_setting( 'wclon_turnstile_settings_group', self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
	}

	public static function sanitize( $input ) {
		$clean               = array();
		$clean['enabled']    = ! empty( $input['enabled'] ) ? 1 : 0;
		$clean['site_key']   = trim( sanitize_text_field( $input['site_key'] ?? '' ) );
		$clean['secret_key'] = trim( sanitize_text_field( $input['secret_key'] ?? '' ) );

		$clean['protect_login']        = ! empty( $input['protect_login'] ) ? 1 : 0;
		$clean['protect_register']     = ! empty( $input['protect_register'] ) ? 1 : 0;
		$clean['protect_lostpassword'] = ! empty( $input['protect_lostpassword'] ) ? 1 : 0;
		$clean['protect_comment']      = ! empty( $input['protect_comment'] ) ? 1 : 0;
		$clean['protect_elementor_form'] = ! empty( $input['protect_elementor_form'] ) ? 1 : 0;

		$allowed_themes  = array( 'auto', 'light', 'dark' );
		$clean['theme']  = in_array( $input['theme'] ?? '', $allowed_themes, true ) ? $input['theme'] : 'auto';
		$allowed_sizes         = array( 'normal', 'flexible', 'compact' );
		$clean['widget_size']  = in_array( $input['widget_size'] ?? '', $allowed_sizes, true ) ? $input['widget_size'] : 'normal';

		$allowed_appearances  = array( 'always', 'interaction-only' );
		$clean['appearance']  = in_array( $input['appearance'] ?? '', $allowed_appearances, true ) ? $input['appearance'] : 'always';

		$clean['error_message'] = sanitize_text_field( $input['error_message'] ?? '' ) ?: self::default_error_message();

		return $clean;
	}

	public static function default_error_message() {
		return '人機驗證未通過，請重新整理頁面後再試一次。';
	}

	// ─── 前台 widget ────────────────────────────────────────────────────────

	/**
	 * Cloudflare 的 api.js 與本外掛的渲染腳本一律無條件載入（只要 Turnstile 已啟用）。
	 *
	 * 不做「只在登入/註冊頁載入」的判斷，是因為 Blocksy 的 header 帳號彈出視窗會在**任何**
	 * 前台頁面的頁尾輸出登入/註冊/忘記密碼三張表單，無法事先判斷哪些頁面會有 widget；而彈窗
	 * 的 markup 是在 wp_footer 輸出的，等到渲染時才 wp_enqueue_script() 有可能已經來不及
	 * （wp_print_footer_scripts 已經跑過），腳本會整個不出現、widget 永遠不會被渲染。
	 * api.js 很小且帶 defer，成本可以接受。
	 */
	public static function enqueue_assets() {
		wp_enqueue_script(
			'wclon-turnstile',
			WCLON_PLUGIN_URL . 'assets/js/wclon-turnstile.js',
			array(),
			WCLON_VERSION,
			false
		);
		// render=explicit：不讓 api.js 自己掃 DOM，全部交給 wclon-turnstile.js 用 turnstile.render()
		// 處理——Blocksy 彈窗送出失敗時整段表單 HTML 會被 AJAX 換掉，隱式渲染只在載入當下掃一次，
		// 換進來的新表單不會有 widget。
		wp_enqueue_script(
			self::API_HANDLE,
			'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=wclonTurnstileOnload',
			array( 'wclon-turnstile' ),
			null,
			false
		);
	}

	/**
	 * api.js 必須帶 defer：它是外部網域的腳本，若用預設的同步載入會擋住頁面解析；
	 * 而 defer 的執行時機保證在 wclon-turnstile.js（一般同步腳本）之後，
	 * onload callback `wclonTurnstileOnload` 一定已經定義好。
	 */
	public static function script_loader_tag( $tag, $handle ) {
		if ( self::API_HANDLE === $handle && ! str_contains( $tag, ' defer' ) ) {
			$tag = str_replace( '<script ', '<script defer ', $tag );
		}
		return $tag;
	}

	public static function render_login_widget() {
		self::render_widget( 'login' );
	}

	public static function render_register_widget() {
		self::render_widget( 'register' );
	}

	public static function render_lostpassword_widget() {
		self::render_widget( 'lostpassword' );
	}

	/**
	 * 留言／商品評價表單。這是 filter 不是 action——回傳「widget + 原本的送出欄位」，
	 * 讓 widget 落在送出按鈕之前。
	 */
	public static function render_comment_widget( $submit_field, $args = array() ) {
		ob_start();
		self::render_widget( 'comment' );
		return ob_get_clean() . $submit_field;
	}

	/**
	 * 只輸出容器；實際的 turnstile.render() 由 assets/js/wclon-turnstile.js 負責，
	 * 容器上的 data-* 就是傳給 render() 的參數。
	 */
	public static function render_widget( $action ) {
		$site_key = self::get( 'site_key' );
		if ( ! $site_key ) {
			return;
		}
		printf(
			'<div class="wclon-turnstile" data-sitekey="%s" data-theme="%s" data-size="%s" data-action="%s" data-appearance="%s"></div>',
			esc_attr( $site_key ),
			esc_attr( self::get( 'theme', 'auto' ) ),
			esc_attr( self::get( 'widget_size', 'normal' ) ),
			esc_attr( $action ),
			esc_attr( self::get( 'appearance', 'always' ) )
		);
	}

	// ─── 驗證 ───────────────────────────────────────────────────────────────

	/**
	 * 只在「瀏覽器真的送出一張表單」時驗證。排除 XML-RPC／REST／WP-CLI／WP-Cron——
	 * 那些情境不會有 Turnstile widget 可解，一律驗證會把正常的程式化流程整個鎖死。
	 */
	private static function is_form_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return false;
		}
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| wp_doing_cron() ) {
			return false;
		}
		return true;
	}

	private static function get_token() {
		if ( ! isset( $_POST[ self::TOKEN_FIELD ] ) || ! is_string( $_POST[ self::TOKEN_FIELD ] ) ) {
			return '';
		}
		return trim( wp_unslash( $_POST[ self::TOKEN_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * 向 Cloudflare 驗證這次請求帶的 token。
	 *
	 * @return true|WP_Error 通過回傳 true，未通過回傳帶有設定頁自訂訊息的 WP_Error。
	 */
	public static function verify_request( $context = '' ) {
		$token = self::get_token();
		$error = new WP_Error( 'wclon_turnstile_failed', self::get( 'error_message', self::default_error_message() ) );

		if ( '' === $token ) {
			return $error;
		}

		$key = md5( $token );
		if ( isset( self::$verified[ $key ] ) ) {
			return self::$verified[ $key ]; // 同一次請求的第二個驗證點，直接沿用結果（token 不能重送）
		}

		$response = wp_remote_post(
			self::VERIFY_ENDPOINT,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => self::get( 'secret_key' ),
					'response' => $token,
					// 刻意**不**送 remoteip：站台若走在 Cloudflare／其他反向代理後面，
					// REMOTE_ADDR 會是代理的 IP，跟 Cloudflare 那邊看到的訪客 IP 對不起來，
					// 送了反而會讓合法的 token 驗證失敗。這個參數本來就是選填的。
				),
			)
		);

		// 連不上 Cloudflare（DNS／防火牆／逾時）時一律放行並記錄——驗證服務的短暫故障
		// 不應該讓整站沒有人能登入或註冊。真正無效的 token 仍然會被下面的 success 判斷擋下。
		if ( is_wp_error( $response ) ) {
			self::log( 'siteverify 連線失敗，本次放行：' . $response->get_error_message() . ( $context ? " (context: {$context})" : '' ) );
			return self::$verified[ $key ] = true;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			self::log( 'siteverify 回應非 200（' . $code . '），本次放行' . ( $context ? " (context: {$context})" : '' ) );
			return self::$verified[ $key ] = true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			self::log( 'siteverify 回應無法解析為 JSON，本次放行' . ( $context ? " (context: {$context})" : '' ) );
			return self::$verified[ $key ] = true;
		}

		if ( empty( $body['success'] ) ) {
			$codes = isset( $body['error-codes'] ) && is_array( $body['error-codes'] )
				? implode( ',', array_map( 'sanitize_text_field', $body['error-codes'] ) )
				: '(無 error-codes)';
			self::log( '驗證未通過：' . $codes . ( $context ? " (context: {$context})" : '' ) );
			return self::$verified[ $key ] = $error;
		}

		return self::$verified[ $key ] = true;
	}

	private static function log( $message ) {
		error_log( 'WCLON Turnstile: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * 登入驗證（authenticate filter，priority 30，理由見 init() 的註解）。
	 */
	public static function verify_login( $user, $username = '', $password = '' ) {
		if ( ! self::get( 'protect_login', 1 ) || ! self::is_form_post() ) {
			return $user;
		}
		// 只在「看起來真的是登入表單送出」時才驗證：wp-login.php 與 Blocksy 彈窗用 log，
		// WooCommerce 我的帳號用 username。其他程式化呼叫 wp_signon() 的情境（$_POST 裡不會有
		// 這兩個欄位）維持原本行為——本外掛的 LINE／Google／Apple 登入是直接
		// wp_set_auth_cookie()，根本不會經過 authenticate，不受影響。
		if ( ! isset( $_POST['log'] ) && ! isset( $_POST['username'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $user;
		}

		$result = self::verify_request( 'login' );
		return is_wp_error( $result ) ? $result : $user;
	}

	/** WP 原生註冊（register_new_user()，涵蓋 wp-login.php?action=register 與 Blocksy 彈窗的 wp 策略） */
	public static function verify_wp_registration( $errors, $sanitized_user_login = '', $user_email = '' ) {
		if ( ! self::get( 'protect_register', 1 ) || ! self::is_form_post() ) {
			return $errors;
		}
		$result = self::verify_request( 'register' );
		if ( is_wp_error( $result ) && is_wp_error( $errors ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}
		return $errors;
	}

	/**
	 * WooCommerce 註冊。同時掛在 woocommerce_process_registration_errors（我的帳號表單的
	 * 前置驗證）與 woocommerce_registration_errors（wc_create_new_customer() 內部，Blocksy
	 * 彈窗唯一擋得住的點）。兩者都觸發時第二次會直接吃 self::$verified 的記憶，不會重送 token。
	 */
	public static function verify_wc_registration( $errors ) {
		if ( ! self::get( 'protect_register', 1 ) || ! self::is_form_post() ) {
			return $errors;
		}
		$result = self::verify_request( 'register' );
		if ( is_wp_error( $result ) && is_wp_error( $errors ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}
		return $errors;
	}

	/**
	 * 留言／商品評價驗證（preprocess_comment，涵蓋文章留言與 WooCommerce 商品評價——
	 * 商品評價在 WordPress 裡本來就是 comment）。
	 *
	 * 留言送出流程沒有可以「附加錯誤訊息」的機制，核心自己在
	 * wp_allow_comment() 判定重複/洗版時也是直接 wp_die()，這裡照同樣的方式處理。
	 */
	public static function verify_comment( $commentdata ) {
		if ( ! self::get( 'protect_comment', 1 ) || ! self::is_form_post() ) {
			return $commentdata;
		}
		// 只在「留言表單真的送出」時驗證：comment_post_ID 是留言表單一定會帶的欄位。
		// 程式化建立留言的情境（WooCommerce 的訂單備注是直接呼叫 wp_insert_comment()，
		// 根本不經過 preprocess_comment）不受影響。
		if ( ! isset( $_POST['comment_post_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $commentdata;
		}

		$result = self::verify_request( 'comment' );
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( '留言送出失敗', 'ultimate-login' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		return $commentdata;
	}

	/** 忘記密碼（WP 核心與 WooCommerce 的 retrieve_password() 都會觸發） */
	public static function verify_lostpassword( $errors ) {
		if ( ! self::get( 'protect_lostpassword', 1 ) || ! self::is_form_post() ) {
			return;
		}
		$result = self::verify_request( 'lostpassword' );
		if ( is_wp_error( $result ) && is_wp_error( $errors ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}
	}

	// ─── Elementor Pro 表單（v1.28.0 新增） ─────────────────────────────────

	/**
	 * 把「Cloudflare Turnstile」加進 Elementor 表單 widget 的欄位型別下拉選單。
	 *
	 * 刻意用 filter + `render_field/{type}` action 這組較低階的 API，而不是繼承
	 * `ElementorPro\Modules\Forms\Fields\Field_Base`：繼承的話這個檔案在被 require 的當下
	 * （plugins_loaded，早於 Elementor 註冊自己的 autoload）就必須找得到那個父類別，
	 * 沒裝 Elementor Pro 的站台會直接 fatal error。用 hook 就完全沒有這個相依問題。
	 */
	public static function register_elementor_field_type( $field_types ) {
		$field_types[ self::ELEMENTOR_FIELD_TYPE ] = 'Cloudflare Turnstile';
		return $field_types;
	}

	/**
	 * 輸出 Elementor 表單裡的 Turnstile 欄位。
	 *
	 * 外層包一層 Elementor 自己的 `.elementor-field` + `form-field-{custom_id}`，讓它跟其他
	 * 欄位共用同一套版面（欄寬、間距）；裡面就是本外掛四種表單共用的那個容器，實際渲染一樣
	 * 交給 assets/js/wclon-turnstile.js（它監看整份 DOM，Elementor 何時把表單放進畫面都接得住）。
	 *
	 * @param array  $item       欄位設定（含 custom_id）。
	 * @param int    $item_index 欄位在表單中的索引。
	 * @param object $widget     Elementor 表單 widget 實例。
	 */
	public static function render_elementor_field( $item, $item_index, $widget ) {
		$custom_id = isset( $item['custom_id'] ) ? $item['custom_id'] : self::ELEMENTOR_FIELD_TYPE . '-' . $item_index;
		printf( '<div class="elementor-field" id="form-field-%s">', esc_attr( $custom_id ) );
		self::render_widget( 'elementor_form' );
		echo '</div>';
	}

	/**
	 * Elementor 表單送出時的後端驗證（`elementor_pro/forms/validation`）。
	 *
	 * **先查這張表單有沒有 Turnstile 欄位，沒有就直接放行**——這是「只保護站台自己挑的表單」
	 * 這個設計的關鍵，也讓沒加欄位的舊表單完全不受影響（不會突然變成全部送不出去）。
	 *
	 * 驗證通過後把欄位從 record 移除，否則這個沒有值的欄位會出現在通知信與 Elementor 的
	 * 表單記錄裡（Elementor 內建的 reCAPTCHA 也是這樣處理）。
	 *
	 * @param object $record       Elementor 的 Form_Record。
	 * @param object $ajax_handler Elementor 的 Ajax_Handler，用 add_error() 擋下送出。
	 */
	public static function verify_elementor_form( $record, $ajax_handler ) {
		if ( ! self::get( 'protect_elementor_form', 1 ) || ! self::is_form_post() ) {
			return;
		}
		if ( ! is_object( $record ) || ! method_exists( $record, 'get_field' ) || ! is_object( $ajax_handler ) ) {
			return;
		}

		$fields = $record->get_field( array( 'type' => self::ELEMENTOR_FIELD_TYPE ) );
		if ( empty( $fields ) ) {
			return; // 這張表單沒有加 Turnstile 欄位，不是我們要管的表單
		}
		$field = current( $fields );

		$result = self::verify_request( 'elementor_form' );
		if ( is_wp_error( $result ) ) {
			$ajax_handler->add_error( $field['id'], $result->get_error_message() );
			return;
		}

		if ( method_exists( $record, 'remove_field' ) ) {
			$record->remove_field( $field['id'] );
		}
	}

	// ─── 設定頁渲染（嵌入 WCLON_Settings 設定頁的「Turnstile」分頁，見該檔案 render_page()） ──

	public static function render_tab_content() {
		$enabled       = self::get( 'enabled' );
		$site_key      = self::get( 'site_key' );
		$secret_key    = self::get( 'secret_key' );
		$theme         = self::get( 'theme', 'auto' );
		$widget_size   = self::get( 'widget_size', 'normal' );
		$appearance    = self::get( 'appearance', 'always' );
		$error_message = self::get( 'error_message', self::default_error_message() );
		?>
		<form method="post" action="options.php" id="wclon-turnstile-settings-form">
			<?php settings_fields( 'wclon_turnstile_settings_group' ); ?>

			<div class="wclon-card">
				<h2 class="wclon-card__title">Cloudflare Turnstile</h2>
				<p class="wclon-card__desc">在登入／註冊／忘記密碼／留言表單加上 Cloudflare 的人機驗證，阻擋自動化的暴力破解、大量假帳號註冊與垃圾留言。金鑰請到 <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Cloudflare 後台 → Turnstile</a> 新增網站取得（Widget Mode 選 Managed 即可，外觀由 Cloudflare 那邊決定）。</p>
				<table class="form-table">
					<tr>
						<th scope="row">啟用 Turnstile</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $enabled, 1 ); ?>> 啟用人機驗證</label>
							<p class="description">關閉、或下方兩把金鑰任一沒填時，整個功能完全不掛載（表單不會出現 widget，也不會有任何驗證），不會有「半套」把顧客擋在門外的狀況。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wclon_turnstile_site_key">Site Key</label></th>
						<td><input type="text" id="wclon_turnstile_site_key" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[site_key]" value="<?php echo esc_attr( $site_key ); ?>" placeholder="0x4AAAAAAA..."></td>
					</tr>
					<tr>
						<th scope="row"><label for="wclon_turnstile_secret_key">Secret Key</label></th>
						<td>
							<input type="password" id="wclon_turnstile_secret_key" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[secret_key]" value="<?php echo esc_attr( $secret_key ); ?>" autocomplete="new-password">
							<p class="description">只用於後端向 Cloudflare 驗證，不會輸出到前台頁面。</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="wclon-card">
				<h2 class="wclon-card__title">保護哪些表單</h2>
				<p class="wclon-card__desc">四種內建表單各自獨立、開關一開就自動生效。帳號類三種涵蓋 WordPress 登入頁（<code>wp-login.php</code>）、WooCommerce 我的帳號，以及佈景主題 Blocksy 標頭的帳號彈出視窗；留言類涵蓋文章留言與 WooCommerce 商品評價。最下面的 Elementor 表單是另一種模式——由你在編輯器裡逐張表單決定要不要加，說明見該列。</p>
				<table class="form-table">
					<tr>
						<th scope="row">套用範圍</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[protect_login]" value="1" <?php checked( self::get( 'protect_login', 1 ), 1 ); ?>> 登入表單</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[protect_register]" value="1" <?php checked( self::get( 'protect_register', 1 ), 1 ); ?>> 註冊表單</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[protect_lostpassword]" value="1" <?php checked( self::get( 'protect_lostpassword', 1 ), 1 ); ?>> 忘記密碼表單</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[protect_comment]" value="1" <?php checked( self::get( 'protect_comment', 1 ), 1 ); ?>> 留言／商品評價表單</label>
							<p class="description">不影響 LINE／Google／Apple 社交登入——那三種是導向對方網站授權後直接建立登入狀態，不經過帳密表單，本來就沒有暴力破解的空間。「留言／商品評價」一併涵蓋文章留言與 WooCommerce 商品評價（兩者在 WordPress 裡都是 comment），驗證未通過時會顯示錯誤頁並提供「返回上一頁」連結。結帳頁下單目前不在保護範圍內。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Elementor 表單</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[protect_elementor_form]" value="1" <?php checked( self::get( 'protect_elementor_form', 1 ), 1 ); ?>> 提供 Elementor 表單的 Turnstile 欄位</label>
							<p class="description"><strong>跟上面四種不一樣，這個開關本身不會保護任何表單</strong>，它只是讓 Elementor 表單 widget 的「欄位型別」下拉選單多出一個 <code>Cloudflare Turnstile</code> 選項（做法與 Elementor 內建的 reCAPTCHA 完全相同）。<br>實際保護哪張表單由你決定：用 Elementor 編輯要保護的表單 → 表單 widget → 欄位 → 新增項目 → 類型選「Cloudflare Turnstile」（建議放在送出按鈕前的最後一個欄位）。<strong>沒有加這個欄位的表單完全不受影響</strong>，不會突然送不出去。<br>需要 Elementor <strong>Pro</strong>（表單 widget 是 Pro 功能）；沒安裝 Elementor 時這個開關沒有任何作用。</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="wclon-card">
				<h2 class="wclon-card__title">外觀與文案</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wclon_turnstile_theme">配色</label></th>
						<td>
							<select id="wclon_turnstile_theme" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[theme]">
								<option value="auto" <?php selected( $theme, 'auto' ); ?>>自動（跟隨瀏覽器深色模式）</option>
								<option value="light" <?php selected( $theme, 'light' ); ?>>淺色</option>
								<option value="dark" <?php selected( $theme, 'dark' ); ?>>深色</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wclon_turnstile_size">尺寸</label></th>
						<td>
							<select id="wclon_turnstile_size" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[widget_size]">
								<option value="normal" <?php selected( $widget_size, 'normal' ); ?>>標準（300×65）</option>
								<option value="flexible" <?php selected( $widget_size, 'flexible' ); ?>>彈性寬度（跟隨表單寬度）</option>
								<option value="compact" <?php selected( $widget_size, 'compact' ); ?>>精簡（150×140）</option>
							</select>
							<p class="description">Blocksy 的帳號彈出視窗寬度較窄，若標準尺寸會被裁切，改用「彈性寬度」。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wclon_turnstile_appearance">顯示時機</label></th>
						<td>
							<select id="wclon_turnstile_appearance" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[appearance]">
								<option value="always" <?php selected( $appearance, 'always' ); ?>>一律顯示</option>
								<option value="interaction-only" <?php selected( $appearance, 'interaction-only' ); ?>>只在需要時才顯示（推薦）</option>
							</select>
							<p class="description">Cloudflare 官方支援的顯示模式，不是自訂樣式（Turnstile 的邊框／圖示／文字本身無法客製化，這是 Cloudflare 刻意的安全限制，避免有人用 CSS 偽造「已驗證」畫面）。選「只在需要時才顯示」時，Cloudflare 判斷不需要真人互動就能通過的顧客，畫面上<strong>完全不會出現方塊</strong>（背景無感驗證，效果跟現在多數顧客看到的「無感通過」一樣），只有真的被判定可疑時才會跳出方塊要求驗證——多數正常顧客不會再看到這個方塊，不影響驗證強度。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wclon_turnstile_error_message">驗證失敗訊息</label></th>
						<td>
							<input type="text" id="wclon_turnstile_error_message" class="large-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[error_message]" value="<?php echo esc_attr( $error_message ); ?>">
							<p class="description">顧客沒有完成驗證、或 token 已過期時顯示的錯誤訊息。</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="wclon-card">
				<h2 class="wclon-card__title">連線失敗時的行為</h2>
				<p class="wclon-card__desc">向 Cloudflare <code>siteverify</code> 驗證時如果連不上（DNS、防火牆、逾時、回應非 200），本次一律<strong>放行</strong>並寫入 PHP <code>error_log</code>（訊息前綴 <code>WCLON Turnstile:</code>）。驗證服務的短暫故障不應該讓整站沒有人能登入或註冊；顧客沒解或 token 無效這種「確定驗證未通過」的情況仍然照常擋下。</p>
			</div>
		</form>
		<?php
	}
}
