<?php
/**
 * 新訂單建立時，透過 LINE Messaging API 推播通知給管理員
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAN_Notifier {

	private static $header_color = '#4A90D9';

	public static function init() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'maybe_notify_admins' ) );
	}

	/**
	 * 網站有新訂單建立時，通知已記錄的群組/聊天室
	 */
	public static function maybe_notify_admins( $order ) {
		if ( ! WCAN_Settings::get( 'enabled', 1 ) ) {
			return;
		}
		if ( $order->get_meta( '_wcan_notified' ) ) {
			return;
		}

		$recipients = (array) WCAN_Settings::get( 'group_ids', array() );
		if ( empty( $recipients ) ) {
			return;
		}

		$message  = self::build_new_order_flex( $order );
		$failures = array();
		foreach ( $recipients as $line_user_id ) {
			$result = self::push_message( $line_user_id, $message );
			if ( true !== $result ) {
				$failures[] = $result;
			}
		}

		$order->update_meta_data( '_wcan_notified', 1 );
		$order->save();

		if ( empty( $failures ) ) {
			$order->add_order_note( sprintf( '【新訂單 LINE 通知】已通知 %d 個群組/聊天室。', count( $recipients ) ) );
		} else {
			$order->add_order_note( sprintf( '【新訂單 LINE 通知】%d/%d 發送失敗：%s', count( $failures ), count( $recipients ), implode( '; ', $failures ) ) );
		}
	}

	/**
	 * 測試推播（從設定頁觸發）
	 *
	 * @return true|string
	 */
	public static function test_push( $line_user_id ) {
		$site_name = get_bloginfo( 'name' );
		$rows      = array(
			self::flex_row( '訂單編號', '#TEST-001' ),
			self::flex_row( '訂購人', '測試顧客' ),
			self::flex_row( '訂單狀態', '處理中' ),
			self::flex_row( '訂單金額', 'NT$1,200' ),
			self::flex_row( '付款方式', '信用卡' ),
			self::flex_row( '・範例商品 A', '×2', '#555555', '#111111' ),
		);

		$message = self::make_flex(
			"【{$site_name}】這是一則測試推播通知",
			$site_name,
			$rows,
			home_url()
		);

		return self::push_message( $line_user_id, $message );
	}

	/**
	 * 組出「新訂單」管理員通知 Flex Message bubble
	 */
	private static function build_new_order_flex( $order ) {
		$customer_name = trim( $order->get_billing_last_name() . $order->get_billing_first_name() ) ?: '訪客';
		$order_number  = $order->get_order_number();
		$total         = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ) );
		$payment       = $order->get_payment_method_title() ?: '—';
		$status_name   = wc_get_order_status_name( $order->get_status() );
		$order_url     = $order->get_edit_order_url();
		$site_name     = get_bloginfo( 'name' );

		$rows = array(
			self::flex_row( '訂單編號', '#' . $order_number ),
			self::flex_row( '訂購人', $customer_name ),
			self::flex_row( '訂單狀態', $status_name ),
			self::flex_row( '訂單金額', $total ),
			self::flex_row( '付款方式', $payment ),
		);

		foreach ( $order->get_items() as $item ) {
			$rows[] = self::flex_row(
				'・' . mb_substr( $item->get_name(), 0, 30 ),
				'×' . $item->get_quantity(),
				'#555555',
				'#111111'
			);
		}

		return self::make_flex(
			mb_substr( "【{$site_name}】新訂單 #{$order_number}", 0, 400 ),
			$site_name,
			$rows,
			$order_url
		);
	}

	/**
	 * 組出完整 Flex Message 陣列
	 */
	private static function make_flex( $alt_text, $site_name, array $rows, $btn_url ) {
		$title       = WCAN_Settings::get( 'title', '🔔 新訂單通知' ) ?: '🔔 新訂單通知';
		$button_text = WCAN_Settings::get( 'button_text', '前往後台查看' ) ?: '前往後台查看';

		return array(
			'type'     => 'flex',
			'altText'  => $alt_text,
			'contents' => array(
				'type'   => 'bubble',
				'header' => array(
					'type'            => 'box',
					'layout'          => 'vertical',
					'backgroundColor' => self::$header_color,
					'paddingAll'      => '16px',
					'contents'        => array(
						array(
							'type'   => 'text',
							'text'   => '🛍️ ' . $site_name,
							'color'  => '#ffffff',
							'size'   => 'sm',
							'weight' => 'bold',
						),
						array(
							'type'   => 'text',
							'text'   => $title,
							'color'  => '#ffffff',
							'size'   => 'xxl',
							'weight' => 'bold',
							'margin' => 'sm',
						),
					),
				),
				'body'   => array(
					'type'     => 'box',
					'layout'   => 'vertical',
					'spacing'  => 'sm',
					'contents' => $rows,
				),
				'footer' => array(
					'type'     => 'box',
					'layout'   => 'vertical',
					'contents' => array(
						array(
							'type'   => 'button',
							'action' => array(
								'type'  => 'uri',
								'label' => $button_text,
								'uri'   => $btn_url,
							),
							'style'  => 'primary',
							'color'  => self::$header_color,
							'height' => 'sm',
						),
					),
				),
			),
		);
	}

	/**
	 * 組出一行 label + value 的 baseline box
	 */
	private static function flex_row( $label, $value, $label_color = '#aaaaaa', $value_color = '#111111' ) {
		return array(
			'type'     => 'box',
			'layout'   => 'baseline',
			'spacing'  => 'sm',
			'contents' => array(
				array(
					'type'  => 'text',
					'text'  => $label,
					'color' => $label_color,
					'size'  => 'sm',
					'flex'  => 4,
					'wrap'  => true,
				),
				array(
					'type'   => 'text',
					'text'   => $value,
					'color'  => $value_color,
					'size'   => 'sm',
					'flex'   => 2,
					'weight' => 'bold',
					'align'  => 'end',
					'wrap'   => true,
				),
			),
		);
	}

	/**
	 * 呼叫 LINE Messaging API push endpoint
	 *
	 * @param string $line_user_id
	 * @param array  $message LINE message 物件
	 * @return true|string true 表示成功，字串為錯誤訊息
	 */
	private static function push_message( $line_user_id, array $message ) {
		// Channel Access Token 為顧客／管理員通知共用欄位，v1.8.2 起集中存在 wclon_settings
		$token = WCLON_Settings::get( 'channel_access_token' );
		if ( ! $token ) {
			return '尚未設定 Channel Access Token。';
		}

		$response = wp_remote_post( 'https://api.line.me/v2/bot/message/push', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
			'body'    => wp_json_encode( array(
				'to'       => $line_user_id,
				'messages' => array( $message ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = $body['message'] ?? '未知錯誤';

		if ( 403 === $code ) {
			$msg .= '（對方可能尚未加官方帳號好友，或已封鎖）';
		} elseif ( 429 === $code ) {
			$msg .= '（已達本月訊息額度上限）';
		} elseif ( 401 === $code ) {
			$msg .= '（Access Token 無效或過期）';
		}

		return sprintf( 'HTTP %d - %s', $code, $msg );
	}
}
