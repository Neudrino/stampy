const React = require( 'react' );

module.exports = {
	useBlockProps: jest.fn( ( props ) => ( {
		className: 'stampy-signup-block',
		...props,
	} ) ),
	InspectorControls: ( { children } ) =>
		React.createElement( 'div', null, children ),
};
