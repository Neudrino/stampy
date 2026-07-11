const React = require( 'react' );

module.exports = {
	PanelBody: ( { children, title } ) =>
		React.createElement(
			'div',
			{ className: 'components-panel__body' },
			React.createElement( 'h2', null, title ),
			children
		),
	CheckboxControl: ( { label, checked, onChange } ) =>
		React.createElement(
			'label',
			null,
			React.createElement( 'input', {
				type: 'checkbox',
				checked,
				onChange: ( e ) => onChange( e.target.checked ),
				'aria-label': label,
			} ),
			label
		),
	ToggleControl: ( { label, checked, onChange } ) =>
		React.createElement(
			'label',
			null,
			React.createElement( 'input', {
				type: 'checkbox',
				checked,
				onChange: ( e ) => onChange( e.target.checked ),
				'aria-label': label,
			} ),
			label
		),
	Notice: ( { children } ) =>
		React.createElement(
			'div',
			{ className: 'components-notice' },
			children
		),
};
