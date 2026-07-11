import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	CheckboxControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';

interface SignupBlockAttributes {
	list_ids: number[];
	show_first_name: boolean;
	show_last_name: boolean;
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

export default function Edit( {
	attributes,
	setAttributes,
	className,
}: EditProps ) {
	const listIds = attributes.list_ids;
	const showFirstName = attributes.show_first_name;
	const showLastName = attributes.show_last_name;

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
					<ToggleControl
						label={ __( 'Show First Name', 'stampy' ) }
						checked={ showFirstName }
						onChange={ ( value ) =>
							setAttributes( { show_first_name: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show Last Name', 'stampy' ) }
						checked={ showLastName }
						onChange={ ( value ) =>
							setAttributes( { show_last_name: value } )
						}
					/>
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
					{ showFirstName && (
						<p className="stampy-signup-field">
							<label htmlFor="stampy-first-name">
								{ __( 'First Name', 'stampy' ) }
							</label>
							<input
								type="text"
								id="stampy-first-name"
								name="first_name"
							/>
						</p>
					) }
					{ showLastName && (
						<p className="stampy-signup-field">
							<label htmlFor="stampy-last-name">
								{ __( 'Last Name', 'stampy' ) }
							</label>
							<input
								type="text"
								id="stampy-last-name"
								name="last_name"
							/>
						</p>
					) }
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
					<p className="stampy-signup-field">
						<input
							type="text"
							name="website_check"
							style={ {
								position: 'absolute',
								left: '-9999px',
								width: 1,
								height: 1,
								overflow: 'hidden',
							} }
							aria-hidden="true"
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
