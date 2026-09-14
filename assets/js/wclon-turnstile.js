/**
 * Cloudflare Turnstile widget 渲染（v1.24.0 新增，v1.25.2 修正尺寸誤判踩坑）。
 *
 * api.js 用 render=explicit 載入，所有 widget 都由這裡自己呼叫 turnstile.render()——
 * 隱式渲染（api.js 自己掃 .cf-turnstile）只在腳本載入當下掃一次 DOM，而 Blocksy 佈景主題
 * 標頭的帳號彈出視窗是 AJAX 送出、失敗時整段表單 HTML 會被換掉（見
 * blocksy-companion/framework/features/account-auth.php），換進來的新表單裡那個全新的容器
 * 不會有人去渲染它，顧客就會卡在「送出後永遠驗證不過」。
 *
 * v1.25.2 修正：容器還沒有實際版面寬度時延後渲染（見下方 renderAll() 的說明），確保
 * `data-size="flexible"` 量到的永遠是表單「當下」的真實寬度，不需要、也不應該在 CSS 另外
 * 寫死任何 px 數值去將就。
 *
 * **這裡改用輪詢、不用 ResizeObserver**：直覺上「等祖先從 display:none 變成可見」應該是
 * ResizeObserver 的教科書用法，但實際在 Chrome 測試（隔離出一個最簡單的 display:none 祖先
 * + observe 子元素 + 切成 display:block 的重現案例）發現 ResizeObserver **完全不會觸發**
 * ——包括 observe() 當下該有的初始通知，以及祖先變成可見之後那次，一次都沒有。等同這段
 * 情境下 ResizeObserver 整個失效，若沿用它，widget 會永遠卡在「還沒渲染」，比修正前「量到
 * 錯誤寬度」的原始問題更嚴重（原本至少會渲染出東西）。改用單純的 setInterval 輪詢
 * `offsetWidth`，同樣的重現案例下輪詢確實能正確偵測到，且不依賴任何特定 CSS 屬性名稱，
 * 對任何主題用什麼手法切換顯示（class、inline style、甚至 JS 動畫）都一體適用。
 */
