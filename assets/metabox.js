/* tldr-block classic editor meta box. No build step: uses wp.apiFetch. */
(function (wp) {
	'use strict';
	var __ = wp.i18n.__;

	function node(tag, text, attrs) {
		var n = document.createElement(tag);
		if (text) {
			n.textContent = text;
		}
		Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
		return n;
	}

	function list(item) {
		if (item.format === 'paragraph') {
			return node('p', item.text);
		}
		var ul = node('ul', '', { style: 'list-style:disc;padding-inline-start:1.25em' });
		(item.points || []).forEach(function (p) { ul.appendChild(node('li', p)); });
		return ul;
	}

	function mount(box) {
		var postId = box.getAttribute('data-post-id');
		function call(method, action, data) {
			return wp.apiFetch({ path: '/tldr-block/v1/posts/' + postId + (action ? '/' + action : ''), method: method, data: data })
				.then(render)
				.catch(function (err) {
					var msg = node('p', err.message || String(err), { class: 'notice notice-error' });
					box.insertBefore(msg, box.firstChild);
				});
		}
		function button(label, fn) {
			var b = node('button', label, { type: 'button', class: 'button', style: 'margin:2px' });
			b.addEventListener('click', fn);
			return b;
		}
		function render(state) {
			box.textContent = '';
			var has = function (a) { return state.actions.indexOf(a) !== -1; };
			box.appendChild(node('p', __('Status:', 'tldr-block') + ' ' + state.status + (state.live && state.live.stale ? ' (' + __('stale', 'tldr-block') + ')' : '')));
			if (state.live) {
				box.appendChild(node('strong', __('Live summary', 'tldr-block')));
				box.appendChild(list(state.live));
			}
			var c = state.candidate;
			if (c) {
				box.appendChild(node('strong', __('Candidate', 'tldr-block')));
				box.appendChild(list(c));
				(c.warnings || []).forEach(function (w) {
					box.appendChild(node('p', __('Warning:', 'tldr-block') + ' ' + w, { class: 'description' }));
				});
				if (has('approve')) {
					box.appendChild(button(__('Approve', 'tldr-block'), function () { call('POST', 'approve', { candidate_id: c.id }); }));
				}
				if (has('edit_approve')) {
					box.appendChild(button(__('Edit and approve', 'tldr-block'), function () {
						var current = c.format === 'paragraph' ? c.text : (c.points || []).join('\n');
						var area = node('textarea', '', { rows: '6', style: 'width:100%' });
						area.value = current;
						box.appendChild(area);
						box.appendChild(button(__('Save and approve', 'tldr-block'), function () {
							var body = c.format === 'paragraph' ? { candidate_id: c.id, text: area.value } : { candidate_id: c.id, points: area.value.split('\n').filter(Boolean) };
							call('POST', 'approve', body);
						}));
					}));
				}
				if (has('reject')) {
					box.appendChild(button(__('Reject', 'tldr-block'), function () {
						var reason = window.prompt(__('Reason (optional)', 'tldr-block')) || '';
						call('POST', 'reject', { candidate_id: c.id, reason: reason.slice(0, 200), keep_previous: has('reject_keep_previous') && window.confirm(__('Keep the previous summary?', 'tldr-block')) });
					}));
				}
			}
			if (has('generate')) {
				box.appendChild(button(__('Generate', 'tldr-block'), function () { call('POST', 'generate', {}); }));
			}
			if (has('withdraw')) {
				box.appendChild(button(__('Withdraw', 'tldr-block'), function () { call('POST', 'withdraw', {}); }));
			}
			if (has('restore') && state.history.length) {
				box.appendChild(button(__('Restore previous', 'tldr-block'), function () { call('POST', 'restore', { history_id: state.history[0].history_id }); }));
			}
			if (has('manual')) {
				box.appendChild(button(__('Write my own', 'tldr-block'), function () {
					var area = node('textarea', '', { rows: '6', style: 'width:100%', placeholder: __('One point per line', 'tldr-block') });
					box.appendChild(area);
					box.appendChild(button(__('Save my summary', 'tldr-block'), function () {
						call('PUT', 'manual', { points: area.value.split('\n').filter(Boolean) });
					}));
				}));
			}
		}
		call('GET', '', undefined);
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('.tldr-block-metabox'), mount);
	});
})(window.wp);
