const React = require( 'react' );

module.exports = {
	PluginSidebar: ( { children, title, icon } ) =>
		React.createElement(
			'div',
			{
				className: 'components-plugin-sidebar',
				'data-title': title,
				'data-icon': icon,
			},
			children
		),
};
