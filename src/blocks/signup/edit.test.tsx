import { renderToString } from 'react-dom/server';
import React, { act } from 'react';
import { createRoot } from 'react-dom/client';

import Edit from './edit';

const mockLists: StampyList[] = [
	{ id: 1, name: 'Newsletter', slug: 'newsletter', description: '' },
	{ id: 2, name: 'Promotions', slug: 'promotions', description: '' },
];

const defaultProps = {
	attributes: {
		list_ids: [] as number[],
		enabled_fields: [] as string[],
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

	it( 'renders toggle controls for custom fields in inspector', () => {
		( window as unknown as { stampy: StampyGlobal } ).stampy = {
			...( window as unknown as { stampy: StampyGlobal } ).stampy,
			fields: [
				{
					key: 'first_name',
					label: 'First Name',
					type: 'text',
					options: null,
					required: false,
				},
				{
					key: 'company',
					label: 'Company',
					type: 'text',
					options: null,
					required: true,
				},
			],
		};

		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).toContain( 'First Name' );
		expect( html ).toContain( 'Company' );
	} );

	it( 'renders enabled custom field inputs in the form', () => {
		( window as unknown as { stampy: StampyGlobal } ).stampy = {
			...( window as unknown as { stampy: StampyGlobal } ).stampy,
			fields: [
				{
					key: 'company',
					label: 'Company',
					type: 'text',
					options: null,
					required: true,
				},
			],
		};

		const props = {
			...defaultProps,
			attributes: {
				...defaultProps.attributes,
				enabled_fields: [ 'company' ],
			},
		};

		const html = renderToString( <Edit { ...props } /> );

		expect( html ).toContain( 'name="company"' );
	} );

	it( 'does not render disabled custom field inputs', () => {
		( window as unknown as { stampy: StampyGlobal } ).stampy = {
			...( window as unknown as { stampy: StampyGlobal } ).stampy,
			fields: [
				{
					key: 'company',
					label: 'Company',
					type: 'text',
					options: null,
					required: false,
				},
			],
		};

		const html = renderToString( <Edit { ...defaultProps } /> );

		expect( html ).not.toContain( 'name="company"' );
	} );

	it( 'auto-selects required fields when enabled_fields is empty on mount', () => {
		( window as unknown as { stampy: StampyGlobal } ).stampy = {
			...( window as unknown as { stampy: StampyGlobal } ).stampy,
			fields: [
				{
					key: 'first_name',
					label: 'First Name',
					type: 'text',
					options: null,
					required: true,
				},
				{
					key: 'company',
					label: 'Company',
					type: 'text',
					options: null,
					required: false,
				},
			],
		};

		const setAttributes = jest.fn();
		const props = {
			...defaultProps,
			setAttributes,
		};

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		act( () => {
			root.render( <Edit { ...props } /> );
		} );

		expect( setAttributes ).toHaveBeenCalledWith( {
			enabled_fields: [ 'first_name' ],
		} );

		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	it( 'does not auto-select when enabled_fields is already populated', () => {
		( window as unknown as { stampy: StampyGlobal } ).stampy = {
			...( window as unknown as { stampy: StampyGlobal } ).stampy,
			fields: [
				{
					key: 'first_name',
					label: 'First Name',
					type: 'text',
					options: null,
					required: true,
				},
			],
		};

		const setAttributes = jest.fn();
		const props = {
			...defaultProps,
			attributes: {
				...defaultProps.attributes,
				enabled_fields: [ 'first_name' ],
			},
			setAttributes,
		};

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		act( () => {
			root.render( <Edit { ...props } /> );
		} );

		expect( setAttributes ).not.toHaveBeenCalled();

		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	it( 'excludes optional fields from auto-select', () => {
		( window as unknown as { stampy: StampyGlobal } ).stampy = {
			...( window as unknown as { stampy: StampyGlobal } ).stampy,
			fields: [
				{
					key: 'first_name',
					label: 'First Name',
					type: 'text',
					options: null,
					required: true,
				},
				{
					key: 'company',
					label: 'Company',
					type: 'text',
					options: null,
					required: false,
				},
				{
					key: 'phone',
					label: 'Phone',
					type: 'text',
					options: null,
					required: true,
				},
			],
		};

		const setAttributes = jest.fn();
		const props = {
			...defaultProps,
			setAttributes,
		};

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		act( () => {
			root.render( <Edit { ...props } /> );
		} );

		expect( setAttributes ).toHaveBeenCalledWith( {
			enabled_fields: [ 'first_name', 'phone' ],
		} );

		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );
} );
