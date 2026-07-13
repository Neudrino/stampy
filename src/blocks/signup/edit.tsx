import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	CheckboxControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';
import { useEffect } from '@wordpress/element';

interface SignupBlockAttributes {
	list_ids: number[];
	enabled_fields: string[];
	[ key: string ]: unknown;
}

type EditProps = BlockEditProps< SignupBlockAttributes >;

const availableLists: StampyList[] =
	( typeof window !== 'undefined' && window.stampy?.lists ) || [];

const consentText: string =
	( typeof window !== 'undefined' && window.stampy?.consentText ) ||
	__(
		'I agree to receive marketing emails from this website. I can unsubscribe at any time.',
		'stampy'
	);

const quizQuestions: { question: string; answer: string }[] =
	( typeof window !== 'undefined' && window.stampy?.quizQuestions ) || [];

function getCustomFields(): StampyField[] {
	return ( typeof window !== 'undefined' && window.stampy?.fields ) || [];
}

function renderFieldInput( field: StampyField ): JSX.Element {
	const id = `stampy-${ field.key }`;

	if ( field.type === 'textarea' ) {
		return (
			<textarea
				id={ id }
				name={ field.key }
				className="stampy-signup-input"
			/>
		);
	}

	if ( field.type === 'select' && field.options ) {
		return (
			<select
				id={ id }
				name={ field.key }
				className="stampy-signup-input"
			>
				<option value="">—</option>
				{ field.options.map( ( opt ) => (
					<option key={ opt } value={ opt }>
						{ opt }
					</option>
				) ) }
			</select>
		);
	}

	if ( field.type === 'checkbox' ) {
		return (
			<input
				type="checkbox"
				id={ id }
				name={ field.key }
				value="1"
				className="stampy-signup-input"
			/>
		);
	}

	const inputType =
		field.type === 'number' || field.type === 'date' ? field.type : 'text';

	return (
		<input
			type={ inputType }
			id={ id }
			name={ field.key }
			className="stampy-signup-input"
			required={ field.required }
		/>
	);
}

export default function Edit( {
	attributes,
	setAttributes,
	className,
}: EditProps ) {
	const listIds = attributes.list_ids;
	const enabledFields = attributes.enabled_fields || [];
	const customFields = getCustomFields();

	// Auto-select required fields and deselect optional fields when a new
	// block is created (enabled_fields is empty = default from block.json).
	useEffect( () => {
		if ( enabledFields.length === 0 && customFields.length > 0 ) {
			const requiredKeys = customFields
				.filter( ( f ) => f.required )
				.map( ( f ) => f.key );
			setAttributes( { enabled_fields: requiredKeys } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const toggleList = ( listId: number, checked: boolean ) => {
		if ( checked ) {
			setAttributes( {
				list_ids: [ ...listIds, listId ],
			} );
		} else {
			setAttributes( {
				list_ids: listIds.filter( ( id ) => id !== listId ),
			} );
		}
	};

	const toggleField = ( key: string, checked: boolean ) => {
		if ( checked ) {
			setAttributes( {
				enabled_fields: [ ...enabledFields, key ],
			} );
		} else {
			setAttributes( {
				enabled_fields: enabledFields.filter( ( k ) => k !== key ),
			} );
		}
	};

	const blockProps = useBlockProps( {
		className: [ 'stampy-signup-block', className ]
			.filter( Boolean )
			.join( ' ' ),
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Target Lists', 'stampy' ) }
					initialOpen={ true }
				>
					{ availableLists.length === 0 ? (
						<p>
							{ __(
								'No lists found. Create at least one list in the Stampy admin.',
								'stampy'
							) }
						</p>
					) : (
						availableLists.map( ( list ) => (
							<CheckboxControl
								key={ list.id }
								label={ list.name }
								checked={ listIds.includes( list.id ) }
								onChange={ ( checked ) =>
									toggleList( list.id, checked )
								}
							/>
						) )
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'Form Fields', 'stampy' ) }
					initialOpen={ true }
				>
					{ customFields.length === 0 ? (
						<p>
							{ __(
								'No fields defined. Create fields in the Stampy admin under Fields.',
								'stampy'
							) }
						</p>
					) : (
						customFields.map( ( field ) => (
							<ToggleControl
								key={ field.key }
								label={ field.label }
								checked={ enabledFields.includes( field.key ) }
								onChange={ ( value ) =>
									toggleField( field.key, value )
								}
							/>
						) )
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ listIds.length === 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'No list selected. The signup form will not be displayed on the front end until at least one list is selected.',
							'stampy'
						) }
					</Notice>
				) }

				<form className="stampy-signup-form">
					{ customFields
						.filter( ( f ) => enabledFields.includes( f.key ) )
						.map( ( field ) => (
							<p
								key={ field.key }
								className="stampy-signup-field"
							>
								<label htmlFor={ `stampy-${ field.key }` }>
									{ field.label }
									{ field.required && (
										<span
											aria-hidden="true"
											className="required"
										>
											{ ' *' }
										</span>
									) }
								</label>
								{ renderFieldInput( field ) }
							</p>
						) ) }
					<p className="stampy-signup-field">
						<label htmlFor="stampy-email">
							{ __( 'Email', 'stampy' ) }
							<span aria-hidden="true" className="required">
								{ ' *' }
							</span>
						</label>
						<input
							type="email"
							id="stampy-email"
							name="email"
							className="stampy-signup-input"
							required
							aria-required="true"
						/>
					</p>
					<p className="stampy-signup-field">
						<label htmlFor="stampy-consent">
							<input
								type="checkbox"
								id="stampy-consent"
								name="consent"
								required
								aria-required="true"
							/>
							{ consentText }
							<span aria-hidden="true" className="required">
								{ ' *' }
							</span>
						</label>
					</p>
					{ quizQuestions.length > 0 && (
						<p className="stampy-signup-field stampy-signup-quiz">
							<label htmlFor="stampy-quiz">
								{ quizQuestions[ 0 ].question }
								<span aria-hidden="true" className="required">
									{ ' *' }
								</span>
							</label>
							<input
								type="text"
								id="stampy-quiz"
								name="stampy_quiz_answer"
								className="stampy-signup-input"
								required
								aria-required="true"
							/>
						</p>
					) }
					<p
						className="stampy-signup-field stampy-signup-honeypot"
						aria-hidden="true"
					>
						<label htmlFor="stampy-website-check">
							{ __( 'Website', 'stampy' ) }
						</label>
						<input
							type="text"
							id="stampy-website-check"
							name="website_check"
							tabIndex={ -1 }
							autoComplete="off"
						/>
					</p>
					<button type="submit" className="stampy-signup-button">
						{ __( 'Subscribe', 'stampy' ) }
					</button>
				</form>
			</div>
		</>
	);
}
