import { renderToString } from 'react-dom/server';

const mockEditPost = jest.fn();

jest.mock( '@wordpress/element', () => ( {
	useState: ( initial: unknown ) => {
		let val = initial;
		return [
			val,
			( v: unknown ) => {
				val = v;
			},
		];
	},
	useEffect: () => {},
	useRef: ( initial: unknown ) => ( { current: initial } ),
} ) );

jest.mock( '@wordpress/plugins', () => {
	const React = require( 'react' );
	return {
		registerPlugin: jest.fn(),
		PluginSidebar: ( { children, title, icon }: any ) =>
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
} );

jest.mock( '@wordpress/editor', () => {
	const React = require( 'react' );
	return {
		PluginSidebar: ( { children, title, icon }: any ) =>
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
} );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn( () => ( {
		editPost: mockEditPost,
	} ) ),
	useSelect: jest.fn( ( selector: any ) => {
		const select: Record< string, any > = {
			'core/editor': {
				getCurrentPostId: () => 42,
				getCurrentPostType: () => 'stampy_campaign',
				getEditedPostAttribute: ( attr: string ) => {
					if ( attr === 'meta' ) {
						return {
							stampy_campaign_subject: 'Test Subject',
							stampy_campaign_list_ids: '[1]',
							stampy_campaign_status: 'draft',
							stampy_campaign_tracking: '',
						};
					}
					return undefined;
				},
			},
		};
		return selector( ( store: string ) => select[ store ] || {} );
	} ),
} ) );

import { registerPlugin } from '@wordpress/plugins';
import './index';

function getRender(): () => JSX.Element | null {
	const calls = ( registerPlugin as jest.Mock ).mock.calls;
	return calls[ calls.length - 1 ][ 1 ].render;
}

describe( 'CampaignSidebar', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		( window as unknown as { stampy: StampyGlobal } ).stampy = {
			restUrl: 'http://localhost/wp-json/stampy/v1',
			restNonce: 'test-nonce',
			lists: [
				{
					id: 1,
					name: 'Newsletter',
					slug: 'newsletter',
					description: '',
				},
			],
			consentText: 'Test consent',
			previewUrl: 'http://localhost/preview',
			ajaxUrl: 'http://localhost/wp-admin/admin-ajax.php',
			startSendNonce: 'start-nonce',
			cancelSendNonce: 'cancel-nonce',
			progressNonce: 'progress-nonce',
		};
		// Re-import to trigger registerPlugin with fresh mocks.
		jest.isolateModules( () => {
			require( './index' );
		} );
	} );

	it( 'registers the plugin', () => {
		expect( registerPlugin ).toHaveBeenCalledWith(
			'stampy-campaign-editor',
			expect.objectContaining( {
				render: expect.any( Function ),
			} )
		);
	} );

	it( 'renders the sidebar with Subject panel', () => {
		const html = renderToString( getRender()() );

		expect( html ).toContain( 'Campaign Settings' );
		expect( html ).toContain( 'Subject' );
		expect( html ).toContain( 'Test Subject' );
	} );

	it( 'renders Target Lists panel with available lists', () => {
		const html = renderToString( getRender()() );

		expect( html ).toContain( 'Target Lists' );
		expect( html ).toContain( 'Newsletter' );
	} );

	it( 'renders Send & Progress panel with Send button for draft campaigns', () => {
		const html = renderToString( getRender()() );

		expect( html ).toContain( 'Send &amp; Progress' );
		expect( html ).toContain( 'Send Campaign' );
		expect( html ).toContain( 'Status:' );
		expect( html ).toContain( 'draft' );
	} );

	it( 'renders Tracking panel', () => {
		const html = renderToString( getRender()() );

		expect( html ).toContain( 'Open &amp; Click Tracking' );
		expect( html ).toContain( 'Use global setting' );
	} );

	it( 'renders Preview panel', () => {
		const html = renderToString( getRender()() );

		expect( html ).toContain( 'Preview' );
		expect( html ).toContain( 'Preview HTML email' );
		expect( html ).toContain( 'Preview plain-text email' );
	} );

	it( 'returns null for non-campaign post types', () => {
		const data = require( '@wordpress/data' );
		( data.useSelect as jest.Mock ).mockImplementation(
			( selector: any ) => {
				const select: Record< string, any > = {
					'core/editor': {
						getCurrentPostId: () => 1,
						getCurrentPostType: () => 'post',
						getEditedPostAttribute: () => undefined,
					},
				};
				return selector( ( store: string ) => select[ store ] || {} );
			}
		);

		const html = renderToString( getRender()() );

		expect( html ).toBe( '' );

		// Restore the default mock.
		( data.useSelect as jest.Mock ).mockImplementation(
			( selector: any ) => {
				const select: Record< string, any > = {
					'core/editor': {
						getCurrentPostId: () => 42,
						getCurrentPostType: () => 'stampy_campaign',
						getEditedPostAttribute: ( attr: string ) => {
							if ( attr === 'meta' ) {
								return {
									stampy_campaign_subject: 'Test Subject',
									stampy_campaign_list_ids: '[1]',
									stampy_campaign_status: 'draft',
									stampy_campaign_tracking: '',
								};
							}
							return undefined;
						},
					},
				};
				return selector( ( store: string ) => select[ store ] || {} );
			}
		);
	} );
} );
