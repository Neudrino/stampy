declare module '@wordpress/api-fetch' {
	interface ApiFetchOptions {
		path?: string;
		url?: string;
		method?: string;
		data?: unknown;
		headers?: Record< string, string >;
		parse?: boolean;
	}

	interface ApiFetchMiddleware {
		(
			options: ApiFetchOptions,
			next: ( options: ApiFetchOptions ) => Promise< unknown >
		): Promise< unknown >;
	}

	const apiFetch: ( (
		options: ApiFetchOptions | string
	) => Promise< unknown > ) & {
		use: ( middleware: ApiFetchMiddleware ) => void;
		createNonceMiddleware: ( nonce: string ) => ApiFetchMiddleware;
	};

	export default apiFetch;
}
