/**
 * 終極登入設定頁的互動腳本（v1.39.0 從頁面內嵌 <script> 抽出）。
 * 伺服器端的值（網站名稱、兩組 nonce）由 wp_localize_script() 放在 wclonAdmin。
 */
(function () {
	// ── Tab 切換 ──
	var groupTabs = document.querySelectorAll('[data-wclon-group]');
	var subtabs   = document.querySelectorAll('[data-wclon-tab]');
	var subnavs   = document.querySelectorAll('[data-wclon-subtabs]');
	var panes     = document.querySelectorAll('.wclon-tab-pane');

	subnavs.forEach(function (nav) { nav.setAttribute('role', 'tablist'); });
	// 同頁通知連結只會改變 hash，不會重新載入設定頁。
	window.addEventListener('hashchange', function () {
		showTab(window.location.hash.slice(1));
	});

	subtabs.forEach(function (tab) {
		var tabId = tab.dataset.wclonTab;
		tab.id = 'wclon-tab-control-' + tabId;
		tab.setAttribute('role', 'tab');
		tab.setAttribute('aria-controls', 'wclon-tab-' + tabId);
		tab.setAttribute('href', '#' + tabId);
	});
	panes.forEach(function (pane) {
		pane.id = 'wclon-tab-' + pane.dataset.tab;
		pane.setAttribute('role', 'tabpanel');
		if (Array.prototype.some.call(subtabs, function (tab) { return tab.dataset.wclonTab === pane.dataset.tab; })) {
			pane.setAttribute('aria-labelledby', 'wclon-tab-control-' + pane.dataset.tab);
		}
	});

	function showTab(tabId) {
		var selected = Array.prototype.find.call(subtabs, function (tab) { return tab.dataset.wclonTab === tabId; });
		if (!selected) return;
		var groupId = selected.dataset.wclonTabGroup;
		panes.forEach(function (p) {
			var isActive = p.dataset.tab === tabId;
			p.style.display = isActive ? '' : 'none';
			p.hidden = !isActive;
		});
		subtabs.forEach(function (t) {
			var isActive = t.dataset.wclonTab === tabId;
			t.classList.toggle('current', isActive);
			t.setAttribute('aria-selected', isActive ? 'true' : 'false');
			t.setAttribute('tabindex', isActive ? '0' : '-1');
		});
		groupTabs.forEach(function (groupTab) {
			var isActive = groupTab.dataset.wclonGroup === groupId;
			groupTab.classList.toggle('nav-tab-active', isActive);
			if (isActive) groupTab.setAttribute('aria-current', 'page');
			else groupTab.removeAttribute('aria-current');
		});
		subnavs.forEach(function (nav) {
			var isActive = nav.dataset.wclonSubtabs === groupId;
			nav.style.display = isActive && !nav.classList.contains('is-single') ? 'block' : 'none';
		});
		var mainSubmit = document.getElementById('wclon-main-submit');
		if (mainSubmit) mainSubmit.style.display = tabId === 'license' ? 'none' : '';
		var licenseNotice = document.getElementById('wclon-license-inline-notice');
		if (licenseNotice) licenseNotice.style.display = tabId === 'license' ? 'none' : '';
		try {
			sessionStorage.setItem('wclon_active_tab', tabId);
			sessionStorage.setItem('wclon_group_tab_' + groupId, tabId);
		} catch (e) {}
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', '#' + tabId);
		}
	}

	subtabs.forEach(function (tab) {
		tab.addEventListener('click', function (e) {
			e.preventDefault();
			showTab(this.dataset.wclonTab);
		});
	});
	groupTabs.forEach(function (groupTab) {
		groupTab.addEventListener('click', function (e) {
			e.preventDefault();
			var groupId = this.dataset.wclonGroup;
			var savedTab = '';
			try { savedTab = sessionStorage.getItem('wclon_group_tab_' + groupId) || ''; } catch (ignore) {}
			var target = Array.prototype.find.call(subtabs, function (tab) {
				return tab.dataset.wclonTabGroup === groupId && tab.dataset.wclonTab === savedTab;
			});
			showTab(target ? target.dataset.wclonTab : this.dataset.wclonDefaultTab);
		});
	});
	groupTabs.forEach(function (groupTab, index) {
		groupTab.addEventListener('keydown', function (e) {
			var next = null;
			if (e.key === 'ArrowRight') next = (index + 1) % groupTabs.length;
			if (e.key === 'ArrowLeft') next = (index - 1 + groupTabs.length) % groupTabs.length;
			if (e.key === 'Home') next = 0;
			if (e.key === 'End') next = groupTabs.length - 1;
			if (next === null) return;
			e.preventDefault();
			groupTabs[next].focus();
			groupTabs[next].click();
		});
	});

	// 採用 WordPress/WooCommerce 頁籤慣例：左右方向鍵在同一層頁籤間切換，Home/End
	// 可直接移到首尾；Tab 鍵仍按欄位自然順序前進。
	subtabs.forEach(function (tab) {
		tab.addEventListener('keydown', function (e) {
			var sameGroup = Array.prototype.filter.call(subtabs, function (item) { return item.dataset.wclonTabGroup === tab.dataset.wclonTabGroup; });
			var index = sameGroup.indexOf(tab);
			var next = null;
			if (e.key === 'ArrowRight') next = (index + 1) % sameGroup.length;
			if (e.key === 'ArrowLeft') next = (index - 1 + sameGroup.length) % sameGroup.length;
			if (e.key === 'Home') next = 0;
			if (e.key === 'End') next = sameGroup.length - 1;
			if (next === null) return;
			e.preventDefault();
			sameGroup[next].focus();
			showTab(sameGroup[next].dataset.wclonTab);
		});
	});

	try {
		var justSaved = /[?&]settings-updated=/.test(window.location.search);
			// 只有「儲存設定後被導回」才還原上次停留的頁籤；從後台側邊選單點進來一律回到預設頁籤。
			var saved = window.location.hash.slice(1) || (justSaved ? sessionStorage.getItem('wclon_active_tab') : '');
		// v1.36.0 起模組開關會讓部分頁籤整個不輸出（見 PHP 端 $mod_social／$mod_notify／
		// $mod_sysmail），validTabs 因此改成直接從「實際渲染出來的第二層頁籤」反推，不再寫死
		// 固定清單——寫死的清單在模組被關閉、原本存在 sessionStorage 的舊 tab id 對應的
		// 子頁籤／pane 都不再輸出時，會導致所有 pane 都比對不到、整頁空白（v1.20.0／v1.23.0
		// 拆頁籤/搬頁籤時就踩過同一種問題，這次用「從 DOM 反推」一次徹底解決，不用每次異動
		// 頁籤清單都要記得同步改這裡）。
		var validTabs = Array.prototype.map.call(subtabs, function (t) { return t.dataset.wclonTab; });
		showTab(saved && validTabs.indexOf(saved) !== -1 ? saved : validTabs[0]);
	} catch (e) {}

	// ── 顧客通知／管理員通知／Turnstile／系統信件／模組共用同一顆「儲存設定」按鈕 ──
	// 顧客通知屬於主表單（wclon-settings-form）本身；其餘幾個各自是獨立的 <form>（各自對應不同的
	// option 與 settings group，見 PHP 端註解），只是不想讓管理員在不同頁籤各自按一次。攔截主表單
	// 的 submit：先用 fetch 把其他表單背景送到 options.php，等全部都處理完再讓主表單走原生
	// submit（觸發 WordPress 標準的重新導向流程），頁面重新整理後所有 option 都已是最新值，也仍
	// 會顯示 WP 原生「設定已儲存」提示。日後若再新增獨立表單的頁籤，記得同步把它的 form id 加進
	// 下面 otherForms 陣列。管理員通知／系統信件的表單在對應模組關閉時整個不會輸出，
	// document.getElementById() 會拿到 null，靠 .filter(Boolean) 自然跳過，不需要另外判斷。
	(function () {
		var mainForm    = document.getElementById('wclon-settings-form');
		var otherForms  = ['wcan-settings-form', 'wclon-turnstile-settings-form', 'wclon-system-email-settings-form', 'wclon-module-settings-form']
			.map(function (id) { return document.getElementById(id); })
			.filter(Boolean);

		if (!mainForm || !otherForms.length) return;

		mainForm.addEventListener('submit', function (e) {
			e.preventDefault();

			var submitBtn = document.querySelector('#wclon-main-submit input[type="submit"]');
			if (submitBtn) {
				submitBtn.disabled = true;
				submitBtn.value = '儲存中⋯';
			}

			Promise.all(otherForms.map(function (form) {
				// 不能用 form.action：settings_fields() 會在表單裡輸出一個
				// name="action" 的隱藏欄位（value="update"），HTML 表單的 named-property
				// 行為讓這個子元素蓋掉了 form.action 這個 IDL 屬性本來該回傳的網址字串，
				// 讀到的會是那個 <input> 元素本身。必須改讀 action 屬性本身（相對路徑
				// "options.php"，瀏覽器會自動依目前頁面網址正確解析）。
				return fetch(form.getAttribute('action'), {
					method:      'POST',
					credentials: 'same-origin',
					body:        new FormData(form),
				}).catch(function (err) {
					// 背景儲存失敗不擋主表單送出，避免按了按鈕卻整頁卡住；
					// 失敗時該板塊的設定不會更新，但顧客通知（主表單本身）仍會正常存檔。
					console.error('wclon: 背景儲存失敗', err);
				});
			})).then(function () {
				// mainForm.submit 可能被 id/name="submit" 的送出按鈕遮蔽（HTML 表單的
				// named-property 行為，同名子元素會蓋掉表單物件原生的 submit() 方法），
				// 直接呼叫原型上的方法繞開這個問題。
				HTMLFormElement.prototype.submit.call(mainForm);
			});
		});
	}());

	// ── 按鈕形狀／配色／版型預覽 ──
	(function () {
		var shapeSel  = document.getElementById('wclon_btn_shape');
		var colorSel  = document.getElementById('wclon_btn_color_scheme');
		var layoutSel = document.getElementById('wclon_btn_layout');
		var alignSel  = document.getElementById('wclon_btn_align');
		var previews  = document.querySelectorAll('.wclon-preview-btn');

		if (!previews.length) return;

		if (shapeSel) {
			shapeSel.addEventListener('change', function () {
				previews.forEach(function (btn) {
					btn.className = btn.className.replace(/wclon-btn--(rounded|pill|square)/, 'wclon-btn--' + shapeSel.value);
				});
			});
		}

		if (colorSel) {
			colorSel.addEventListener('change', function () {
				var dark = 'dark' === colorSel.value;
				previews.forEach(function (btn) {
					if ('google' === btn.dataset.provider) {
						btn.className = btn.className.replace(/wclon-btn--google-(light|dark)/, 'wclon-btn--google-' + (dark ? 'dark' : 'light'));
					} else if ('apple' === btn.dataset.provider) {
						btn.className = btn.className.replace(/wclon-btn--apple-(black|white)/, 'wclon-btn--apple-' + (dark ? 'white' : 'black'));
					}
				});
			});
		}

		if (layoutSel) {
			layoutSel.addEventListener('change', function () {
				previews.forEach(function (btn) {
					btn.className = btn.className.replace(/wclon-btn--(full|icon)/, 'wclon-btn--' + layoutSel.value);
				});
				// 容器也要換 modifier：純 ICON 是橫排（跟前台的按鈕列一樣），全寬是直排
				var box = previews[0].parentNode;
				box.className = box.className.replace(/wclon-btn-preview--(full|icon)/, 'wclon-btn-preview--' + layoutSel.value);
			});
		}

		if (alignSel) {
			alignSel.addEventListener('change', function () {
				previews.forEach(function (btn) {
					btn.className = btn.className.replace(/wclon-btn--align-(left|center)/, 'wclon-btn--align-' + alignSel.value);
				});
				var box = previews[0].parentNode;
				box.className = box.className.replace(/wclon-btn-preview--align-(left|center)/, 'wclon-btn-preview--align-' + alignSel.value);
			});
		}
	}());

	// ── Flex Message 標題色：WordPress 內建色票選擇器（可直接輸入色號） ──
	// 用 jQuery(document).ready 延後執行：wp-color-picker 依賴的 iris／jquery-ui 系列 script
	// 大多掛在 footer 輸出，若跟其餘設定頁 JS 一樣立即執行，執行當下這些函式庫可能還沒載入。
	jQuery(function ($) {
		if ( ! $.fn.wpColorPicker ) {
			return;
		}
		// 用 .each() 各自用閉包記住自己的 <input>，不依賴 change／clear 回呼裡 this／
		// event.target 實際指向哪個元素（wp-color-picker 兩個回呼的綁定對象並不一致）。
		$('.wclon-color-field').each(function () {
			var el = this;
			function notify() {
				setTimeout(function () {
					el.dispatchEvent(new Event('input', { bubbles: true }));
				}, 0);
			}
			$(el).wpColorPicker({
				change: notify,
				clear: notify
			});
		});

	});

	// ── Flex Message 樣式即時預覽 ──
	(function () {
		var siteName = wclonAdmin.siteName;
		var DUMMY = {
			orderNumber: '#TEST-001',
			status: '處理中',
			total: 'NT$1,200',
			payment: '信用卡',
			items: [['範例商品 A', '×2'], ['範例商品 B', '×1']],
			customerName: '顧客',
			noteText: '您好，商品已確認出貨，感謝您的訂購！',
			logisticsText: '貨物已送達，感謝您的購買！',
			couponCode: 'WELCOME100',
			couponExpiry: '2026-12-31 前有效'
		};

		function esc(str) {
			var div = document.createElement('div');
			div.textContent = (str === null || str === undefined) ? '' : String(str);
			return div.innerHTML;
		}

		function renderTemplate(tpl, vars) {
			return String(tpl || '').replace(/\{([a-z_]+)\}/gi, function (match, key) {
				return Object.prototype.hasOwnProperty.call(vars, key) ? vars[key] : match;
			});
		}

		function val(id, fallback) {
			var el = document.getElementById(id);
			if (!el || '' === el.value) return fallback || '';
			return el.value;
		}

		function couponAmountText() {
			var type   = val('wclon_coupon_type', 'fixed_cart');
			var amount = parseFloat(val('wclon_coupon_amount', '100')) || 0;
			if ('percent' === type) {
				return amount.toFixed(2).replace(/0+$/, '').replace(/\.$/, '') + '%';
			}
			return 'NT$' + amount.toLocaleString('en-US');
		}

		function row(label, value, valueColor) {
			return '<div class="wclon-flex-preview__row">' +
				'<span class="wclon-flex-preview__row-label">' + esc(label) + '</span>' +
				'<span class="wclon-flex-preview__row-value"' + (valueColor ? ' style="color:' + esc(valueColor) + ';"' : '') + '>' + esc(value) + '</span>' +
				'</div>';
		}

		function bubbleHtml(color, headerTitle, bodyHtml, btnText) {
			return '<div class="wclon-flex-preview__header" style="background-color:' + esc(color) + ';">' +
				'<span class="wclon-flex-preview__site">' + esc(siteName) + '</span>' +
				'<span class="wclon-flex-preview__title">' + esc(headerTitle) + '</span>' +
				'</div>' +
				'<div class="wclon-flex-preview__body">' + bodyHtml + '</div>' +
				'<div class="wclon-flex-preview__footer">' +
				'<span class="wclon-flex-preview__btn" style="background-color:' + esc(color) + ';">' + esc(btnText) + '</span>' +
				'</div>';
		}

		function renderCustomerPreview() {
			var box     = document.getElementById('wclon_flex_preview_customer');
			var typeSel = document.getElementById('wclon_flex_preview_type');
			if (!box || !typeSel) return;

			var type       = typeSel.value;
			if ('coupon' === type) {
				box.innerHTML = couponBubble();
				return;
			}
			var buttonText = val('wclon_button_text', '查看訂單詳情');
			var greeting   = renderTemplate(val('wclon_greeting_template', '您好，{customer_name}！'), {
				customer_name: DUMMY.customerName,
				site_name: siteName
			});
			var color, title, bodyHtml;

			if ('note' === type) {
				color    = val('wclon_note_color', '#FF9800');
				title    = val('wclon_note_title', '店家留言');
				bodyHtml = '<div class="wclon-flex-preview__greeting">' + esc(greeting) + '</div>' +
					'<hr class="wclon-flex-preview__sep">' +
					row('訂單編號', DUMMY.orderNumber) +
					'<hr class="wclon-flex-preview__sep">' +
					'<div class="wclon-flex-preview__section-label">' + esc(title) + '</div>' +
					'<div class="wclon-flex-preview__text">' + esc(DUMMY.noteText) + '</div>';
			} else if ('logistics' === type) {
				color    = val('wclon_header_color', '#00C300');
				title    = val('wclon_logistics_title', '🚚 物流狀態更新');
				bodyHtml = '<div class="wclon-flex-preview__greeting">' + esc(greeting) + '</div>' +
					'<hr class="wclon-flex-preview__sep">' +
					row('訂單編號', DUMMY.orderNumber) +
					'<hr class="wclon-flex-preview__sep">' +
					'<div class="wclon-flex-preview__section-label">' + esc(title) + '</div>' +
					'<div class="wclon-flex-preview__text">' + esc(DUMMY.logisticsText) + '</div>';
			} else {
				color = val('wclon_header_color', '#00C300');
				title = DUMMY.status;
				var itemRows = DUMMY.items.map(function (item) {
					return row('・' + item[0], item[1]);
				}).join('');
				bodyHtml = '<div class="wclon-flex-preview__greeting">' + esc(greeting) + '</div>' +
					'<hr class="wclon-flex-preview__sep">' +
					row('訂單編號', DUMMY.orderNumber) +
					row('訂單狀態', DUMMY.status, color) +
					row('訂單金額', DUMMY.total) +
					row('付款方式', DUMMY.payment) +
					'<hr class="wclon-flex-preview__sep">' +
					'<div class="wclon-flex-preview__section-label">購買商品</div>' +
					itemRows;
			}

			box.innerHTML = bubbleHtml(color, title, bodyHtml, buttonText);
		}

		function couponBubble() {
			var color   = val('wclon_header_color', '#00C300');
			var title   = val('wclon_bind_coupon_title', '🎁 專屬優惠券');
			var btnText = val('wclon_bind_coupon_button_text', '前往購物');
			var vars    = {
				site_name: siteName,
				coupon_code: DUMMY.couponCode,
				coupon_amount: couponAmountText()
			};
			var greeting    = renderTemplate(val('wclon_bind_coupon_greeting', '感謝您綁定 LINE 帳號！'), vars);
			var description = renderTemplate(val('wclon_bind_coupon_desc', '結帳時輸入上方代碼即可折抵，僅限本人帳號使用一次。'), vars);

			var bodyHtml = '<div class="wclon-flex-preview__greeting">' + esc(greeting) + '</div>' +
				'<hr class="wclon-flex-preview__sep">' +
				row('優惠券代碼', DUMMY.couponCode) +
				row('折扣內容', vars.coupon_amount) +
				row('使用效期', DUMMY.couponExpiry) +
				'<div class="wclon-flex-preview__desc">' + esc(description) + '</div>';

			return bubbleHtml(color, title, bodyHtml, btnText);
		}

		// 欄位 id → 編輯時預覽要切到哪一種訊息（null＝共用欄位，不切換）
		var fieldPreviewType = {
			wclon_header_color: null,
			wclon_note_color: 'note',
			wclon_button_text: 'status',
			wclon_greeting_template: 'status',
			wclon_note_title: 'note',
			wclon_logistics_title: 'logistics',
			wclon_bind_coupon_title: 'coupon',
			wclon_bind_coupon_greeting: 'coupon',
			wclon_bind_coupon_desc: 'coupon',
			wclon_bind_coupon_button_text: 'coupon',
			wclon_coupon_type: 'coupon',
			wclon_coupon_amount: 'coupon'
		};

		var typeSel = document.getElementById('wclon_flex_preview_type');
		if (typeSel) {
			typeSel.addEventListener('change', renderCustomerPreview);
		}

		Object.keys(fieldPreviewType).forEach(function (id) {
			var el = document.getElementById(id);
			if (!el) return;
			var onEdit = function () {
				var want = fieldPreviewType[id];
				if (want && typeSel && typeSel.value !== want && typeSel.querySelector('option[value="' + want + '"]')) {
					typeSel.value = want;
				}
				renderCustomerPreview();
			};
			el.addEventListener('input', onEdit);
			el.addEventListener('change', onEdit);
		});

		renderCustomerPreview();
	}());

	// ── 測試推播 ──
	(function ($) {
		$('#wclon_test_btn').on('click', function () {
			var btn    = $(this);
			var msg    = $('#wclon_test_msg');
			var lineId = $('#wclon_test_line_id').val().trim();
			btn.prop('disabled', true).text('發送中⋯');
			msg.css('color', '#555').text('');
			$.post(ajaxurl, {
				action:       'wclon_test_push',
				nonce:        wclonAdmin.nonce,
				line_user_id: lineId,
			}, function (res) {
				btn.prop('disabled', false).text('發送測試訊息');
				if (res.success) {
					msg.css('color', '#00a32a').text(res.data.message);
				} else {
					msg.css('color', '#d63638').text(res.data.message || '發送失敗');
				}
			});
		});
	}(jQuery));
}());

// ── 管理員通知：測試推播（原 WCAN_Settings::render_tab_content() 內嵌）──
(function ($) {
	$('#wcan_test_btn').on('click', function () {
		var btn    = $(this);
		var msg    = $('#wcan_test_msg');
		var lineId = $('#wcan_test_line_id').val().trim();
		btn.prop('disabled', true).text('發送中⋯');
		msg.css('color', '#555').text('');
		$.post(ajaxurl, {
			action:       'wcan_test_push',
			nonce:        wclonAdmin.wcanNonce,
			line_user_id: lineId,
		}, function (res) {
			btn.prop('disabled', false).text('發送測試訊息');
			if (res.success) {
				msg.css('color', '#00a32a').text(res.data.message);
			} else {
				msg.css('color', '#d63638').text(res.data.message || '發送失敗');
			}
		});
	});
}(jQuery));
