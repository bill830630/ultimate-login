<?php
/**
 * WooCommerce 相容層（v1.42.0 新增）。
 *
 * 外掛在沒有安裝 WooCommerce 的網站也能啟用：社交登入（wp-login.php、短代碼）、管理員 LINE
 * 群組的網站表單通知、Turnstile、系統信件照常運作；訂單通知、綁定歡迎優惠券、結帳／購物車綁定列、
 * 會員中心「帳號綁定」頁這些只有 WooCommerce 才有的功能則整個不註冊。
 *
 * 其他 class 需要 WooCommerce 函式時一律走這裡，不直接呼叫 wc_*()，沒有 WooCommerce 時才不會 fatal。
 * active() 只在 plugins_loaded 之後才準（WooCommerce 是在自己的主檔案被載入時宣告 class）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_WC {

	public static function active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * 設定頁與 AJAX 的權限。沒有 WooCommerce 時 manage_woocommerce 這個權限根本不存在
	 * （連管理員都沒有），要退回 manage_options，否則誰都進不了設定頁。
	 */
	public static function capability() {
		return self::active() ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * 會員帳戶頁網址。沒有 WooCommerce 時退回 WordPress 的個人資料頁，那裡也能補填 Email。
	 */
	public static function account_url( $endpoint = 'dashboard' ) {
		if ( self::active() ) {
			return wc_get_account_endpoint_url( $endpoint );
		}
		return 'dashboard' === $endpoint ? admin_url() : admin_url( 'profile.php' );
	}

	public static function myaccount_url() {
		return self::active() ? wc_get_page_permalink( 'myaccount' ) : admin_url();
	}

	/** OAuth 沒帶 redirect 時的預設導回網址 */
	public static function default_redirect() {
		return self::active() ? wc_get_checkout_url() : home_url( '/' );
	}

	/**
	 * 綁定失敗的錯誤提示。WooCommerce 用通知佇列，呼叫端接著導回原頁；沒有 WooCommerce 時
	 * 沒有前台通知機制，直接 wp_die() 顯示訊息與「返回」連結（wp_die 本身會結束請求）。
	 */
	public static function error_notice( $message ) {
		if ( self::active() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
			return;
		}
		wp_die( esc_html( $message ), '終極登入', array( 'response' => 403, 'back_link' => true ) );
	}
}
