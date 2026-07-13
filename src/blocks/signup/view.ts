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
			const quizAnswer = ( formData.get( 'stampy_quiz_answer' ) || '' )
				.toString()
				.trim();
			const quizIndex = formData.get( 'stampy_quiz_index' );
			const listIdsRaw = form.getAttribute( 'data-list-ids' ) || '[]';
			const listIds: number[] = JSON.parse( listIdsRaw );

			const fields: Record< string, string > = {};

			// Collect all custom field values from the form.
			const customFieldInputs = form.querySelectorAll<
				HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
			>( '[data-stampy-field]' );
			customFieldInputs.forEach( ( input ) => {
				const key = input.getAttribute( 'data-stampy-field' );
				if ( ! key ) {
					return;
				}
				let value: string;
				if ( input instanceof HTMLInputElement ) {
					if ( input.type === 'checkbox' ) {
						if ( ! input.checked ) {
							return;
						}
						value = input.value;
					} else {
						value = input.value.trim();
					}
				} else {
					value = input.value.trim();
				}
				if ( value ) {
					fields[ key ] = value;
				}
			} );

			clearErrors( form );

			try {
				const data: Record< string, unknown > = {
					email,
					fields,
					consent: consent !== null,
					list_ids: listIds,
					website_check: websiteCheck,
				};

				if ( quizAnswer ) {
					data.stampy_quiz_answer = quizAnswer;
					data.stampy_quiz_index = quizIndex
						? parseInt( quizIndex.toString(), 10 )
						: -1;
				}

				const turnstileToken = (
					form.querySelector(
						'[name="cf-turnstile-response"]'
					) as HTMLInputElement | null
				 )?.value;
				if ( turnstileToken ) {
					data.stampy_turnstile_token = turnstileToken;
				}

				const fcSolution = (
					form.querySelector(
						'[name="frc-captcha-response"]'
					) as HTMLInputElement | null
				 )?.value;
				if ( fcSolution ) {
					data.stampy_friendly_captcha_solution = fcSolution;
				}

				const response = ( await apiFetch( {
					path: '/stampy/v1/signup',
					method: 'POST',
					data,
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
