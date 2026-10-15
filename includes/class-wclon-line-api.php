<?php
/**
 * LINE Messaging API push 共用層（v1.45.0 新增）：顧客通知（WCLON_Notifier）與管理員群組通知
 * （WCAN_Notifier）原本各有一份 push_message()，錯誤說明不一致，統一收在這裡。
 *
 * - push()：同步發送，回傳 true 或錯誤字串（測試推播等需要立即結果的地方用）
 * - queue()：排進 Action Scheduler 背景發送，結帳／改狀態／表單送出不用等 LINE 回應；
 *   網路錯誤或 LINE 5xx 自動重試，重試帶同一個 X-Line-Retry-Key，LINE 端保證不重複送達。
 *   沒有 Action Scheduler（未裝 WooCommerce）時退回同步發送。
 * - 401（Token 失效）／429（本月額度用完）是整站推播都會失敗的錯誤，記在 ALERT_OPTION，
 *   後台每頁顯示提醒，之後任何一次推播成功或 Token 更換就自動清除。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Line_API {

	const PUSH_URL        = 'https://api.line.me/v2/bot/message/push';
	const ACTION_HOOK     = 'wclon_line_push';
	const ACTION_GROUP    = 'wclon-line';
	const PAYLOAD_PREFIX  = 'wclon_push_';
	const ALERT_OPTION    = 'wclon_line_api_alert';
	const QUOTA_TRANSIENT = 'wclon_line_quota';

	/** 暫時性失敗的重試間隔（秒），用完就放棄 */
	private static $retry_delays = array( 60, 300 );

	public static function init() {
		add_action( self::ACTION_HOOK, array( __CLASS__, 'run_queued' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'update_option_' . WCLON_Settings::OPTION_KEY, array( __CLASS__, 'on_settings_updated' ), 10, 2 );
	}

	/**
	 * 排進背景發送。$context 會原樣傳給失敗時的 `wclon_line_push_failed` action，
	 * 讓呼叫端自行善後（例如撤銷「已發送」紀錄）；$label 只用在錯誤記錄。
	 */
	public static function queue( $to, array $message, $label, array $context = array() ) {
		$key     = wp_generate_uuid4(); // 同時當 X-Line-Retry-Key（LINE 要求 UUID）
		$payload = array(
			'to'      => $to,
			'message' => $message,
			'label'   => $label,
			'context' => $context,
		);

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::finish( $payload, self::push( $to, $message ) );
			return;
		}

		// 訊息內容可能超過 Action Scheduler 參數上限（8000 字元，中文 JSON 跳脫後很容易超過），
		// 存在不自動載入的 option，參數只帶 key。
		add_option( self::PAYLOAD_PREFIX . $key, $payload, '', false );
		if ( ! as_enqueue_async_action( self::ACTION_HOOK, array( $key, 0 ), self::ACTION_GROUP ) ) {
			// Action Scheduler 還沒初始化（太早呼叫）等情況會回傳 0，改同步發送，不讓訊息卡住
			delete_option( self::PAYLOAD_PREFIX . $key );
			self::finish( $payload, self::push( $to, $message ) );
		}
	}

	/** Action Scheduler 執行背景發送 */
	public static function run_queued( $key, $attempt = 0 ) {
		$payload = get_option( self::PAYLOAD_PREFIX . $key );
		if ( ! is_array( $payload ) ) {
			return;
		}

		$result = self::push( $payload['to'], $payload['message'], $key, $transient );
		if ( true !== $result && $transient && isset( self::$retry_delays[ $attempt ] ) ) {
			as_schedule_single_action( time() + self::$retry_delays[ $attempt ], self::ACTION_HOOK, array( $key, $attempt + 1 ), self::ACTION_GROUP );
			return;
		}

		delete_option( self::PAYLOAD_PREFIX . $key );
		self::finish( $payload, $result );
	}

	private static function finish( array $payload, $result ) {
		if ( true === $result ) {
			return;
		}
		error_log( 'WCLON LINE 推播失敗（' . $payload['label'] . '）：' . $result ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		do_action( 'wclon_line_push_failed', $payload['context'], $result );
	}

	/**
	 * 呼叫 push endpoint
	 *
	 * @param string      $to        User／群組／聊天室 ID
	 * @param array       $message   LINE message 物件
	 * @param string|null $retry_key 同一則訊息重試時帶相同值，LINE 保證只送達一次
	 * @param bool        $transient 輸出：失敗是否為暫時性（值得重試）
	 * @return true|string true 表示成功，字串為錯誤訊息
	 */
	public static function push( $to, array $message, $retry_key = null, &$transient = false ) {
		$transient = false;
		$token     = WCLON_Settings::get( 'channel_access_token' );
		if ( ! $token ) {
			return '尚未設定 Channel Access Token。';
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		);
		if ( $retry_key ) {
			$headers['X-Line-Retry-Key'] = $retry_key;
		}

		$response = wp_remote_post( self::PUSH_URL, array(
			'timeout' => 15,
			'headers' => $headers,
			'body'    => wp_json_encode( array(
				'to'       => $to,
				'messages' => array( $message ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			$transient = true;
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// 409：同一個 Retry Key 先前已被 LINE 受理（前一次其實送到了，只是回應沒收到）
		if ( 200 === $code || ( 409 === $code && $retry_key ) ) {
			delete_option( self::ALERT_OPTION );
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = $body['message'] ?? '未知錯誤';
		$room = in_array( substr( (string) $to, 0, 1 ), array( 'C', 'R' ), true );

		if ( 400 === $code && 'Failed to send messages' === $msg ) {
			// LINE 對「ID 格式正確但送不到」只回這句（已實測）
			$msg = $room
				? '無法送達：官方帳號不在這個群組/聊天室裡（可能已被移出或群組已解散），或這個 ID 屬於另一個官方帳號'
				: '無法送達：這個 LINE ID 可能屬於另一個官方帳號';
		} elseif ( 400 === $code && str_contains( $msg, "'to'" ) ) {
			$msg = $room ? '群組/聊天室 ID 格式不正確' : 'LINE ID 格式不正確';
		} elseif ( 403 === $code ) {
			$msg .= '（對方可能尚未加官方帳號好友，或已封鎖）';
		} elseif ( 429 === $code ) {
			$msg .= '（已達本月訊息額度上限）';
		} elseif ( 401 === $code ) {
			$msg .= '（Access Token 無效或過期）';
		} elseif ( $code >= 500 ) {
			$transient = true;
		}

		if ( 401 === $code || 429 === $code ) {
			update_option( self::ALERT_OPTION, array( 'code' => $code, 'time' => time() ), false );
		}

		return sprintf( 'HTTP %d - %s', $code, $msg );
	}

	/** Token 更換後，舊 Token 的錯誤提醒與額度快取都不再適用 */
	public static function on_settings_updated( $old, $new ) {
		if ( ( $old['channel_access_token'] ?? '' ) !== ( $new['channel_access_token'] ?? '' ) ) {
			delete_option( self::ALERT_OPTION );
			delete_transient( self::QUOTA_TRANSIENT );
		}
	}

	/**
	 * 本月訊息用量，快取 10 分鐘。
	 *
	 * @return array|null { used:int, limit:int|null（null 表示無上限） }，查不到時 null
	 */
	public static function get_quota() {
		$token = WCLON_Settings::get( 'channel_access_token' );
		if ( ! $token ) {
			return null;
		}
		$cached = get_transient( self::QUOTA_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$args  = array(
			'timeout' => 5,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		);
		$quota = wp_remote_get( 'https://api.line.me/v2/bot/message/quota', $args );
		$usage = wp_remote_get( 'https://api.line.me/v2/bot/message/quota/consumption', $args );
		if ( is_wp_error( $quota ) || is_wp_error( $usage )
			|| 200 !== wp_remote_retrieve_response_code( $quota ) || 200 !== wp_remote_retrieve_response_code( $usage ) ) {
			return null;
		}
		$quota = json_decode( wp_remote_retrieve_body( $quota ), true );
		$usage = json_decode( wp_remote_retrieve_body( $usage ), true );

		$data = array(
			'used'  => (int) ( $usage['totalUsage'] ?? 0 ),
			'limit' => 'limited' === ( $quota['type'] ?? '' ) ? (int) $quota['value'] : null,
		);
		set_transient( self::QUOTA_TRANSIENT, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/** 設定頁 Token 欄位下方的用量說明（查不到時不輸出） */
	public static function render_quota_line() {
		$quota = self::get_quota();
		if ( ! $quota ) {
			return;
		}
		if ( null === $quota['limit'] ) {
			$text = sprintf( '本月已發送 %s 則訊息（方案無上限）。', number_format_i18n( $quota['used'] ) );
		} else {
			$text = sprintf(
				'本月已發送 %1$s／%2$s 則訊息，剩餘 %3$s 則。',
				number_format_i18n( $quota['used'] ),
				number_format_i18n( $quota['limit'] ),
				number_format_i18n( max( 0, $quota['limit'] - $quota['used'] ) )
			);
		}
		echo '<p class="description"><strong>' . esc_html( $text ) . '</strong>（每 10 分鐘更新）</p>';
	}

	public static function render_admin_notice() {
		if ( ! current_user_can( WCLON_WC::capability() ) ) {
			return;
		}
		$alert = get_option( self::ALERT_OPTION );
		if ( ! is_array( $alert ) ) {
			return;
		}
		// Token 欄位所在頁籤，邏輯同 WCLON_Settings::render_page() 的 $token_in_admin
		$tab = WCLON_Settings::module_enabled( 'social_login' ) && WCLON_WC::active() ? 'customer' : 'adminline';
		$url = admin_url( 'admin.php?page=wclon-settings' ) . '#' . $tab;

		if ( 401 === (int) $alert['code'] ) {
			$text = 'LINE Channel Access Token 無效或已過期，所有 LINE 通知都送不出去。請到設定頁更新 Token。';
		} else {
			$text = '本月 LINE 官方帳號的訊息額度已用完，在下個月額度重置或升級方案前，LINE 通知都送不出去。';
		}
		printf(
			'<div class="notice notice-error"><p><strong>終極登入：</strong>%s <a href="%s">前往設定</a>（%s 發生）</p></div>',
			esc_html( $text ),
			esc_url( $url ),
			esc_html( wp_date( 'Y-m-d H:i', (int) $alert['time'] ) )
		);
	}
}