(function () {
	'use strict';

	var apiReady = false;
	var renderTimer = null;
	var POLL_INTERVAL_MS = 150;
	var MAX_POLL_ATTEMPTS = 100; // 150ms * 100 = 15 秒逾時上限

	function buildOptions(el) {
		return {
			sitekey: el.getAttribute('data-sitekey'),
			theme: el.getAttribute('data-theme') || 'auto',
			size: el.getAttribute('data-size') || 'normal',
			appearance: el.getAttribute('data-appearance') || 'always',
			action: el.getAttribute('data-action') || '',
			// token 有效期只有 300 秒，過期自動換一張，避免顧客慢慢填表單後送出才發現驗證失效
			'refresh-expired': 'auto',
			'retry': 'auto',
			// 這個名稱要跟 PHP 端 WCLON_Turnstile::TOKEN_FIELD 一致
			'response-field-name': 'cf-turnstile-response'
		};
	}

	function renderNow(el) {
		// 先標記再渲染：MutationObserver 會被 turnstile.render() 自己插入的 iframe 觸發，
		// 沒先標記的話同一個容器會在下一輪又被抓出來渲染一次。
		el.setAttribute('data-wclon-rendered', '1');
		el.removeAttribute('data-wclon-pending');

		try {
			// 記住 Cloudflare 回傳的 widget id：Elementor 表單送出後要用它 reset（見 bindElementorReset）
			var widgetId = window.turnstile.render(el, buildOptions(el));
			if (widgetId !== undefined && widgetId !== null) {
				el.setAttribute('data-wclon-widget-id', widgetId);
			}
		} catch (e) {
			el.removeAttribute('data-wclon-rendered');
			if (window.console) {
				console.error('wclon: Turnstile widget 渲染失敗', e);
			}
		}
	}

	function renderAll() {
		if (!apiReady || typeof window.turnstile === 'undefined') {
			return;
		}

		var nodes = document.querySelectorAll(
			'.wclon-turnstile[data-sitekey]:not([data-wclon-rendered]):not([data-wclon-pending])'
		);

		Array.prototype.forEach.call(nodes, function (el) {
			if (el.offsetWidth > 0) {
				renderNow(el);
				return;
			}

			// 容器現在還量不到寬度：通常是祖先元素還在 `display:none`（例如 Blocksy 標頭帳號
			// 彈出視窗，第一次點擊只是把表單從 <template> 複製進 DOM，此時外層 panel 還沒加上
			// 觸發顯示的 class，要等第二次互動才變成 display:flex）。若在這個時間點就呼叫
			// turnstile.render()，Cloudflare 量到的是 0，`data-size="flexible"` 便會失去依據、
			// 退化成某個跟表單實際寬度對不上的尺寸——widget 之後即使容器顯示出來也不會重新量測
			// （Cloudflare 只在 render() 當下量一次，不會持續 watch 容器），問題就永久卡住。
			// 用輪詢等到這個容器真正有寬度（等同已經在畫面上顯示）才渲染（原因見檔案開頭註解：
			// ResizeObserver 在這個情境下實測完全不會觸發）。
			el.setAttribute('data-wclon-pending', '1');
			pollUntilVisible(el, MAX_POLL_ATTEMPTS);
		});
	}

	/**
	 * 每 150ms 檢查一次容器是否已經有實際寬度，最多等 15 秒（100 次）。逾時仍未可見的話
	 * 放棄等待、直接渲染——寧可用可能不準確的尺寸渲染出東西，也不要讓 widget 永遠是空的
	 * （沒有這個上限，若某個情境下容器永遠不會顯示，就會無限期輪詢下去）。
	 */
	function pollUntilVisible(el, attemptsLeft) {
		if (el.offsetWidth > 0 || attemptsLeft <= 0) {
			renderNow(el);
			return;
		}
		setTimeout(function () {
			pollUntilVisible(el, attemptsLeft - 1);
		}, POLL_INTERVAL_MS);
	}

	/**
	 * Elementor 表單是 AJAX 送出，送出後整張表單還留在畫面上——但 Cloudflare 的 token 是
	 * **一次性**的（後端 siteverify 驗過一次就作廢，同一個 token 再送一定拿到
	 * `timeout-or-duplicate`）。所以只要送出過一次，不論成功或失敗，都必須 reset widget 換一張
	 * 新 token，否則顧客在同一頁再送第二次會永遠驗證失敗——而且畫面上 widget 看起來還是綠色
	 * 打勾的「已驗證」狀態，完全看不出問題出在哪。
	 *
	 * Elementor Pro 在表單元素上觸發 jQuery 事件 `submit_success` / `submit_error`（見
	 * elementor-pro/assets/js/frontend.js 的表單 handler），兩個都綁：失敗要換 token 才能重送，
	 * 成功後表單可能被清空重複使用（同一頁多次送出）也一樣需要。用事件委派綁在 document 上，
	 * 因此 AJAX 之後才進場的表單也接得到。
	 */
	function resetWidgetsIn(form) {
		if (typeof window.turnstile === 'undefined' || !form || !form.querySelectorAll) {
			return;
		}
		var nodes = form.querySelectorAll('.wclon-turnstile[data-wclon-widget-id]');
		Array.prototype.forEach.call(nodes, function (el) {
			try {
				window.turnstile.reset(el.getAttribute('data-wclon-widget-id'));
			} catch (e) {
				if (window.console) {
					console.error('wclon: Turnstile widget reset 失敗', e);
				}
			}
		});
	}

	function bindElementorReset() {
		// Elementor 一定會載入 jQuery（它自己的前台腳本就依賴 jQuery），這裡仍然保護一下：
		// 沒有 jQuery 就代表站上根本沒有 Elementor 表單，不綁也沒差。
		if (!window.jQuery) {
			return;
		}
		window.jQuery(document).on('submit_success submit_error', '.elementor-form', function () {
			resetWidgetsIn(this);
		});
	}

	function scheduleRender() {
		clearTimeout(renderTimer);
		renderTimer = setTimeout(renderAll, 50);
	}

	// api.js 載入完成後呼叫（URL 上的 onload=wclonTurnstileOnload）。這支腳本是一般同步腳本、
	// api.js 帶 defer，執行順序保證這個 callback 在 api.js 執行前就已經定義好。
	window.wclonTurnstileOnload = function () {
		apiReady = true;
		renderAll();
	};

	if (window.MutationObserver) {
		new MutationObserver(scheduleRender).observe(document.documentElement, {
			childList: true,
			subtree: true
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			renderAll();
			bindElementorReset();
		});
	} else {
		renderAll();
		bindElementorReset();
	}
}());
