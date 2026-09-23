<?php
/**
 * 訂單狀態變更時，透過 LINE Messaging API 推播 Flex Message 給顧客
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Notifier {

	const EMAIL_NOTIFY_META_KEY = '_wclon_email_notify_enabled';

	private static $status_colors = array(
		'pending'    => '#FF9800',
		'processing' => '#00C300',
		'on-hold'    => '#FF9800',
		'completed'  => '#2196F3',
		'cancelled'  => '#9E9E9E',
		'refunded'   => '#9E9E9E',
		'failed'     => '#F44336',
	);

	// WC 顧客 email ID → 對應的訂單狀態（含 wc- 前綴）
	private static $email_status_map = array(
		'customer_processing_order'         => 'wc-processing',
		'customer_completed_order'          => 'wc-completed',
		'customer_on_hold_order'            => 'wc-on-hold',
		'customer_invoice'                  => 'wc-pending',
		'customer_refunded_order'           => 'wc-refunded',
		'customer_partially_refunded_order' => 'wc-refunded',
	);

	public static function init() {
		// 顧客 LINE 推播的前提是顧客綁定過 LINE，社交登入模組關閉時綁定入口全部消失，
		// 推播也一併停用（v1.40.0）；Email 通知開關與壓制照常運作。
		if ( WCLON_Settings::module_enabled( 'social_login' ) ) {
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_notify' ), 10, 4 );
			// 補捉訂單建立時就已確定狀態的情形（如銀行轉帳），此時不會有狀態轉換事件
			add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'maybe_notify_on_create' ) );
			add_action( 'woocommerce_new_customer_note', array( __CLASS__, 'notify_customer_note' ) );
			// 物流狀態通知（v1.17.0 新增）：只讀 ecpay-ecommerce-for-woocommerce 外掛寫入的訂單備注，
			// 不修改該外掛、也不重新實作物流 API，見 maybe_notify_logistics_note()
			add_action( 'woocommerce_order_note_added', array( __CLASS__, 'maybe_notify_logistics_note' ), 10, 2 );
		}

		foreach ( array_keys( self::$email_status_map ) as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( __CLASS__, 'maybe_suppress_wc_email' ), 10, 2 );
		}
		add_filter( 'woocommerce_email_enabled_customer_note', array( __CLASS__, 'maybe_suppress_note_email' ), 10, 2 );

		add_action( 'init', array( __CLASS__, 'handle_toggle_email_notify' ) );
		add_action( 'woocommerce_account_' . WCLON_ACCOUNT_ENDPOINT . '_endpoint', array( __CLASS__, 'echo_email_notify_row' ), 42 );
	}

	/**
	 * 顧客自行在帳戶詳細資料頁關閉 Email 訂單通知時，壓制對應的 WC 顧客信件
	 */
	public static function maybe_suppress_wc_email( $enabled, $order ) {
		if ( ! $enabled || ! $order instanceof WC_Order ) {
			return $enabled;
		}
		$customer_id = $order->get_customer_id();
		if ( $customer_id && ! self::get_email_notify_enabled( $customer_id ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * 備注通知信件壓制：顧客自行關閉 Email 訂單通知時壓制
	 */
	public static function maybe_suppress_note_email( $enabled, $order ) {
		if ( ! $enabled || ! $order instanceof WC_Order ) {
			return $enabled;
		}
		$customer_id = $order->get_customer_id();
		if ( $customer_id && ! self::get_email_notify_enabled( $customer_id ) ) {
			return false;
		}
		return $enabled;
	}

	/* ---------- Email 訂單通知開關（帳戶詳細資料頁「通知設定」區塊） ---------- */

	/**
	 * 取得使用者的 Email 訂單通知開關（預設 true）
	 */
	public static function get_email_notify_enabled( $user_id ) {
		$val = get_user_meta( $user_id, self::EMAIL_NOTIFY_META_KEY, true );
		return $val === '' ? true : (bool) $val;
	}

	public static function handle_toggle_email_notify() {
		if ( empty( $_GET['wclon_action'] ) || 'toggle_email_notify' !== $_GET['wclon_action'] ) {
			return;
		}
		if ( ! is_user_logged_in() || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wclon_toggle_email_notify' ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}
		$current = self::get_email_notify_enabled( get_current_user_id() );
		update_user_meta( get_current_user_id(), self::EMAIL_NOTIFY_META_KEY, $current ? 0 : 1 );
		wp_safe_redirect( wp_get_referer() ?: wc_get_account_endpoint_url( WCLON_ACCOUNT_ENDPOINT ) );
		exit;
	}

	public static function echo_email_notify_row() {
		echo self::render_email_notify_row(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render_email_notify_row() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$notify_on = self::get_email_notify_enabled( get_current_user_id() );
		ob_start();
		?>
		<div class="wclon-account-social__row">
			<span class="wclon-account-social__row-label">Email 訂單通知</span>
			<a class="wclon-notify-toggle<?php echo $notify_on ? ' wclon-notify-toggle--on' : ''; ?>"
			   href="<?php echo esc_url( wp_nonce_url( home_url( '/?wclon_action=toggle_email_notify' ), 'wclon_toggle_email_notify' ) ); ?>"
			   title="<?php echo $notify_on ? '點擊關閉 Email 訂單通知' : '點擊開啟 Email 訂單通知'; ?>">
				<span class="wclon-notify-toggle__track"><span class="wclon-notify-toggle__thumb"></span></span>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function maybe_notify( $order_id, $old_status, $new_status, $order ) {
		if ( ! WCLON_Settings::get( 'notify_customer_enabled', 1 ) ) {
			return;
		}
		$statuses = (array) WCLON_Settings::get( 'statuses', array() );
		if ( ! in_array( 'wc-' . $new_status, $statuses, true ) ) {
			return;
		}

		// 同一狀態只推播一次，避免狀態來回切換重複發送
		$sent_log = (array) $order->get_meta( '_wclon_sent_statuses' );
		if ( in_array( $new_status, $sent_log, true ) ) {
			return;
		}

		$line_user_id = self::resolve_line_user_id( $order );
		if ( ! $line_user_id ) {
			$order->add_order_note( '【LINE 通知】未發送：顧客尚未綁定 LINE。' );
			return;
		}

		$customer_id = $order->get_customer_id();
		if ( $customer_id && ! WCLON_Line_Login::get_notify_enabled( $customer_id ) ) {
			$order->add_order_note( '【LINE 通知】未發送：顧客已關閉 LINE 訂單通知。' );
			return;
		}

		$message = self::build_flex_message( $order, $new_status );
		$result  = self::push_message( $line_user_id, $message );

		if ( true === $result ) {
			$sent_log[] = $new_status;
			$order->update_meta_data( '_wclon_sent_statuses', $sent_log );
			$order->save();
			$order->add_order_note( sprintf( '【LINE 通知】已發送「%s」狀態通知。', wc_get_order_status_name( $new_status ) ) );
		} else {
			$order->add_order_note( '【LINE 通知】發送失敗：' . $result );
		}
	}

	/**
	 * 訂單剛建立時（checkout_order_created）補發通知
	 * 適用訂單建立即已確定狀態、不會觸發 status_changed 的情形（如銀行轉帳 pending）
	 */
	public static function maybe_notify_on_create( $order ) {
		$status = $order->get_status(); // e.g. 'pending'
		self::maybe_notify( $order->get_id(), '', $status, $order );
	}

	/**
	 * 訂單備注（顧客可見）新增時推播 LINE
	 *
	 * @param array $args { order_id, customer_note }
	 */
	public static function notify_customer_note( $args ) {
		if ( ! WCLON_Settings::get( 'notify_customer_enabled', 1 ) || ! WCLON_Settings::get( 'note_notify', 1 ) ) {
			return;
		}

		$order_id = absint( $args['order_id'] ?? 0 );
		$note     = sanitize_text_field( wp_strip_all_tags( $args['customer_note'] ?? '' ) );
		if ( ! $order_id || ! $note ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$line_user_id = self::resolve_line_user_id( $order );
		if ( ! $line_user_id ) {
			return;
		}

		$customer_id = $order->get_customer_id();
		if ( $customer_id && ! WCLON_Line_Login::get_notify_enabled( $customer_id ) ) {
			return;
		}

		$message = self::build_note_flex_message( $order, $note );
		$result  = self::push_message( $line_user_id, $message );

		if ( true !== $result ) {
			$order->add_order_note( '【LINE 備注通知】發送失敗：' . $result );
		}
	}

	/**
	 * 物流狀態變化通知（v1.17.0 新增）：ecpay-ecommerce-for-woocommerce 外掛收到綠界物流貨態
	 * 回傳時，只會把狀態寫成一則系統備注（`$order->add_order_note('物流貨態回傳:' . $RtnMsg . ' (' . $RtnCode . ')')`），
	 * 沒有專屬 hook 可掛，因此改成監聽 WooCommerce 核心的 `woocommerce_order_note_added`（任何一則
	 * 訂單備注新增時都會觸發），用固定前綴字串判斷是不是這則物流備注。只讀取，不修改 ecpay 外掛、
	 * 也不重新實作它的物流 API 或 webhook 驗證邏輯，避免跟第三方外掛耦合。
	 *
	 * 這個 handler 失敗時也會呼叫 add_order_note()，會再次觸發同一個 hook，但因為我們自己寫的備注
	 * 內容不是「物流貨態回傳:」開頭，下面的前綴判斷會直接 return，不會形成無限迴圈。
	 *
	 * @param int      $comment_id add_order_note() 寫入後的留言 ID
	 * @param WC_Order $order
	 */
	public static function maybe_notify_logistics_note( $comment_id, $order ) {
		if ( ! WCLON_Settings::get( 'notify_customer_enabled', 1 ) || ! WCLON_Settings::get( 'logistics_notify_enabled' ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		$prefix  = '物流貨態回傳:';
		$content = wp_strip_all_tags( $comment->comment_content );
		if ( 0 !== strpos( $content, $prefix ) ) {
			return;
		}
		$message_text = trim( mb_substr( $content, mb_strlen( $prefix ) ) );
		if ( ! $message_text ) {
			return;
		}

		$line_user_id = self::resolve_line_user_id( $order );
		if ( ! $line_user_id ) {
			return;
		}

		$customer_id = $order->get_customer_id();
		if ( $customer_id && ! WCLON_Line_Login::get_notify_enabled( $customer_id ) ) {
			return;
		}

		$message = self::build_logistics_flex_message( $order, $message_text );
		$result  = self::push_message( $line_user_id, $message );

		if ( true !== $result ) {
			$order->add_order_note( '【LINE 物流通知】發送失敗：' . $result );
		}
	}

	/**
	 * 組出物流狀態通知 Flex Message bubble
	 */
	private static function build_logistics_flex_message( $order, $message_text ) {
		$site_name    = get_bloginfo( 'name' );
		$order_number = $order->get_order_number();
		$order_url    = $order->get_view_order_url();
		$btn_text     = WCLON_Settings::get( 'button_text', '查看訂單詳情' ) ?: '查看訂單詳情';
		$color        = WCLON_Settings::get( 'header_color', '#00C300' );
		$title        = WCLON_Settings::get( 'logistics_title', '物流狀態更新' ) ?: '物流狀態更新';

		$customer_name = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		if ( ! $customer_name ) {
			$customer_name = '顧客';
		}
		$greeting = WCLON_Settings::render_template(
			WCLON_Settings::get( 'greeting_template', '您好，{customer_name}！' ),
			array( 'customer_name' => $customer_name, 'site_name' => $site_name )
		);

		$body_contents = array(
			array( 'type' => 'text', 'text' => $greeting, 'weight' => 'bold', 'size' => 'md' ),
			array( 'type' => 'separator', 'margin' => 'md' ),
			self::flex_row( '訂單編號', '#' . $order_number, '#aaaaaa', '#111111', 'md' ),
			array( 'type' => 'separator', 'margin' => 'md' ),
			array(
				'type'   => 'text',
				'text'   => $title,
				'weight' => 'bold',
				'size'   => 'sm',
				'color'  => '#555555',
				'margin' => 'md',
			),
			array(
				'type'   => 'text',
				'text'   => $message_text,
				'size'   => 'sm',
				'color'  => '#111111',
				'wrap'   => true,
				'margin' => 'sm',
			),
		);

		return self::make_flex(
			mb_substr( "【{$site_name}】訂單 #{$order_number} {$title}", 0, 400 ),
			$site_name,
			$title,
			$color,
			$body_contents,
			$btn_text,
			$order_url
		);
	}

	/**
	 * 組出備注 Flex Message bubble
	 */
	private static function build_note_flex_message( $order, $note ) {
		$site_name    = get_bloginfo( 'name' );
		$order_number = $order->get_order_number();
		$order_url    = $order->get_view_order_url();
		$btn_text     = WCLON_Settings::get( 'button_text', '查看訂單詳情' ) ?: '查看訂單詳情';
		$color        = WCLON_Settings::get( 'note_color', '#FF9800' );

		$customer_name = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		if ( ! $customer_name ) {
			$customer_name = '顧客';
		}

		$note_title = WCLON_Settings::get( 'note_title', '店家留言' ) ?: '店家留言';
		$greeting   = WCLON_Settings::render_template(
			WCLON_Settings::get( 'greeting_template', '您好，{customer_name}！' ),
			array( 'customer_name' => $customer_name, 'site_name' => $site_name )
		);

		$body_contents = array(
			array(
				'type'   => 'text',
				'text'   => $greeting,
				'weight' => 'bold',
				'size'   => 'md',
			),
			array( 'type' => 'separator', 'margin' => 'md' ),
			self::flex_row( '訂單編號', '#' . $order_number, '#aaaaaa', '#111111', 'md' ),
			array( 'type' => 'separator', 'margin' => 'md' ),
			array(
				'type'       => 'text',
				'text'       => $note_title,
				'weight'     => 'bold',
				'size'       => 'sm',
				'color'      => '#555555',
				'margin'     => 'md',
			),
			array(
				'type'   => 'text',
				'text'   => $note,
				'size'   => 'sm',
				'color'  => '#111111',
				'wrap'   => true,
				'margin' => 'sm',
			),
		);

		return self::make_flex(
			mb_substr( "【{$site_name}】訂單 #{$order_number} {$note_title}", 0, 400 ),
			$site_name,
			$note_title,
			$color,
			$body_contents,
			$btn_text,
			$order_url
		);
	}

	/**
	 * 從訂單 meta（涵蓋訪客結帳）或訂單客戶帳號取得 LINE User ID。
	 * public：v1.23.0 起棄單/未結帳提醒已搬到 wc-marketing-automation 外掛（該外掛用自己的
	 * WCMA_Notifier::resolve_order_line_id() 軟依賴讀取，不呼叫這裡）；這裡維持 public 純粹因為
	 * 訂單狀態通知本身（build_flex_message() 等）在本檔案內就有三處呼叫，公開性不需要收回。
	 */
	public static function resolve_line_user_id( $order ) {
		$id = $order->get_meta( '_wclon_line_user_id' );
		if ( $id ) {
			return $id;
		}
		$user_id = $order->get_customer_id();
		if ( $user_id ) {
			return get_user_meta( $user_id, WCLON_Line_Login::USER_META_KEY, true );
		}
		return '';
	}

	// ─── Flex Message 組裝 ──────────────────────────────────────────────────

	private static function status_color( $status ) {
		return self::$status_colors[ $status ] ?? '#00C300';
	}

	/**
	 * 組出訂單 Flex Message bubble
	 */
	private static function build_flex_message( $order, $new_status ) {
		$customer_name = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		if ( ! $customer_name ) {
			$customer_name = '顧客';
		}

		$status_name  = wc_get_order_status_name( $new_status );
		$order_number = $order->get_order_number();
		$total        = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ) );
		$payment      = $order->get_payment_method_title() ?: '—';
		$order_url    = $order->get_view_order_url();
		$color        = WCLON_Settings::get( 'header_color', self::status_color( $new_status ) );
		$btn_text     = WCLON_Settings::get( 'button_text', '查看訂單詳情' ) ?: '查看訂單詳情';
		$site_name    = get_bloginfo( 'name' );
		$greeting     = WCLON_Settings::render_template(
			WCLON_Settings::get( 'greeting_template', '您好，{customer_name}！' ),
			array( 'customer_name' => $customer_name, 'site_name' => $site_name )
		);

		// 商品列表 rows
		$item_rows = array();
		foreach ( $order->get_items() as $item ) {
			$item_rows[] = self::flex_row(
				'・' . mb_substr( $item->get_name(), 0, 30 ),
				'×' . $item->get_quantity(),
				'#555555',
				'#111111'
			);
		}

		$body_contents = array_merge(
			array(
				array(
					'type'   => 'text',
					'text'   => $greeting,
					'weight' => 'bold',
					'size'   => 'md',
				),
				array( 'type' => 'separator', 'margin' => 'md' ),
				array(
					'type'     => 'box',
					'layout'   => 'vertical',
					'margin'   => 'md',
					'spacing'  => 'sm',
					'contents' => array(
						self::flex_row( '訂單編號', '#' . $order_number ),
						self::flex_row( '訂單狀態', $status_name, '#aaaaaa', $color ),
						self::flex_row( '訂單金額', $total ),
						self::flex_row( '付款方式', $payment ),
					),
				),
				array( 'type' => 'separator', 'margin' => 'md' ),
				array(
					'type'   => 'text',
					'text'   => '購買商品',
					'weight' => 'bold',
					'size'   => 'sm',
					'margin' => 'md',
					'color'  => '#555555',
				),
			),
			$item_rows
		);

		return self::make_flex(
			mb_substr( "【{$site_name}】訂單 #{$order_number} 狀態：{$status_name}", 0, 400 ),
			$site_name,
			$status_name,
			$color,
			$body_contents,
			$btn_text,
			$order_url
		);
	}

	/**
	 * 顧客帳號第一次綁定 LINE 時，推播一則「綁定歡迎優惠券」Flex Message
	 * （由 WCLON_Line_Login::maybe_issue_bind_coupon() 呼叫）
	 *
	 * @param string    $line_user_id
	 * @param WC_Coupon $coupon
	 * @return true|string true 表示成功，字串為錯誤訊息
	 */
	public static function send_bind_coupon_notice( $line_user_id, $coupon ) {
		$site_name = get_bloginfo( 'name' );
		$code      = $coupon->get_code();
		$type      = $coupon->get_discount_type();
		$amount    = (float) $coupon->get_amount();
		$expires   = $coupon->get_date_expires();
		$color     = WCLON_Settings::get( 'header_color', '#00C300' );

		$amount_text = WCLON_Settings::format_coupon_amount( $type, $amount );

		$expiry_text = $expires ? ( $expires->date_i18n( 'Y-m-d' ) . ' 前有效' ) : '無使用期限';

		$title       = WCLON_Settings::get( 'bind_coupon_title', '專屬優惠券' ) ?: '專屬優惠券';
		$btn_text    = WCLON_Settings::get( 'bind_coupon_button_text', '前往購物' ) ?: '前往購物';
		$vars        = array( 'site_name' => $site_name, 'coupon_code' => $code, 'coupon_amount' => $amount_text );
		$greeting    = WCLON_Settings::render_template( WCLON_Settings::get( 'bind_coupon_greeting', '感謝您綁定 LINE 帳號！' ), $vars );
		$description = WCLON_Settings::render_template( WCLON_Settings::get( 'bind_coupon_desc', '結帳時輸入上方代碼即可折抵，僅限本人帳號使用一次。' ), $vars );

		$body_contents = array(
			array( 'type' => 'text', 'text' => $greeting, 'weight' => 'bold', 'size' => 'md', 'wrap' => true ),
			array( 'type' => 'separator', 'margin' => 'md' ),
			array(
				'type'     => 'box',
				'layout'   => 'vertical',
				'margin'   => 'md',
				'spacing'  => 'sm',
				'contents' => array(
					self::flex_row( '優惠券代碼', $code ),
					self::flex_row( '折扣內容', $amount_text ),
					self::flex_row( '使用效期', $expiry_text ),
				),
			),
			array(
				'type'   => 'text',
				'text'   => $description,
				'size'   => 'xs',
				'color'  => '#888888',
				'wrap'   => true,
				'margin' => 'md',
			),
		);

		$message = self::make_flex(
			"【{$site_name}】LINE 綁定成功！專屬優惠券 {$code}",
			$site_name,
			$title,
			$color,
			$body_contents,
			$btn_text,
			wc_get_page_permalink( 'shop' ) ?: home_url()
		);

		return self::push_message( $line_user_id, $message );
	}

	/**
	 * 測試推播（從後台觸發）
	 *
	 * @return true|string
	 */
	public static function test_push( $line_user_id ) {
		$site_name = get_bloginfo( 'name' );
		$color     = WCLON_Settings::get( 'header_color', '#00C300' );
		$btn_text  = WCLON_Settings::get( 'button_text', '查看訂單詳情' ) ?: '查看訂單詳情';
		$greeting  = WCLON_Settings::render_template(
			WCLON_Settings::get( 'greeting_template', '您好，{customer_name}！' ),
			array( 'customer_name' => '顧客', 'site_name' => $site_name )
		);

		$body_contents = array(
			array( 'type' => 'text', 'text' => $greeting, 'weight' => 'bold', 'size' => 'md' ),
			array( 'type' => 'separator', 'margin' => 'md' ),
			array(
				'type'     => 'box',
				'layout'   => 'vertical',
				'margin'   => 'md',
				'spacing'  => 'sm',
				'contents' => array(
					self::flex_row( '訂單編號', '#TEST-001' ),
					self::flex_row( '訂單狀態', '處理中', '#aaaaaa', $color ),
					self::flex_row( '訂單金額', 'NT$1,200' ),
					self::flex_row( '付款方式', '信用卡' ),
				),
			),
			array( 'type' => 'separator', 'margin' => 'md' ),
			array( 'type' => 'text', 'text' => '購買商品', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'md', 'color' => '#555555' ),
			self::flex_row( '・範例商品 A', '×2', '#555555', '#111111' ),
			self::flex_row( '・範例商品 B', '×1', '#555555', '#111111' ),
		);

		$message = self::make_flex(
			"【{$site_name}】這是一則測試推播通知",
			$site_name,
			'測試通知',
			$color,
			$body_contents,
			$btn_text,
			home_url()
		);

		return self::push_message( $line_user_id, $message );
	}

	/**
	 * 組出完整 Flex Message 陣列
	 */
	private static function make_flex( $alt_text, $site_name, $status_text, $color, $body_contents, $btn_text, $btn_url ) {
		return array(
			'type'     => 'flex',
			'altText'  => $alt_text,
			'contents' => array(
				'type'   => 'bubble',
				'header' => array(
					'type'            => 'box',
					'layout'          => 'vertical',
					'backgroundColor' => $color,
					'paddingAll'      => '16px',
					'contents'        => array(
						array(
							'type'   => 'text',
							'text'   => $site_name,
							'color'  => '#ffffff',
							'size'   => 'sm',
							'weight' => 'bold',
						),
						array(
							'type'   => 'text',
							'text'   => $status_text,
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
					'contents' => $body_contents,
				),
				'footer' => array(
					'type'     => 'box',
					'layout'   => 'vertical',
					'contents' => array(
						array(
							'type'   => 'button',
							'action' => array(
								'type'  => 'uri',
								'label' => $btn_text,
								'uri'   => $btn_url,
							),
							'style'  => 'primary',
							'color'  => $color,
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
	private static function flex_row( $label, $value, $label_color = '#aaaaaa', $value_color = '#111111', $margin = null ) {
		$row = array(
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
		if ( $margin ) {
			$row['margin'] = $margin;
		}
		return $row;
	}

	/**
	 * 呼叫 LINE Messaging API push endpoint
	 *
	 * @param string $line_user_id
	 * @param array  $message LINE message 物件
	 * @return true|string true 表示成功，字串為錯誤訊息
	 */
	private static function push_message( $line_user_id, array $message ) {
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
			$msg .= '（顧客可能尚未加官方帳號好友，或已封鎖）';
		} elseif ( 429 === $code ) {
			$msg .= '（已達本月訊息額度上限）';
		} elseif ( 401 === $code ) {
			$msg .= '（Access Token 無效或過期）';
		}

		return sprintf( 'HTTP %d - %s', $code, $msg );
	}
}
