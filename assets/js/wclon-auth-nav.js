/**
 * 社交登入按鈕（LINE／Google／Apple／綁定連結）改用 mousedown 觸發導覽，
 * 不依賴瀏覽器對 <a> 的預設點擊動作（v1.31.1 新增）。
 *
 * 根因：Blocksy 佈景主題標頭帳號彈出視窗開啟後，`overlay.js` 的 showOffcanvas() 會用
 * setTimeout(200ms) 把焦點強制搶到表單第一個 <input>（讓使用者可以直接打字）。當按鈕位置
 * 設定為「上方」（btn_position=above）時，本外掛的社交按鈕會是彈窗裡最先看到、最容易第一個
 * 被點的元素——若使用者手速夠快、在那 200ms 內點下按鈕，瀏覽器在處理這次點擊的過程中焦點被
 * 搶走，導致該次點擊原本要觸發的 <a> 預設導覽動作被吃掉（右鍵「開啟連結」正常、原生左鍵點擊
 * 卻毫無反應）；點第二次、或先點別的地方讓那個 200ms 過去之後再點，才會正常，症狀完全對得上。
 *
 * 這是主題彈窗機制本身的既有行為，不是這幾顆按鈕的 href 或伺服器端有問題，因此不修 Blocksy、
 * 改成不依賴那次「點擊」的預設動作：mousedown 比 click 更早觸發，不會被 200ms 後才執行的
 * focus() 搶走，用它直接做導覽即可繞過整個競爭關係。保留 Ctrl/Cmd/Shift/中鍵，讓「開新分頁」
 * 這類瀏覽器原生行為不受影響。
 */
(function () {
	'use strict';

	var SELECTOR = '.wclon-auth-btn, .wclon-connect-btn, .wclon-chip';

	document.addEventListener( 'mousedown', function ( event ) {
		if ( event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
			return;
		}

		var link = event.target.closest( SELECTOR );

		if ( ! link || ! link.href ) {
			return;
		}

		window.location.href = link.href;
	} );
})();
