/** LINE 懸浮按鈕：依名稱搜尋並選取內容頁與分類彙整頁。 */
(function ($) {
	'use strict';
	var picker = document.querySelector('.wclon-page-picker');
	if (!picker) return;
	var search = picker.querySelector('#wclon-float-search');
	var filter = picker.querySelector('#wclon-float-filter');
	var results = picker.querySelector('[data-picker-results]');
	var selected = picker.querySelector('[data-picker-selected]');
	var status = picker.querySelector('[data-picker-status]');
	var more = picker.querySelector('[data-picker-more]');
	var request = null;
	var sequence = 0;
	var page = 0;
	var query = {};

	function hasItem(kind, id) {
		return Array.prototype.some.call(selected.children, function (item) {
			return item.dataset.kind === kind && item.dataset.id === String(id);
		});
	}
	function refreshButtons() {
		results.querySelectorAll('[data-picker-add]').forEach(function (button) {
			var exists = hasItem(button.dataset.kind, button.dataset.id);
			button.disabled = exists;
			button.textContent = exists ? '已加入' : '加入';
		});
	}
	function addItem(item) {
		if (hasItem(item.kind, item.id)) return;
		var row = document.createElement('li');
		row.dataset.kind = item.kind;
		row.dataset.id = String(item.id);
		var label = document.createElement('span');
		label.textContent = item.label;
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = picker.dataset.fieldPrefix + '[line_float_' + (item.kind === 'term' ? 'term_ids' : 'page_ids') + '][]';
		input.value = item.id;
		var remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'button-link-delete';
		remove.dataset.pickerRemove = '';
		remove.textContent = '移除';
		remove.setAttribute('aria-label', '移除 ' + item.label);
		row.append(label, input, remove);
		selected.appendChild(row);
		refreshButtons();
		status.textContent = '已加入：' + item.label + '。請儲存設定以套用。';
	}
	function load(nextPage) {
		var current = ++sequence;
		if (request) request.abort();
		if (nextPage === 1) {
			query = { search: search.value.trim(), filter: filter.value };
			results.textContent = '';
			page = 0;
			more.hidden = true;
		}
		more.disabled = true;
		results.setAttribute('aria-busy', 'true');
		status.textContent = '搜尋中…';
		request = $.post(ajaxurl, {
			action: 'wclon_float_search_pages', nonce: wclonAdmin.nonce,
			search: query.search, filter: query.filter, page: nextPage
		}).done(function (response) {
			if (current !== sequence) return;
			if (!response.success) {
				status.textContent = response.data && response.data.message ? response.data.message : '搜尋失敗，請重試。';
				return;
			}
			response.data.items.forEach(function (item) {
				var row = document.createElement('li');
				var label = document.createElement('span');
				label.textContent = item.label;
				var button = document.createElement('button');
				button.type = 'button';
				button.className = 'button button-small';
				button.dataset.pickerAdd = '';
				button.dataset.kind = item.kind;
				button.dataset.id = String(item.id);
				button.setAttribute('aria-label', '加入 ' + item.label);
				button.addEventListener('click', function () { addItem(item); });
				row.append(label, button);
				results.appendChild(row);
			});
			page = nextPage;
			more.hidden = !response.data.more;
			refreshButtons();
			status.textContent = results.children.length ? '已顯示 ' + results.children.length + ' 個項目，點選「加入」選取。' : '找不到符合名稱的項目，請調整名稱或類型。';
		}).fail(function (xhr, reason) {
			if (current !== sequence || reason === 'abort') return;
			status.textContent = '搜尋失敗，請重試；若登入已逾期，請重新載入設定頁。';
		}).always(function () {
			if (current !== sequence) return;
			request = null;
			more.disabled = false;
			results.setAttribute('aria-busy', 'false');
		});
	}
	picker.querySelector('[data-picker-search]').addEventListener('click', function () { load(1); });
	filter.addEventListener('change', function () { load(1); });
	search.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') { event.preventDefault(); load(1); }
	});
	more.addEventListener('click', function () { load(page + 1); });
	selected.addEventListener('click', function (event) {
		var button = event.target.closest('[data-picker-remove]');
		if (!button) return;
		var row = button.closest('li');
		var name = row.querySelector('span').textContent;
		var next = row.nextElementSibling || row.previousElementSibling;
		row.remove();
		refreshButtons();
		(next ? next.querySelector('button') : search).focus();
		status.textContent = '已移除：' + name + '。請儲存設定以套用。';
	});
})(jQuery);
