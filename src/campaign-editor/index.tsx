import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';
import {
	PanelBody,
	TextControl,
	CheckboxControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';

interface StampyList {
	id: number;
	name: string;
	slug: string;
	description: string;
}

declare global {
	interface Window {
		stampy?: {
			restUrl: string;
			restNonce: string;
			lists: StampyList[];
			consentText: string;
			previewUrl: string;
		};
	}
}

function CampaignSidebar() {
	const availableLists: StampyList[] =
		( typeof window !== 'undefined' && window.stampy?.lists ) || [];
	const previewBase =
		( typeof window !== 'undefined' && window.stampy?.previewUrl ) || '';

	const postId = useSelect(
		( select ) => select( 'core/editor' )?.getCurrentPostId()
	);
	const postType = useSelect(
		( select ) => select( 'core/editor' )?.getCurrentPostType()
	);
	const subject = useSelect(
		( select ) =>
			select( 'core/editor' )?.getEditedPostAttribute( 'meta' )
				?.stampy_campaign_subject || ''
	);
	const listIdsRaw = useSelect(
		( select ) =>
			select( 'core/editor' )?.getEditedPostAttribute( 'meta' )
				?.stampy_campaign_list_ids || '[]'
	);
	const status = useSelect(
		( select ) =>
			select( 'core/editor' )?.getEditedPostAttribute( 'meta' )
				?.stampy_campaign_status || 'draft'
	);

	const { editPost } = useDispatch( 'core/editor' );

	const listIds: number[] = ( () => {
		try {
			const parsed = JSON.parse( listIdsRaw );
			return Array.isArray( parsed ) ? parsed.map( Number ) : [];
		} catch {
			return [];
		}
	} )();

	const toggleList = ( listId: number, checked: boolean ) => {
		const next = checked
			? [ ...listIds, listId ]
			: listIds.filter( ( id ) => id !== listId );
		editPost( {
			meta: {
				stampy_campaign_list_ids: JSON.stringify( next ),
			},
		} );
	};

	const updateSubject = ( value: string ) => {
		editPost( {
			meta: {
				stampy_campaign_subject: value,
			},
		} );
	};

	if ( postType !== 'stampy_campaign' ) {
		return null;
	}

	const previewUrl = previewBase
		? `${ previewBase }&post_id=${ postId }`
		: '';
	const previewTextUrl = previewBase
		? `${ previewBase }&post_id=${ postId }&format=text`
		: '';

	return (
		<PluginSidebar
			name="stampy-campaign"
			title={ __( 'Campaign Settings', 'stampy' ) }
			icon="email-alt"
		>
			<PanelBody title={ __( 'Subject', 'stampy' ) } initialOpen={ true }>
				<TextControl
					value={ subject }
					onChange={ updateSubject }
					placeholder={ __( 'Enter email subject…', 'stampy' ) }
					help={ __(
						'The subject line for this campaign.',
						'stampy'
					) }
				/>
			</PanelBody>

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
				{ listIds.length === 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'No list selected. Select at least one target list before sending.',
							'stampy'
						) }
					</Notice>
				) }
			</PanelBody>

			<PanelBody title={ __( 'Status', 'stampy' ) } initialOpen={ true }>
				<p style={ { textTransform: 'capitalize' } }>
					<strong>{ __( 'Current status:', 'stampy' ) }</strong>{ ' ' }
					{ status }
				</p>
			</PanelBody>

			<PanelBody
				title={ __( 'Preview', 'stampy' ) }
				initialOpen={ false }
			>
				{ previewUrl ? (
					<>
						<p>
							<a
								href={ previewUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Preview HTML email', 'stampy' ) }
							</a>
						</p>
						<p>
							<a
								href={ previewTextUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Preview plain-text email', 'stampy' ) }
							</a>
						</p>
					</>
				) : (
					<p>
						{ __(
							'Save the campaign to enable preview.',
							'stampy'
						) }
					</p>
				) }
			</PanelBody>
		</PluginSidebar>
	);
}

registerPlugin( 'stampy-campaign-editor', {
	render: CampaignSidebar,
} );
