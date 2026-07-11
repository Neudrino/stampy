import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

interface SignupResponse {
	success: boolean;
	message: string;
	errors?: Record< string, string >;
}

function initSignupForms(): void {
	const forms = document.querySelectorAll< HTMLFormElement >(
		'.stampy-signup-form'
	);
	if ( forms.length === 0 ) {
		return;
	}

	const restNonce =
		( typeof window !== 'undefined' && window.stampy?.restNonce ) || '';

	if ( restNonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( restNonce ) );
	}

	forms.forEach( ( form ) => {
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();

			const formData = new FormData( form );
			const email = ( formData.get( 'email' ) || '' ).toString().trim();
			const consent = formData.get( 'consent' );
			const websiteCheck = (
				formData.get( 'website_check' ) || ''
			).toString();
			const firstName = ( formData.get( 'first_name' ) || '' )
				.toString()
				.trim();
			const lastName = ( formData.get( 'last_name' ) || '' )
				.toString()
				.trim();
			const listIdsRaw = form.getAttribute( 'data-list-ids' ) || '[]';
			const listIds: number[] = JSON.parse( listIdsRaw );

			const fields: Record< string, string > = {};
			if ( firstName ) {
				fields.first_name = firstName;
			}
			if ( lastName ) {
				fields.last_name = lastName;
			}

			clearErrors( form );

			try {
				const response = ( await apiFetch( {
					path: '/stampy/v1/signup',
					method: 'POST',
					data: {
						email,
						fields,
						consent: consent !== null,
						list_ids: listIds,
						website_check: websiteCheck,
					},
				} ) ) as SignupResponse;

				if ( response.success ) {
					showSuccess( form, response.message );
				} else {
					if ( response.errors ) {
						showFieldErrors( form, response.errors );
					}
					showError( form, response.message );
				}
			} catch {
				showError(
					form,
					__(
						'Something went wrong. Please try again later.',
						'stampy'
					)
				);
			}
		} );
	} );
}

function clearErrors( form: HTMLFormElement ): void {
	form.querySelectorAll( '.stampy-signup-error' ).forEach( ( el ) => {
		el.remove();
	} );
	form.querySelectorAll( '[aria-invalid="true"]' ).forEach( ( el ) => {
		el.setAttribute( 'aria-invalid', 'false' );
	} );
}

function showSuccess( form: HTMLFormElement, message: string ): void {
	const notice = document.createElement( 'p' );
	notice.className = 'stampy-signup-notice stampy-signup-success';
	notice.setAttribute( 'role', 'status' );
	notice.textContent = message;

	form.innerHTML = '';
	form.appendChild( notice );
}

function showError( form: HTMLFormElement, message: string ): void {
	const existing = form.querySelector( '.stampy-signup-error-general' );
	if ( existing ) {
		existing.remove();
	}

	const error = document.createElement( 'p' );
	error.className = 'stampy-signup-error stampy-signup-error-general';
	error.setAttribute( 'role', 'alert' );
	error.textContent = message;

	form.insertBefore( error, form.firstChild );
}

function showFieldErrors(
	form: HTMLFormElement,
	errors: Record< string, string >
): void {
	for ( const [ field, message ] of Object.entries( errors ) ) {
		const input = form.querySelector< HTMLInputElement >(
			`[name="${ field }"]`
		);
		if ( ! input ) {
			continue;
		}

		input.setAttribute( 'aria-invalid', 'true' );

		const errorEl = document.createElement( 'span' );
		errorEl.className = 'stampy-signup-error stampy-signup-field-error';
		errorEl.setAttribute( 'role', 'alert' );
		errorEl.id = `stampy-error-${ field }`;
		errorEl.textContent = message;

		input.setAttribute( 'aria-describedby', errorEl.id );
		input.parentNode?.appendChild( errorEl );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initSignupForms );
} else {
	initSignupForms();
}
