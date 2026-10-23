<?php
/**
 * 懸浮 LINE 聊天按鈕（v1.44.0 新增）
 *
 * 前台每個頁面左／右下角固定一顆 LINE 綠圓形按鈕，點擊：未登入且 LINE 登入已就緒→LINE 登入；否則開啟官方帳號聊天。
 * 跨平台的全域設定放在「一般設定」頁籤，支援尺寸、邊距、頁面與裝置條件。
 * 配色固定 LINE 官方綠；位置可選左／右下角（line_float_position）；不跟 btn_shape（那是登入按鈕的形狀設定）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCLON_Line_Float {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	/**
	 * 把設定值正規化成 LINE 聊天連結；不合法回空字串。
	 * 接受：@basicId／basicId（轉成 line.me/R/ti/p/@xxx），或 line.me／lin.ee 網域的 https 連結。
	 */
	public static function normalize_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^@?[A-Za-z0-9._-]{3,}$/', $raw ) ) {
			return 'https://line.me/R/ti/p/@' . ltrim( $raw, '@' );
		}
		$url  = esc_url_raw( $raw, array( 'https' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $url || ! preg_match( '/(^|\.)(line\.me|lin\.ee)$/', $host ) ) {
			return '';
		}
		return $url;
	}

	public static function url() {
		return self::normalize_url( WCLON_Settings::get( 'line_float_url', '' ) );
	}

	public static function position() {
		return 'left' === WCLON_Settings::get( 'line_float_position', 'right' ) ? 'left' : 'right';
	}

	/**
	 * 未登入且 LINE 登入已就緒：按鈕改成「用 LINE 登入」，登入後回到目前頁面；
	 * 其餘情況（已登入、或沒設定 LINE 登入）開啟官方帳號聊天。
	 */
	private static function target() {
		if ( ! is_user_logged_in() && WCLON_Verify::ready( 'line' ) ) {
			$current = set_url_scheme( home_url( add_query_arg( array() ) ) );
			return array(
				'url'   => home_url( '/?wclon_action=login&intent=login&redirect=' . rawurlencode( $current ) ),
				'label' => '使用 LINE 登入',
				'blank' => false,
			);
		}
		$chat = self::url();
		if ( '' === $chat ) {
			return null;
		}
		return array( 'url' => $chat, 'label' => '使用 LINE 聊天', 'blank' => true );
	}

	/** 共用於儲存與輸出，讓舊設定、空白與超出範圍的數值都有一致的回退。 */
	public static function dimension( $value, $default, $min = 0, $max = 500 ) {
		return is_scalar( $value ) && is_numeric( $value ) ? max( $min, min( $max, (int) $value ) ) : $default;
	}

	public static function page_ids( $value ) {
		$values = is_array( $value ) ? $value : preg_split( '/[\s,，]+/u', (string) $value );
		$ids    = array();
		foreach ( $values as $id ) {
			if ( is_scalar( $id ) && preg_match( '/^[0-9]+$/', (string) $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function devices() {
		$devices = WCLON_Settings::get( 'line_float_devices', array( 'desktop', 'tablet', 'mobile' ) );
		return is_array( $devices ) ? array_values( array_intersect( array( 'desktop', 'tablet', 'mobile' ), $devices ) ) : array();
	}

	/** 動態列出公開分類法，涵蓋文章／商品分類、標籤及自訂分類法。 */
	public static function condition_labels() {
		$labels = array(
			'front' => '首頁', 'blog' => '文章列表頁', 'pages' => '所有一般頁面',
			'posts' => '所有文章', 'products' => '所有商品', 'archives' => '所有彙整頁（含分類、標籤、作者、日期）',
			'search' => '搜尋結果頁', 'not_found' => '404 找不到頁面',
		);
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$labels[ 'taxonomy:' . $taxonomy->name ] = $taxonomy->labels->singular_name . '：所有彙整頁';
		}
		if ( function_exists( 'is_shop' ) ) {
			$labels += array( 'shop' => '商店頁', 'cart' => '購物車', 'checkout' => '結帳（含付款／訂單完成）', 'account' => '會員中心（含子頁）' );
		}
		return $labels;
	}

	public static function condition_matches( $condition ) {
		if ( 0 === strpos( $condition, 'taxonomy:' ) ) {
			$taxonomy = substr( $condition, 9 );
			return ( 'category' === $taxonomy && is_category() ) || ( 'post_tag' === $taxonomy && is_tag() ) || is_tax( $taxonomy );
		}
		switch ( $condition ) {
			case 'front': return is_front_page();
			case 'blog': return is_home();
			case 'pages': return is_page();
			case 'posts': return is_singular( 'post' );
			case 'products': return is_singular( 'product' );
			case 'archives': return is_archive();
			case 'search': return is_search();
			case 'not_found': return is_404();
			case 'shop': return function_exists( 'is_shop' ) && is_shop();
			case 'cart': return function_exists( 'is_cart' ) && is_cart();
			case 'checkout': return function_exists( 'is_checkout' ) && is_checkout();
			case 'account': return function_exists( 'is_account_page' ) && is_account_page();
		}
		return false;
	}

	public static function searchable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	/** 名稱查詢只提供已發佈內容與公開分類法，不把整站內容載入設定頁。 */
	public static function ajax_search_pages() {
		check_ajax_referer( 'wclon_admin_action', 'nonce' );
		if ( ! current_user_can( WCLON_WC::capability() ) ) {
			wp_send_json_error( array( 'message' => '權限不足。' ), 403 );
		}
		$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$filter = sanitize_text_field( wp_unslash( $_POST['filter'] ?? '' ) );
		$page   = max( 1, absint( $_POST['page'] ?? 1 ) );
		$types  = self::searchable_post_types();
		$taxes  = get_taxonomies( array( 'public' => true ), 'objects' );
		$items  = array();
		$more   = false;
		if ( $types && ( '' === $filter || ( 0 === strpos( $filter, 'post:' ) && isset( $types[ substr( $filter, 5 ) ] ) ) ) ) {
			$query = new WP_Query( array(
				'post_type' => '' === $filter ? array_keys( $types ) : substr( $filter, 5 ),
				'post_status' => 'publish', 's' => $search, 'search_columns' => array( 'post_title' ),
				'posts_per_page' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ),
				'no_found_rows' => true, 'ignore_sticky_posts' => true,
			) );
			$more = count( $query->posts ) > 20;
			foreach ( array_slice( $query->posts, 0, 20 ) as $post ) {
				$items[] = array( 'id' => $post->ID, 'kind' => 'post', 'label' => ( $post->post_title ?: '（無標題）' ) . ' — ' . $types[ $post->post_type ]->labels->singular_name );
			}
		}
		if ( $taxes && ( '' === $filter || ( 0 === strpos( $filter, 'tax:' ) && isset( $taxes[ substr( $filter, 4 ) ] ) ) ) ) {
			$terms = get_terms( array(
				'taxonomy' => '' === $filter ? array_keys( $taxes ) : substr( $filter, 4 ),
				'hide_empty' => false, 'search' => $search, 'number' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => 'term_id', 'order' => 'ASC',
			) );
			if ( is_wp_error( $terms ) ) {
				wp_send_json_error( array( 'message' => '分類查詢失敗，請重試。' ), 500 );
			}
			$more = $more || count( $terms ) > 20;
			foreach ( array_slice( $terms, 0, 20 ) as $term ) {
				$items[] = array( 'id' => $term->term_id, 'kind' => 'term', 'label' => $term->name . ' — ' . $taxes[ $term->taxonomy ]->labels->singular_name . '彙整頁' );
			}
		}
		wp_send_json_success( array( 'items' => $items, 'more' => $more ) );
	}

	public static function render_page_picker() {
		?>
		<div class="wclon-page-picker" data-field-prefix="<?php echo esc_attr( WCLON_Settings::OPTION_KEY ); ?>">
			<div class="wclon-page-picker__search">
				<label class="screen-reader-text" for="wclon-float-search">搜尋頁面或分類名稱</label>
				<input type="search" id="wclon-float-search" placeholder="輸入頁面、文章、商品或分類名稱">
				<label class="screen-reader-text" for="wclon-float-filter">內容類型</label>
				<select id="wclon-float-filter">
					<option value="">全部內容與分類</option>
					<?php foreach ( self::searchable_post_types() as $type ) : ?>
					<option value="post:<?php echo esc_attr( $type->name ); ?>"><?php echo esc_html( $type->labels->singular_name ); ?></option>
					<?php endforeach; ?>
					<?php foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) : ?>
					<option value="tax:<?php echo esc_attr( $tax->name ); ?>"><?php echo esc_html( $tax->labels->singular_name . '彙整頁' ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" data-picker-search>搜尋</button>
			</div>
			<p data-picker-status role="status" aria-live="polite">輸入名稱後搜尋，也可直接搜尋以瀏覽清單。</p>
			<ul data-picker-results class="wclon-page-picker__list"></ul>
			<button type="button" class="button" data-picker-more hidden>載入更多</button>
			<p><strong>已選擇的頁面與分類</strong></p>
			<ul data-picker-selected class="wclon-page-picker__list">
			<?php foreach ( array( 'post' => 'line_float_page_ids', 'term' => 'line_float_term_ids' ) as $kind => $key ) : ?>
				<?php foreach ( self::page_ids( WCLON_Settings::get( $key, array() ) ) as $id ) : ?>
					<?php
					$item  = 'post' === $kind ? get_post( $id ) : get_term( $id );
					$label = '已刪除的項目 #' . $id;
					if ( $item && ! is_wp_error( $item ) ) {
						$type  = 'post' === $kind ? get_post_type_object( $item->post_type ) : get_taxonomy( $item->taxonomy );
						$label = ( 'post' === $kind ? ( $item->post_title ?: '（無標題）' ) : $item->name ) . ' — ' . ( $type ? $type->labels->singular_name : $kind );
					}
					?>
					<li data-kind="<?php echo esc_attr( $kind ); ?>" data-id="<?php echo esc_attr( $id ); ?>"><span><?php echo esc_html( $label ); ?></span><input type="hidden" name="<?php echo esc_attr( WCLON_Settings::OPTION_KEY . '[' . $key . '][]' ); ?>" value="<?php echo esc_attr( $id ); ?>"><button type="button" class="button-link-delete" data-picker-remove aria-label="<?php echo esc_attr( '移除 ' . $label ); ?>">移除</button></li>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</ul>
			<p class="description">可加入多個項目。分類條件套用至該分類的彙整頁（含分頁），不會自動套用至分類內文章或子分類；購物車、結帳、會員中心包含其子端點。</p>
			<noscript><p>請啟用 JavaScript 以搜尋及調整選擇；原有項目仍會保留。</p></noscript>
		</div>
		<?php
	}

	public static function active() {
		if ( ! WCLON_Settings::get( 'line_float_enabled', 0 ) || ! self::devices() ) {
			return false;
		}
		$mode = WCLON_Settings::get( 'line_float_page_mode', 'all' );
		if ( ! in_array( $mode, array( 'include', 'exclude' ), true ) ) {
			return true;
		}
		// 僅比對內容頁，避免分類／作者 ID 與頁面 ID 碰撞；另外支援文章列表與 WooCommerce 商店頁。
		$page_id = is_singular() ? get_queried_object_id() : 0;
		if ( is_home() ) {
			$page_id = (int) get_option( 'page_for_posts' );
		} elseif ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
			$page_id = (int) wc_get_page_id( 'shop' );
		}
		$matches = $page_id > 0 && in_array( $page_id, self::page_ids( WCLON_Settings::get( 'line_float_page_ids', array() ) ), true );
		if ( is_category() || is_tag() || is_tax() ) {
			$matches = $matches || in_array( (int) get_queried_object_id(), self::page_ids( WCLON_Settings::get( 'line_float_term_ids', array() ) ), true );
		}
		$conditions = WCLON_Settings::get( 'line_float_conditions', array() );
		foreach ( is_array( $conditions ) ? $conditions : array() as $condition ) {
			if ( is_string( $condition ) && self::condition_matches( $condition ) ) {
				$matches = true;
				break;
			}
		}
		return 'include' === $mode ? $matches : ! $matches;
	}

	private static function style() {
		$style = array();
		foreach ( array( 'offset_x' => 20, 'offset_bottom' => 20, 'mobile_offset_x' => 14, 'mobile_offset_bottom' => 14, 'size' => 56 ) as $key => $default ) {
			$value   = self::dimension( WCLON_Settings::get( 'line_float_' . $key, $default ), $default, 'size' === $key ? 40 : 0, 'size' === $key ? 96 : 500 );
			$style[] = '--wclon-float-' . str_replace( '_', '-', $key ) . ':' . $value . 'px';
		}
		return implode( ';', $style );
	}

	public static function enqueue() {
		if ( self::active() ) {
			wp_enqueue_style( 'wclon-frontend', WCLON_PLUGIN_URL . 'assets/css/wclon-frontend.css', array(), WCLON_VERSION );
		}
	}

	public static function render() {
		$target = self::active() ? self::target() : null;
		if ( ! $target ) {
			return;
		}
		?>
		<a class="wclon-line-float wclon-line-float--<?php echo esc_attr( self::position() ); ?><?php foreach ( self::devices() as $device ) { echo ' wclon-line-float--show-' . esc_attr( $device ); } ?>" style="<?php echo esc_attr( self::style() ); ?>" href="<?php echo esc_url( $target['url'] ); ?>"<?php echo $target['blank'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> aria-label="<?php echo esc_attr( $target['label'] ); ?>">
			<svg viewBox="0 0 24 24" width="38" height="38" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2C6.48 2 2 5.75 2 10.36c0 4.13 3.56 7.6 8.37 8.26.33.07.77.22.88.5.1.25.07.65.03.9l-.14.85c-.04.25-.2.99.87.54 1.07-.45 5.76-3.39 7.86-5.8C21.3 13.9 22 12.2 22 10.36 22 5.75 17.52 2 12 2zM8.2 12.9H6.1a.5.5 0 0 1-.5-.5V8.5a.5.5 0 0 1 1 0v3.4h1.6a.5.5 0 0 1 0 1zm2.5-.5a.5.5 0 0 1-1 0V8.5a.5.5 0 0 1 1 0v3.9zm5 0a.5.5 0 0 1-.9.3l-2.1-2.85v2.55a.5.5 0 0 1-1 0V8.5a.5.5 0 0 1 .9-.3l2.1 2.85V8.5a.5.5 0 0 1 1 0v3.9zm3.1-2.3a.5.5 0 0 1 0 1h-1.6v.8h1.6a.5.5 0 0 1 0 1h-2.1a.5.5 0 0 1-.5-.5V8.5a.5.5 0 0 1 .5-.5h2.1a.5.5 0 0 1 0 1h-1.6v1.1h1.6z"/></svg>
		</a>
		<?php
	}
}
