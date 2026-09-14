<?php
/**
 * 自架更新通道：讓外掛能像 WordPress.org 上的外掛一樣，在後台「外掛」頁顯示更新提示。
 *
 * 底層用 YahnisElsts/plugin-update-checker（`vendor/plugin-update-checker/`）串接公開的
 * GitHub repo（bill830630/ultimate-login），比對 repo 的 GitHub Release 版本與目前安裝的
 * `Version:` 標頭，有新版就照 WordPress 原生的更新流程走（後台顯示「有可用的更新」、
 * 一鍵更新會直接抓 Release 附加的 zip 覆蓋安裝）。
 *
 * **v1.33.0 起 repo 改為公開**，`WCLON_GITHUB_TOKEN` 常數變成**選填**：沒設定一樣能正常
 * 檢查更新（公開 repo 的內容/Release API 本來就不需要驗證身份），設定了則會用來呼叫
 * GitHub API（可以拉高請求頻率上限，多個網站共用同一顆對外 IP 時比較不會撞到 GitHub 對
 * 未驗證請求的頻率限制）。
 *
 * 排除機制（見 CLAUDE.md「自架更新通道」一節）：
 *   1. `wp-config.php` 定義 `WCLON_DISABLE_UPDATES`（任何 truthy 值）——完全在該網站本地
 *      判斷，不對外發任何請求，最後一道保險。
 *   2. repo 裡的 `update-config.json`（`disabled_site_hashes`）——集中管理，站主可以在
 *      GitHub 網頁直接編輯，不用逐一登入每個網站後台。**存的是網域的 SHA-256 雜湊值，
 *      不是明文網域**：repo 公開之後，這份清單任何人都看得到，不能直接寫真實網域（等於
 *      公開告訴大家「這幾個網站在用這個外掛」）。每個網站拿自己的網域算雜湊值來比對，
 *      不需要額外設定「我是誰」。
 * 兩者任一成立就跳過整個更新檢查器的註冊。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Updater {

	const GITHUB_REPO_SLUG        = 'bill830630/ultimate-login';
	const DISABLED_LIST_CACHE_KEY = 'wclon_update_disabled_sites';
	const DISABLED_LIST_CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		if ( defined( 'WCLON_DISABLE_UPDATES' ) && WCLON_DISABLE_UPDATES ) {
			return;
		}

		if ( self::is_current_site_excluded() ) {
			return;
		}

		require_once WCLON_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

		$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/' . self::GITHUB_REPO_SLUG . '/',
			WCLON_PLUGIN_DIR . 'ultimate-login.php',
			'ultimate-login'
		);

		if ( defined( 'WCLON_GITHUB_TOKEN' ) && '' !== WCLON_GITHUB_TOKEN ) {
			$update_checker->setAuthentication( WCLON_GITHUB_TOKEN );
		}

		// 只認附在 GitHub Release 上、檔名結尾是 .zip 的附件（發版流程固定這樣命名），
		// 不要讓它退回去抓 repo 本身的原始碼壓縮檔（那份沒有正確的頂層資料夾名稱）。
		$update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
	}

	/**
	 * 讀取 repo 裡的 update-config.json，判斷目前這個網站的網域雜湊值是否在停用清單裡。
	 *
	 * 失敗（連不上、檔案格式跑掉）一律當作「沒有被排除」（fail open）——暫時性的網路
	 * 問題不該讓某個站誤以為自己被排除、永遠看不到更新提示；真的要保證某站絕對不更新，
	 * 請用上面的 WCLON_DISABLE_UPDATES 常數當雙保險。
	 */
	private static function is_current_site_excluded() {
		$disabled_hashes = get_transient( self::DISABLED_LIST_CACHE_KEY );

		if ( false === $disabled_hashes || ! is_array( $disabled_hashes ) ) {
			$disabled_hashes = self::fetch_disabled_site_hashes();
			// 即使抓失敗也快取一份空陣列，短時間內不會重複打 GitHub API（避免 rate limit）。
			set_transient( self::DISABLED_LIST_CACHE_KEY, $disabled_hashes, self::DISABLED_LIST_CACHE_TTL );
		}

		$current_host = self::normalize_host( wp_parse_url( home_url(), PHP_URL_HOST ) );

		return in_array( self::hash_host( $current_host ), $disabled_hashes, true );
	}

	private static function fetch_disabled_site_hashes() {
		$args = array(
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'ultimate-login-update-checker',
			),
			'timeout' => 8,
		);

		// 選填：有設定就帶上，拉高 GitHub API 的請求頻率上限；公開 repo 沒有這個 header
		// 一樣讀得到內容。
		if ( defined( 'WCLON_GITHUB_TOKEN' ) && '' !== WCLON_GITHUB_TOKEN ) {
			$args['headers']['Authorization'] = 'token ' . WCLON_GITHUB_TOKEN;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO_SLUG . '/contents/update-config.json',
			$args
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) || empty( $payload['content'] ) ) {
			return array();
		}

		$config = json_decode( base64_decode( $payload['content'] ), true );

		if ( ! is_array( $config ) || empty( $config['disabled_site_hashes'] ) || ! is_array( $config['disabled_site_hashes'] ) ) {
			return array();
		}

		return array_map( 'strtolower', array_map( 'trim', $config['disabled_site_hashes'] ) );
	}

	private static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		return preg_replace( '#^www\.#', '', $host );
	}

	private static function hash_host( $host ) {
		return hash( 'sha256', $host );
	}
}
