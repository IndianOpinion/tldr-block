/* tldr-block document sidebar panel. No build step: uses the wp globals. */
(function (wp) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var C = wp.components;
	var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || wp.editPost.PluginDocumentSettingPanel;

	function call(postId, method, action, data) {
		return wp.apiFetch({
			path: '/tldr-block/v1/posts/' + postId + (action ? '/' + action : ''),
			method: method,
			data: data
		});
	}

	function List(props) {
		var item = props.item;
		if (!item) {
			return null;
		}
		if (item.format === 'paragraph') {
			return el('p', null, item.text);
		}
		return el('ul', { style: { listStyle: 'disc', paddingInlineStart: '1.25em' } },
			(item.points || []).map(function (p, i) { return el('li', { key: i }, p); }));
	}

	function SummaryPanel() {
		var postId = useSelect(function (select) { return select('core/editor').getCurrentPostId(); }, []);
		var s = useState(null), state = s[0], setState = s[1];
		var e = useState(''), error = e[0], setError = e[1];
		var d = useState(null), draft = d[0], setDraft = d[1];
		var r = useState(''), reason = r[0], setReason = r[1];
		var m = useState(null), own = m[0], setOwn = m[1];

		function load() {
			return call(postId, 'GET').then(setState).catch(function (err) { setError(err.message || String(err)); });
		}
		function act(method, action, data) {
			setError('');
			return call(postId, method, action, data).then(function (next) {
				setState(next);
				setDraft(null);
			}).catch(function (err) {
				setError(err.message || String(err));
				if (err && err.code === 'superseded') {
					load();
				}
			});
		}
		useEffect(function () { if (postId) { load(); } }, [postId]);

		if (!state) {
			return el(Panel, { name: 'tldr-block', title: __('Summary', 'tldr-block') },
				error ? el(C.Notice, { status: 'error', isDismissible: false }, error) : el(C.Spinner));
		}
		var has = function (a) { return state.actions.indexOf(a) !== -1; };
		var c = state.candidate;
		var children = [
			el('p', { key: 'st' }, __('Status:', 'tldr-block') + ' ' + state.status + (state.live && state.live.stale ? ' (' + __('stale', 'tldr-block') + ')' : '')),
			error ? el(C.Notice, { key: 'err', status: 'error', isDismissible: false }, error) : null,
			state.live ? el('div', { key: 'live' }, el('strong', null, __('Live summary', 'tldr-block')), el(List, { item: state.live })) : null
		];
		if (c) {
			var warn = (c.warnings || []).map(function (w) {
				return el('p', { key: w, className: 'description' }, __('Warning:', 'tldr-block') + ' ' + w +
					(w === 'unverified_figures' ? ': ' + (c.unverified_figures || []).join(', ') : ''));
			});
			children.push(el('div', { key: 'cand' },
				el('strong', null, __('Candidate', 'tldr-block')),
				el('p', { className: 'description' }, (c.model || '') + ' ' + new Date(c.generated_at * 1000).toLocaleString()),
				draft === null ? el(List, { item: c }) : el(C.TextareaControl, {
					label: __('One point per line', 'tldr-block'),
					value: draft,
					onChange: setDraft
				}),
				warn));
		}
		var buttons = [];
		if (c && has('approve') && draft === null) {
			buttons.push(el(C.Button, { key: 'a', variant: 'primary', onClick: function () { act('POST', 'approve', { candidate_id: c.id }); } }, __('Approve', 'tldr-block')));
		}
		if (c && has('edit_approve')) {
			buttons.push(draft === null
				? el(C.Button, { key: 'e', variant: 'secondary', onClick: function () { setDraft(c.format === 'paragraph' ? c.text : (c.points || []).join('\n')); } }, __('Edit and approve', 'tldr-block'))
				: el(C.Button, { key: 'e2', variant: 'primary', onClick: function () {
					var body = c.format === 'paragraph' ? { candidate_id: c.id, text: draft } : { candidate_id: c.id, points: draft.split('\n').filter(Boolean) };
					act('POST', 'approve', body);
				} }, __('Save and approve', 'tldr-block')));
		}
		if (c && has('reject')) {
			buttons.push(el(C.TextControl, { key: 'rr', label: __('Reason (optional)', 'tldr-block'), value: reason, onChange: setReason, maxLength: 200 }));
			buttons.push(el(C.Button, { key: 'r', variant: 'secondary', isDestructive: true, onClick: function () { act('POST', 'reject', { candidate_id: c.id, reason: reason }); } }, __('Reject', 'tldr-block')));
			if (has('reject_keep_previous')) {
				buttons.push(el(C.Button, { key: 'rk', variant: 'tertiary', onClick: function () { act('POST', 'reject', { candidate_id: c.id, reason: reason, keep_previous: true }); } }, __('Reject and keep previous', 'tldr-block')));
			}
		}
		if (has('generate')) {
			buttons.push(el(C.Button, { key: 'g', variant: 'secondary', onClick: function () { act('POST', 'generate', {}); } }, __('Generate', 'tldr-block')));
		}
		if (has('withdraw')) {
			buttons.push(el(C.Button, { key: 'w', variant: 'tertiary', isDestructive: true, onClick: function () { act('POST', 'withdraw', {}); } }, __('Withdraw', 'tldr-block')));
		}
		if (has('restore')) {
			buttons.push(el(C.SelectControl, {
				key: 'h',
				label: __('Restore previous', 'tldr-block'),
				value: '',
				options: [{ label: '', value: '' }].concat(state.history.map(function (h) {
					var label = h.kind + ': ' + (h.format === 'paragraph' ? h.text : (h.points || []).join(' / '));
					return { label: label.slice(0, 80), value: h.history_id };
				})),
				onChange: function (v) { if (v) { act('POST', 'restore', { history_id: v }); } }
			}));
		}
		if (has('manual') && !c) {
			if (own === null) {
				buttons.push(el(C.Button, { key: 'm', variant: 'tertiary', onClick: function () { setOwn(''); } }, __('Write my own', 'tldr-block')));
			} else {
				buttons.push(el(C.TextareaControl, { key: 'mt', label: __('One point per line', 'tldr-block'), value: own, onChange: setOwn }));
				buttons.push(el(C.Button, { key: 'ms', variant: 'primary', onClick: function () {
					act('PUT', 'manual', { points: own.split('\n').filter(Boolean) }).then(function () { setOwn(null); });
				} }, __('Save my summary', 'tldr-block')));
			}
		}
		if (has('manual')) {
			buttons.push(el(C.SelectControl, {
				key: 'o',
				label: __('This post', 'tldr-block'),
				value: state.override,
				options: [
					{ label: __('Follow the category', 'tldr-block'), value: 'auto' },
					{ label: __('Always on', 'tldr-block'), value: 'on' },
					{ label: __('Always off', 'tldr-block'), value: 'off' }
				],
				onChange: function (v) { act('POST', 'override', { mode: v }); }
			}));
		}
		children.push(el('div', { key: 'b', style: { display: 'flex', flexDirection: 'column', gap: '8px' } }, buttons));
		return el(Panel, { name: 'tldr-block', title: __('Summary', 'tldr-block') }, children);
	}

	wp.plugins.registerPlugin('tldr-block', { render: SummaryPanel });
})(window.wp);
