import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';
import {
	PanelBody,
	TextControl,
	CheckboxControl,
	SelectControl,
	Notice,
	Button,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect, useRef } from '@wordpress/element';

interface ProgressData {
	total: number;
	queued: number;
	sending: number;
	sent: number;
	failed: number;
	status: string;
	stats?: {
		opens: number;
		clicks: number;
		total_clicks: number;
	};
	sent_count?: number;
}

function CampaignSidebar() {
	const availableLists: StampyList[] =
		( typeof window !== 'undefined' && window.stampy?.lists ) || [];
	const previewBase =
		( typeof window !== 'undefined' && window.stampy?.previewUrl ) || '';
	const ajaxUrl =
		( typeof window !== 'undefined' && window.stampy?.ajaxUrl ) ||
		'/wp-admin/admin-ajax.php';
	const startSendNonce =
		( typeof window !== 'undefined' && window.stampy?.startSendNonce ) ||
		'';
	const cancelSendNonce =
		( typeof window !== 'undefined' && window.stampy?.cancelSendNonce ) ||
		'';
	const progressNonce =
		( typeof window !== 'undefined' && window.stampy?.progressNonce ) || '';
	const previewNonce =
		( typeof window !== 'undefined' && window.stampy?.previewNonce ) || '';

	const postId = useSelect(
		( select ) =>
			select( 'core/editor' )?.getCurrentPostId() as number | undefined
	);
	const postType = useSelect(
		( select ) =>
			select( 'core/editor' )?.getCurrentPostType() as string | undefined
	);
	const subject = useSelect(
		( select ) =>
			(
				select( 'core/editor' )?.getEditedPostAttribute( 'meta' ) as
					| Record< string, string >
					| undefined
			 )?.stampy_campaign_subject || ''
	);
	const listIdsRaw = useSelect(
		( select ) =>
			(
				select( 'core/editor' )?.getEditedPostAttribute( 'meta' ) as
					| Record< string, string >
					| undefined
			 )?.stampy_campaign_list_ids || '[]'
	);
	const status = useSelect(
		( select ) =>
			(
				select( 'core/editor' )?.getEditedPostAttribute( 'meta' ) as
					| Record< string, string >
					| undefined
			 )?.stampy_campaign_status || 'draft'
	);
	const trackingOverride = useSelect(
		( select ) =>
			(
				select( 'core/editor' )?.getEditedPostAttribute( 'meta' ) as
					| Record< string, string >
					| undefined
			 )?.stampy_campaign_tracking || ''
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

	const [ progress, setProgress ] = useState< ProgressData | null >( null );
	const [ sendError, setSendError ] = useState< string >( '' );
	const [ isSending, setIsSending ] = useState< boolean >( false );
	const pollRef = useRef< ReturnType< typeof setInterval > | null >( null );

	const fetchProgress = async () => {
		if ( ! postId || ! progressNonce ) {
			return;
		}

		try {
			const url = `${ ajaxUrl }?action=stampy_campaign_progress&post_id=${ postId }&_wpnonce=${ encodeURIComponent(
				progressNonce
			) }`;
			const response = await fetch( url );
			const json = await response.json();

			if ( json.success ) {
				setProgress( json.data );
			}
		} catch {
			// Network error — keep last known progress.
		}
	};

	const startPolling = () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
		}
		pollRef.current = setInterval( fetchProgress, 3000 );
	};

	const stopPolling = () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
	};

	useEffect( () => {
		if ( status === 'sending' ) {
			fetchProgress();
			startPolling();
		} else if ( status === 'sent' ) {
			fetchProgress();
		}

		return () => stopPolling();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status ] );

	const handleStartSend = async () => {
		if ( ! postId ) {
			return;
		}

		setIsSending( true );
		setSendError( '' );

		try {
			const formData = new FormData();
			formData.append( 'action', 'stampy_start_send' );
			formData.append( 'post_id', String( postId ) );
			formData.append( '_wpnonce', startSendNonce );

			const response = await fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
			} );
			const json = await response.json();

			if ( json.success ) {
				editPost( {
					meta: { stampy_campaign_status: 'sending' },
				} );
				fetchProgress();
				startPolling();
			} else {
				setSendError( json.data?.message || 'Send failed to start.' );
			}
		} catch {
			setSendError( 'Network error.' );
		} finally {
			setIsSending( false );
		}
	};

	const handleCancelSend = async () => {
		if ( ! postId ) {
			return;
		}

		setIsSending( true );
		setSendError( '' );

		try {
			const formData = new FormData();
			formData.append( 'action', 'stampy_cancel_send' );
			formData.append( 'post_id', String( postId ) );
			formData.append( '_wpnonce', cancelSendNonce );

			const response = await fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
			} );
			const json = await response.json();

			if ( json.success ) {
				editPost( {
					meta: { stampy_campaign_status: 'cancelled' },
				} );
				stopPolling();
			} else {
				setSendError( json.data?.message || 'Cancel failed.' );
			}
		} catch {
			setSendError( 'Network error.' );
		} finally {
			setIsSending( false );
		}
	};

	if ( postType !== 'stampy_campaign' ) {
		return null;
	}

	const previewUrl =
		previewBase && postId
			? `${ previewBase }&post_id=${ postId }&_wpnonce=${ previewNonce }`
			: '';
	const previewTextUrl =
		previewBase && postId
			? `${ previewBase }&post_id=${ postId }&format=text&_wpnonce=${ previewNonce }`
			: '';

	const displayProgress = progress || {
		total: 0,
		queued: 0,
		sending: 0,
		sent: 0,
		failed: 0,
		status,
	};

	const percentage =
		displayProgress.total > 0
			? Math.round(
					( ( displayProgress.sent + displayProgress.failed ) /
						displayProgress.total ) *
						100
			  )
			: 0;

	const remaining = displayProgress.queued + displayProgress.sending;

	const stats = progress?.stats;
	const sentCount = progress?.sent_count ?? displayProgress.sent;
	const openRate =
		stats && sentCount > 0
			? Math.round( ( stats.opens / sentCount ) * 100 )
			: 0;

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

			<PanelBody
				title={ __( 'Open & Click Tracking', 'stampy' ) }
				initialOpen={ true }
			>
				<SelectControl
					label={ __( 'Tracking for this campaign', 'stampy' ) }
					value={ trackingOverride }
					options={ [
						{
							label: __( 'Use global setting', 'stampy' ),
							value: '',
						},
						{
							label: __( 'Enable tracking', 'stampy' ),
							value: 'on',
						},
						{
							label: __( 'Disable tracking', 'stampy' ),
							value: 'off',
						},
					] }
					onChange={ ( value: string ) =>
						editPost( {
							meta: {
								stampy_campaign_tracking: value,
							},
						} )
					}
					help={ __(
						'Overrides the global tracking setting for this campaign only.',
						'stampy'
					) }
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'Send & Progress', 'stampy' ) }
				initialOpen={ true }
			>
				<p style={ { textTransform: 'capitalize' } }>
					<strong>{ __( 'Status:', 'stampy' ) }</strong> { status }
				</p>

				{ sendError && (
					<Notice status="error" isDismissible={ false }>
						{ sendError }
					</Notice>
				) }

				{ ( status === 'sending' || status === 'sent' ) && (
					<>
						<p>
							<strong>{ __( 'Progress:', 'stampy' ) }</strong>
						</p>
						<ul>
							<li>
								{ sprintf(
									/* translators: %d: count */
									__( 'Total recipients: %d', 'stampy' ),
									displayProgress.total
								) }
							</li>
							<li>
								{ sprintf(
									/* translators: %d: count */
									__( 'Sent: %d', 'stampy' ),
									displayProgress.sent
								) }
							</li>
							<li>
								{ sprintf(
									/* translators: %d: count */
									__( 'Failed: %d', 'stampy' ),
									displayProgress.failed
								) }
							</li>
							<li>
								{ sprintf(
									/* translators: %d: count */
									__( 'Remaining: %d', 'stampy' ),
									remaining
								) }
							</li>
						</ul>

						{ displayProgress.total > 0 && (
							<>
								<div
									style={ {
										background: '#f0f0f1',
										borderRadius: '4px',
										height: '20px',
										margin: '8px 0',
									} }
								>
									<div
										style={ {
											background: '#2271b1',
											height: '20px',
											borderRadius: '4px',
											width: `${ percentage }%`,
											transition: 'width 0.3s ease',
										} }
									/>
								</div>
								<p>{ percentage }%</p>
							</>
						) }
					</>
				) }

				{ status === 'sent' && stats && (
					<>
						<p>
							<strong>{ __( 'Tracking:', 'stampy' ) }</strong>
						</p>
						<ul>
							<li>
								{ sprintf(
									/* translators: %d: count */
									__( 'Opens: %d', 'stampy' ),
									stats.opens
								) }
							</li>
							{ sentCount > 0 && (
								<li>
									{ sprintf(
										/* translators: %d: percentage */
										__( 'Open rate: %d%%', 'stampy' ),
										openRate
									) }
								</li>
							) }
							<li>
								{ sprintf(
									/* translators: %d: count */
									__( 'Unique clicks: %d', 'stampy' ),
									stats.clicks
								) }
							</li>
							<li>
								{ sprintf(
									/* translators: %d: count */
									__( 'Total clicks: %d', 'stampy' ),
									stats.total_clicks
								) }
							</li>
						</ul>
					</>
				) }

				{ isSending && <Spinner /> }

				{ status === 'draft' && (
					<Button
						variant="primary"
						onClick={ handleStartSend }
						disabled={ isSending }
					>
						{ __( 'Send Campaign', 'stampy' ) }
					</Button>
				) }
				{ status === 'sending' && (
					<Button
						variant="secondary"
						isDestructive
						onClick={ handleCancelSend }
						disabled={ isSending }
					>
						{ __( 'Cancel Send', 'stampy' ) }
					</Button>
				) }
			</PanelBody>

			<PanelBody title={ __( 'Preview', 'stampy' ) } initialOpen={ true }>
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
