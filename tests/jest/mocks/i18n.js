module.exports = {
	__: ( text ) => text,
	sprintf: ( format, ...args ) => {
		let result = format;
		args.forEach( ( arg, i ) => {
			result = result.replace( `%${ i + 1 }$d`, String( arg ) );
			result = result.replace( `%${ i + 1 }$s`, String( arg ) );
			result = result.replace( `%d`, String( arg ) );
			result = result.replace( `%s`, String( arg ) );
		} );
		return result;
	},
};
