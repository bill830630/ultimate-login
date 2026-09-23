<?php
/**
 * 透過 LINE Messaging API 推播通知給管理員群組：新訂單，以及網站表單送出（v1.41.0 起，
 * Elementor Pro／Contact Form 7／Fluent Forms）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAN_Notifier {

	private static $header_color = '#4A90D9';

	/** 表單欄位推播上限（Flex bubble 有大小限制，欄位太多或太長的表單截斷） */
	const FORM_MAX_FIELDS = 25;
	const FORM_MAX_VALUE  = 300;

	/** 表單送出內容中不推播的系統欄位（驗證 token、隱藏欄位） */
	private static $form_skip_keys = array( 'cf-turnstile-response', 'g-recaptcha-response', 'h-captcha-response', '_wp_http_referer', '__fluent_form_embded_post_id' );

	public static function init() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'maybe_notify_admins' ) );

		// 網站表單：只在表單外掛自己確認送出成功之後才觸發（驗證失敗、判定垃圾訊息的都不會到這裡）
		if ( WCAN_Settings::get( 'form_notify_enabled', 0 ) ) {
			add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'notify_elementor_form' ), 10, 2 );
			add_action( 'wpcf7_submit', array( __CLASS__, 'notify_cf7_form' ), 10, 2 );
			add_action( 'fluentform/submission_inserted', array( __CLASS__, 'notify_fluent_form' ), 20, 3 );
		}
	}

	// ─── 網站表單 ─────────────────────────────────────────────────────────────

	/** Elementor Pro：$record 為 Form_Record，fields 為 id => [title, value, type…] */
	public static function notify_elementor_form( $record, $handler = null ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}
		$form_name = method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'form_name' ) : '';
		$fields    = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			if ( in_array( $field['type'] ?? '', array( 'honeypot', 'recaptcha', 'recaptcha_v3', 'step', 'html', 'wclon_turnstile' ), true ) ) {
				continue;
			}
			$label = trim( (string) ( $field['title'] ?? '' ) ) ?: (string) $id;
			$fields[ $label ] = $field['value'] ?? '';
		}
		self::send_form_notice( 'Elementor', $form_name, $fields, admin_url( 'admin.php?page=e-form-submissions' ) );
	}

	/** Contact Form 7：只推播真的送出的（mail_sent／mail_failed），欄位名稱就是表單標籤的 name */
	public static function notify_cf7_form( $contact_form, $result = array() ) {
		$status = is_array( $result ) ? ( $result['status'] ?? '' ) : '';
		if ( ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) || ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$title = is_object( $contact_form ) && method_exists( $contact_form, 'title' ) ? $contact_form->title() : '';
		self::send_form_notice( 'Contact Form 7', $title, (array) $submission->get_posted_data(), admin_url( 'admin.php?page=wpcf7' ) );
	}

	/** Fluent Forms：送出內容存進資料庫之後，連結直接開該筆記錄 */
	public static function notify_fluent_form( $entry_id, $form_data = array(), $form = null ) {
		$form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;
		$title   = is_object( $form ) && isset( $form->title ) ? (string) $form->title : '';
		$labels  = self::fluent_field_labels( $form );
		$fields  = array();
		foreach ( (array) $form_data as $key => $value ) {
			if ( '_' === substr( (string) $key, 0, 1 ) ) {
				continue;
			}
			$fields[ $labels[ $key ] ?? $key ] = $value;
		}
		$url = $form_id
			? admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . $form_id . '#/entries/' . (int) $entry_id )
			: admin_url( 'admin.php?page=fluent_forms' );
		self::send_form_notice( 'Fluent Forms', $title, $fields, $url );
	}

	/** 從 Fluent Forms 的表單結構取欄位標籤（name => label），取不到就用欄位名稱 */
	private static function fluent_field_labels( $form ) {
		$labels = array();
		if ( ! is_object( $form ) || empty( $form->form_fields ) ) {
			return $labels;
		}
		$structure = json_decode( is_string( $form->form_fields ) ? $form->form_fields : wp_json_encode( $form->form_fields ), true );
		$walk      = function ( $fields ) use ( &$walk, &$labels ) {
			foreach ( (array) $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name  = $field['attributes']['name'] ?? '';
				$label = $field['settings']['label'] ?? ( $field['settings']['admin_field_label'] ?? '' );
				if ( $name && $label ) {
					$labels[ $name ] = wp_strip_all_tags( $label );
				}
				// 多欄版面（container）與姓名等複合欄位的子欄位
				foreach ( array( 'columns', 'fields' ) as $child ) {
					if ( ! empty( $field[ $child ] ) ) {
						foreach ( (array) $field[ $child ] as $sub ) {
							$walk( isset( $sub['fields'] ) ? $sub['fields'] : array( $sub ) );
						}
					}
				}
			}
		};
		$walk( $structure['fields'] ?? array() );
		return $labels;
	}

	/**
	 * 推播一則表單送出通知到所有已記錄的群組/聊天室。
	 *
	 * @param array $fields 顯示名稱 => 值（值可為陣列，例如核取方塊、姓名複合欄位）
	 */
	private static function send_form_notice( $source, $form_name, array $fields, $url ) {
		$recipients = (array) WCAN_Settings::get( 'group_ids', array() );
		if ( empty( $recipients ) ) {
			return;
		}
		$site_name = get_bloginfo( 'name' );
		$form_name = trim( wp_strip_all_tags( (string) $form_name ) ) ?: '未命名表單';

		$rows = array(
			self::flex_row( '表單', mb_substr( $form_name, 0, 60 ) ),
			self::flex_row( '來源', $source ),
			self::flex_row( '送出時間', wp_date( 'Y-m-d H:i' ) ),
			array( 'type' => 'separator', 'margin' => 'md' ),
		);
		$count = 0;
		foreach ( $fields as $label => $value ) {
			if ( in_array( (string) $label, self::$form_skip_keys, true ) ) {
				continue;
			}
			$text = self::stringify_form_value( $value );
			if ( '' === $text ) {
				continue;
			}
			if ( ++$count > self::FORM_MAX_FIELDS ) {
				$rows[] = self::form_row( '…', '其餘欄位請至後台查看' );
				break;
			}
			$rows[] = self::form_row( mb_substr( (string) $label, 0, 60 ), mb_substr( $text, 0, self::FORM_MAX_VALUE ) );
		}

		$message = self::make_flex(
			mb_substr( "【{$site_name}】新表單：{$form_name}", 0, 400 ),
			$site_name,
			$rows,
			$url,
			'📝 新表單送出'
		);
		foreach ( $recipients as $group_id ) {
			$result = self::push_message( $group_id, $message );
			if ( true !== $result ) {
				error_log( 'WCAN 表單通知推播失敗（' . $group_id . '）：' . $result ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	private static function stringify_form_value( $value ) {
		if ( is_array( $value ) ) {
			$parts = array();
			array_walk_recursive( $value, function ( $v ) use ( &$parts ) {
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
					$parts[] = trim( (string) $v );
				}
			} );
			return implode( '、', $parts );
		}
		return is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
	}

	/** 表單欄位一列：標籤在上、內容在下（內容可能很長，不適合訂單那種左右並排） */
	private static function form_row( $label, $value ) {
		return array(
			'type'     => 'box',
			'layout'   => 'vertical',
			'margin'   => 'md',
			'contents' => array(
				array( 'type' => 'text', 'text' => $label, 'color' => '#aaaaaa', 'size' => 'xs', 'wrap' => true ),
				array( 'type' => 'text', 'text' => $value, 'color' => '#111111', 'size' => 'sm', 'wrap' => true ),
			),
		);
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
	private static function make_flex( $alt_text, $site_name, array $rows, $btn_url, $title = '' ) {
		$title       = $title ?: ( WCAN_Settings::get( 'title', '🔔 新訂單通知' ) ?: '🔔 新訂單通知' );
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

		if ( 400 === $code && 'Failed to send messages' === $msg ) {
			// LINE 對「ID 格式正確但送不到」只回這句（已實測）：官方帳號不在該群組/聊天室
			// （被移出、群組已解散），或 ID 屬於另一個官方帳號的群組。
			$msg = '無法送達：官方帳號不在這個群組/聊天室裡（可能已被移出或群組已解散），或這個 ID 屬於另一個官方帳號';
		} elseif ( 400 === $code && str_contains( $msg, "'to'" ) ) {
			$msg = '群組/聊天室 ID 格式不正確';
		} elseif ( 403 === $code ) {
			$msg .= '（對方可能尚未加官方帳號好友，或已封鎖）';
		} elseif ( 429 === $code ) {
			$msg .= '（已達本月訊息額度上限）';
		} elseif ( 401 === $code ) {
			$msg .= '（Access Token 無效或過期）';
		}

		return sprintf( 'HTTP %d - %s', $code, $msg );
	}
}
