<?php
/**
 * 接收 LINE Messaging API Webhook，用來自動擷取群組/聊天室 ID
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAN_Webhook {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_request' ) );
	}

	public static function handle_request() {
		if ( empty( $_GET['wcan_action'] ) || 'webhook' !== sanitize_text_field( wp_unslash( $_GET['wcan_action'] ) ) ) {
			return;
		}

		$raw_body  = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_LINE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LINE_SIGNATURE'] ) ) : '';
		$secret    = WCAN_Settings::get( 'messaging_channel_secret' );

		if ( ! $secret || ! $signature || ! self::verify_signature( $raw_body, $signature, $secret ) ) {
			status_header( 403 );
			exit;
		}

		$data   = json_decode( $raw_body, true );
		$events = is_array( $data ) && isset( $data['events'] ) ? (array) $data['events'] : array();

		foreach ( $events as $event ) {
			self::maybe_capture_group( $event );
			self::maybe_issue_line_bind_coupon( $event );
		}

		status_header( 200 );
		exit;
	}

	/**
	 * 驗證 X-Line-Signature：base64( HMAC-SHA256( raw body, channel secret ) )
	 */
	private static function verify_signature( $body, $signature, $secret ) {
		$hash = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
		return hash_equals( $hash, $signature );
	}

	/**
	 * 事件來源若是群組/聊天室，記錄其 ID（join 事件、或群組內任何訊息事件皆可觸發）
	 * 群組（group）可透過 Messaging API 查到顯示名稱一併存起來；多人聊天室（room）在 LINE 裡沒有名稱可查
	 */
	private static function maybe_capture_group( $event ) {
		$source = is_array( $event ) && isset( $event['source'] ) ? $event['source'] : array();
		$type   = $source['type'] ?? '';

		if ( 'group' === $type && ! empty( $source['groupId'] ) ) {
			$group_id = sanitize_text_field( $source['groupId'] );
			WCAN_Settings::add_group_id( $group_id, WCAN_Settings::fetch_group_name( $group_id ) );
		} elseif ( 'room' === $type && ! empty( $source['roomId'] ) ) {
			WCAN_Settings::add_group_id( sanitize_text_field( $source['roomId'] ) );
		}
	}

	/**
	 * 顧客加官方帳號好友時（LINE 的 follow 事件），補發綁定當下因尚未加好友
	 * 而被跳過的「LINE 綁定歡迎優惠券」（見 WCLON_Line_Login::maybe_issue_bind_coupon()
	 * 的 $is_friend 參數）。這個 webhook 端點是整個 Messaging API channel 共用的單一
	 * 接收點（v1.16.3 起不只處理管理員群組通知相關的 group/room join 事件），
	 * 沒有其他地方在收 follow 事件，因此放在這裡處理，而不是另開一個 WCLON 專屬的
	 * webhook class；找不到 source.userId 或該 LINE ID 從未綁定過會員時內部直接跳過。
	 */
	private static function maybe_issue_line_bind_coupon( $event ) {
		if ( ! isset( $event['type'] ) || 'follow' !== $event['type'] ) {
			return;
		}
		$source = is_array( $event ) && isset( $event['source'] ) ? $event['source'] : array();
		if ( 'user' !== ( $source['type'] ?? '' ) || empty( $source['userId'] ) ) {
			return;
		}
		if ( ! class_exists( 'WCLON_Line_Login' ) ) {
			return;
		}
		WCLON_Line_Login::maybe_issue_bind_coupon_for_line_id( sanitize_text_field( $source['userId'] ) );
	}
}
