/* tldr-block summary block. No build step: uses the wp globals. */
(function (wp) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	function Preview(props) {
		var state = props.state;
		if (!state) {
			return el('p', { className: 'tldr-block__placeholder' }, __('No summary yet', 'tldr-block'));
		}
		if (state.mode !== 'on') {
			return el('p', { className: 'tldr-block__placeholder' }, __('Summaries are off for this category', 'tldr-block'));
		}
		var live = state.live;
		if (!live || live.withdrawn || (live.stale && !state.displayable)) {
			var msg = state.candidate ? __('Summary pending review', 'tldr-block') : __('No summary yet', 'tldr-block');
			return el('p', { className: 'tldr-block__placeholder' }, msg);
		}
		var heading = props.attributes.heading || __('Summary', 'tldr-block');
		var body = live.format === 'paragraph'
			? el('p', { className: 'tldr-block__text' }, live.text)
			: el('ul', { className: 'tldr-block__points' }, (live.points || []).map(function (p, i) {
				return el('li', { key: i }, p);
			}));
		return el('aside', { className: 'tldr-block', 'aria-label': heading },
			props.attributes.showHeading ? el('p', { className: 'tldr-block__heading' }, heading) : null,
			body
		);
	}

	wp.blocks.registerBlockType('tldr-block/summary', {
		edit: function (props) {
			var postId = props.context && props.context.postId;
			var pair = useState(null);
			var state = pair[0];
			var setState = pair[1];
			useEffect(function () {
				if (!postId) {
					return;
				}
				wp.apiFetch({ path: '/tldr-block/v1/posts/' + postId }).then(setState).catch(function () {
					setState(null);
				});
			}, [postId]);
			return el('div', useBlockProps(),
				el(InspectorControls, null,
					el(PanelBody, { title: __('Summary', 'tldr-block') },
						el(TextControl, {
							label: __('Heading', 'tldr-block'),
							value: props.attributes.heading,
							onChange: function (v) { props.setAttributes({ heading: v }); }
						}),
						el(ToggleControl, {
							label: __('Show heading', 'tldr-block'),
							checked: props.attributes.showHeading,
							onChange: function (v) { props.setAttributes({ showHeading: v }); }
						})
					)
				),
				el('div', { className: 'tldr-block__editor-preview' }, el(Preview, { state: state, attributes: props.attributes }))
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
