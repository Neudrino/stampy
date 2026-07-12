const React = require( 'react' );

module.exports = {
	PanelBody: ( { children, title } ) =>
		React.createElement(
			'div',
			{ className: 'components-panel__body' },
			React.createElement( 'h2', null, title ),
			children
		),
	TextControl: ( { value, onChange, placeholder, help } ) =>
		React.createElement(
			'div',
			{ className: 'components-text-control' },
			React.createElement( 'input', {
				type: 'text',
				value,
				onChange: ( e ) => onChange( e.target.value ),
				placeholder,
			} ),
			help &&
				React.createElement(
					'p',
					{ className: 'components-text-control__help' },
					help
				)
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
	SelectControl: ( { label, value, options, onChange, help } ) =>
		React.createElement(
			'div',
			{ className: 'components-select-control' },
			label && React.createElement( 'label', null, label ),
			React.createElement(
				'select',
				{
					value,
					onChange: ( e ) => onChange( e.target.value ),
				},
				( options || [] ).map( ( opt, i ) =>
					React.createElement(
						'option',
						{ key: i, value: opt.value },
						opt.label
					)
				)
			),
			help &&
				React.createElement(
					'p',
					{ className: 'components-select-control__help' },
					help
				)
		),
	Notice: ( { children, status } ) =>
		React.createElement(
			'div',
			{ className: `components-notice is-${ status }` },
			children
		),
	Button: ( { children, onClick, disabled, variant, isDestructive } ) =>
		React.createElement(
			'button',
			{
				onClick,
				disabled,
				className: `components-button is-${ variant || 'secondary' }${
					isDestructive ? ' is-destructive' : ''
				}`,
			},
			children
		),
	Spinner: () =>
		React.createElement( 'div', {
			className: 'components-spinner',
		} ),
};
