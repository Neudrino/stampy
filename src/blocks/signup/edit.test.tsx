import { renderToString } from 'react-dom/server';

import Edit from './edit';

const mockLists: StampyList[] = [
	{ id: 1, name: 'Newsletter', slug: 'newsletter', description: '' },
	{ id: 2, name: 'Promotions', slug: 'promotions', description: '' },
];

const defaultProps = {
	attributes: {
		list_ids: [] as number[],
		show_first_name: true,
		show_last_name: true,
	},
	setAttributes: jest.fn(),
	className: '',
	clientId: 'test-client-id',
	isSelected: true,
	context: {},
	name: 'stampy/signup',
};

function setupStampyGlobal() {
	( window as unknown as { stampy: StampyGlobal } ).stampy = {
		restUrl: 'http://localhost/wp-json/stampy/v1',
		restNonce: 'test-nonce',
		lists: mockLists,
		consentText: 'Test consent text',
	};
}

describe( 'SignupBlock Edit', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		setupStampyGlobal();
		document.body.innerHTML = '';
	} );

	it( 'renders email input field with required attribute', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'type="email"' );
		expect( html ).toContain( 'required' );
		expect( html ).toContain( 'aria-required' );
	} );

	it( 'renders first name field by default', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'name="first_name"' );
	} );

	it( 'renders last name field by default', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'name="last_name"' );
	} );

	it( 'does not render first name field when show_first_name is false', () => {
		const props = {
			...defaultProps,
			attributes: {
				...defaultProps.attributes,
				show_first_name: false,
			},
		};

		const html = renderToString( <Edit { ...props } /> );

		expect( html ).not.toContain( 'name="first_name"' );
	} );

	it( 'does not render last name field when show_last_name is false', () => {
		const props = {
			...defaultProps,
			attributes: {
				...defaultProps.attributes,
				show_last_name: false,
			},
		};

		const html = renderToString( <Edit { ...props } /> );

		expect( html ).not.toContain( 'name="last_name"' );
	} );

	it( 'renders consent checkbox with consent text', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'type="checkbox"' );
		expect( html ).toContain( 'name="consent"' );
		expect( html ).toContain( 'Test consent text' );
	} );

	it( 'renders honeypot field with website_check name', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'name="website_check"' );
	} );

	it( 'renders subscribe button', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'Subscribe' );
		expect( html ).toContain( '<button' );
	} );

	it( 'renders warning notice when no lists are selected', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'No list selected' );
	} );

	it( 'does not render warning notice when lists are selected', () => {
		const props = {
			...defaultProps,
			attributes: {
				...defaultProps.attributes,
				list_ids: [ 1 ],
			},
		};

		const html = renderToString( <Edit { ...props } /> );

		expect( html ).not.toContain( 'No list selected' );
	} );

	it( 'renders list checkboxes for available lists', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'Newsletter' );
		expect( html ).toContain( 'Promotions' );
	} );

	it( 'renders without crashing for multiple list selection', () => {
		const props = {
			...defaultProps,
			attributes: {
				...defaultProps.attributes,
				list_ids: [ 1, 2 ],
			},
		};

		const html = renderToString( <Edit { ...props } /> );

		expect( html ).toContain( 'stampy-signup-block' );
	} );

	it( 'renders the form element', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( '<form' );
		expect( html ).toContain( 'stampy-signup-form' );
	} );

	it( 'renders toggle controls for name fields in inspector', () => {
		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'Show First Name' );
		expect( html ).toContain( 'Show Last Name' );
	} );
} );
