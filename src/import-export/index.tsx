import { useState, useRef, useCallback } from 'react';
import { createRoot } from 'react-dom/client';
import Papa from 'papaparse';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	SelectControl,
	TextControl,
	Notice,
	Spinner,
} from '@wordpress/components';

interface ParsedRow {
	[ key: string ]: string;
}

interface ImportResult {
	success: boolean;
	list_id: number;
	imported: number;
	skipped: number;
	errors: string[];
	message?: string;
}

function ImportExportApp() {
	const availableLists: StampyList[] =
		( typeof window !== 'undefined' && window.stampy?.lists ) || [];
	const restNonce =
		( typeof window !== 'undefined' && window.stampy?.restNonce ) || '';

	const [ activeTab, setActiveTab ] = useState< 'export' | 'import' >(
		'export'
	);

	// Export state.
	const [ exportFormat, setExportFormat ] = useState< 'csv' | 'json' >(
		'csv'
	);
	const [ exportListId, setExportListId ] = useState< string >( '' );
	const [ exporting, setExporting ] = useState( false );
	const [ exportError, setExportError ] = useState< string >( '' );

	// Import state.
	const [ fileName, setFileName ] = useState( '' );
	const [ parsedRows, setParsedRows ] = useState< ParsedRow[] >( [] );
	const [ parseError, setParseError ] = useState( '' );
	const [ delimiter, setDelimiter ] = useState< string >( 'auto' );
	const [ customDelimiter, setCustomDelimiter ] = useState( '' );
	const [ listName, setListName ] = useState( '' );
	const [ importing, setImporting ] = useState( false );
	const [ importResult, setImportResult ] = useState< ImportResult | null >(
		null
	);
	const fileInputRef = useRef< HTMLInputElement >( null );

	const handleFile = useCallback(
		( file: File ) => {
			setImportResult( null );
			setParseError( '' );
			setParsedRows( [] );
			setFileName( file.name );

			const isJson = file.name.toLowerCase().endsWith( '.json' );
			const isCsv = file.name.toLowerCase().endsWith( '.csv' );

			if ( ! isJson && ! isCsv ) {
				setParseError(
					__(
						'Invalid file type. Please upload a .csv or .json file.',
						'stampy'
					)
				);
				return;
			}

			if ( isJson ) {
				const reader = new FileReader();
				reader.onload = ( e ) => {
					try {
						const data = JSON.parse(
							e.target?.result as string
						) as ParsedRow[];
						if ( ! Array.isArray( data ) ) {
							setParseError(
								__(
									'JSON file must contain an array of subscriber objects.',
									'stampy'
								)
							);
							return;
						}
						setParsedRows( data );
					} catch ( err ) {
						setParseError(
							sprintf(
								/* translators: %s: error message */
								__( 'JSON parse error: %s', 'stampy' ),
								( err as Error ).message
							)
						);
					}
				};
				reader.readAsText( file );
			} else {
				const reader = new FileReader();
				reader.onload = ( e ) => {
					const text = e.target?.result as string;
					let delim: string | undefined;
					if ( delimiter === 'auto' ) {
						delim = undefined;
					} else if ( delimiter === 'custom' ) {
						delim = customDelimiter || ',';
					} else {
						delim = delimiter;
					}
					const result = Papa.parse< ParsedRow >( text, {
						header: true,
						skipEmptyLines: true,
						delimiter: delim,
					} );

					if ( result.errors.length > 0 ) {
						setParseError(
							sprintf(
								/* translators: %s: error message */
								__( 'CSV parse error: %s', 'stampy' ),
								result.errors[ 0 ].message
							)
						);
						return;
					}

					if ( ! result.data || result.data.length === 0 ) {
						setParseError(
							__(
								'No data rows found in the CSV file.',
								'stampy'
							)
						);
						return;
					}

					setParsedRows( result.data );
				};
				reader.readAsText( file );
			}
		},
		[ delimiter, customDelimiter ]
	);

	const onFileChange = ( e: React.ChangeEvent< HTMLInputElement > ) => {
		const file = e.target.files?.[ 0 ];
		if ( file ) {
			handleFile( file );
		}
	};

	const reparse = () => {
		if ( ! fileInputRef.current?.files?.[ 0 ] ) {
			return;
		}
		handleFile( fileInputRef.current.files[ 0 ] );
	};

	const handleExport = async () => {
		setExporting( true );
		setExportError( '' );
		try {
			const params = new URLSearchParams();
			params.set( 'format', exportFormat );
			if ( exportListId ) {
				params.set( 'list_id', exportListId );
			}

			const response = await fetch(
				`/wp-json/stampy/v1/export?${ params.toString() }`,
				{
					headers: {
						'X-WP-Nonce': restNonce,
					},
				}
			);

			if ( ! response.ok ) {
				throw new Error(
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Export failed (HTTP %d)', 'stampy' ),
						response.status
					)
				);
			}

			const result = await response.json();

			let blob: Blob;
			let mimeType: string;
			let extension: string;

			if ( exportFormat === 'json' ) {
				mimeType = 'application/json';
				extension = 'json';
				blob = new Blob( [ JSON.stringify( result.data, null, 2 ) ], {
					type: mimeType,
				} );
			} else {
				mimeType = 'text/csv';
				extension = 'csv';
				blob = new Blob( [ result.data ], { type: mimeType } );
			}

			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = `stampy-subscribers-${ new Date()
				.toISOString()
				.slice( 0, 10 ) }.${ extension }`;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( url );
		} catch ( err ) {
			setExportError( ( err as Error ).message );
		} finally {
			setExporting( false );
		}
	};

	const handleImport = async () => {
		if ( parsedRows.length === 0 ) {
			return;
		}
		if ( ! listName.trim() ) {
			setParseError( __( 'Please enter a list name.', 'stampy' ) );
			return;
		}

		setImporting( true );
		setImportResult( null );
		try {
			const response = await fetch( '/wp-json/stampy/v1/import', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce,
				},
				body: JSON.stringify( {
					rows: parsedRows,
					list_name: listName.trim(),
				} ),
			} );

			if ( ! response.ok ) {
				throw new Error(
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Import failed (HTTP %d)', 'stampy' ),
						response.status
					)
				);
			}

			const result: ImportResult = await response.json();
			setImportResult( result );
		} catch ( err ) {
			setParseError( ( err as Error ).message );
		} finally {
			setImporting( false );
		}
	};

	const previewRows = parsedRows.slice( 0, 10 );
	const previewColumns =
		parsedRows.length > 0 ? Object.keys( parsedRows[ 0 ] ) : [];

	return (
		<div className="stampy-import-export">
			<div
				style={ {
					display: 'flex',
					gap: '8px',
					marginBottom: '20px',
				} }
			>
				<Button
					variant={ activeTab === 'export' ? 'primary' : 'secondary' }
					onClick={ () => setActiveTab( 'export' ) }
				>
					{ __( 'Export', 'stampy' ) }
				</Button>
				<Button
					variant={ activeTab === 'import' ? 'primary' : 'secondary' }
					onClick={ () => setActiveTab( 'import' ) }
				>
					{ __( 'Import', 'stampy' ) }
				</Button>
			</div>

			{ activeTab === 'export' && (
				<div className="stampy-export-panel">
					<h2>{ __( 'Export Subscribers', 'stampy' ) }</h2>
					<p>
						{ __(
							'Export all subscriber data (properties + custom fields) to CSV or JSON.',
							'stampy'
						) }
					</p>

					{ exportError && (
						<Notice
							status="error"
							onRemove={ () => setExportError( '' ) }
						>
							{ exportError }
						</Notice>
					) }

					<div
						style={ {
							maxWidth: '400px',
							display: 'flex',
							flexDirection: 'column',
							gap: '16px',
							marginTop: '16px',
						} }
					>
						<SelectControl
							label={ __( 'Format', 'stampy' ) }
							value={ exportFormat }
							options={
								[
									{
										label: __( 'CSV', 'stampy' ),
										value: 'csv',
									},
									{
										label: __( 'JSON', 'stampy' ),
										value: 'json',
									},
								] as { label: string; value: string }[]
							}
							onChange={ ( val ) =>
								setExportFormat( val as 'csv' | 'json' )
							}
						/>

						<SelectControl
							label={ __( 'Source List', 'stampy' ) }
							value={ exportListId }
							options={
								[
									{
										label: __(
											'All subscribers',
											'stampy'
										),
										value: '',
									},
									...availableLists.map( ( l ) => ( {
										label: l.name,
										value: String( l.id ),
									} ) ),
								] as { label: string; value: string }[]
							}
							onChange={ setExportListId }
						/>

						<Button
							variant="primary"
							onClick={ handleExport }
							disabled={ exporting }
						>
							{ exporting ? (
								<>
									<Spinner />
									{ __( 'Exporting…', 'stampy' ) }
								</>
							) : (
								__( 'Download Export', 'stampy' )
							) }
						</Button>
					</div>
				</div>
			) }

			{ activeTab === 'import' && (
				<div className="stampy-import-panel">
					<h2>{ __( 'Import Subscribers', 'stampy' ) }</h2>
					<p>
						{ __(
							'Upload a CSV or JSON file to import subscribers into a new list.',
							'stampy'
						) }
					</p>

					{ parseError && (
						<Notice
							status="error"
							onRemove={ () => setParseError( '' ) }
						>
							{ parseError }
						</Notice>
					) }

					{ importResult && (
						<Notice
							status={
								importResult.success ? 'success' : 'error'
							}
							onRemove={ () => setImportResult( null ) }
						>
							{ sprintf(
								/* translators: %1$d: imported count, %2$d: skipped count */
								__(
									'Imported %1$d subscribers, skipped %2$d.',
									'stampy'
								),
								importResult.imported,
								importResult.skipped
							) }
							{ importResult.errors.length > 0 && (
								<ul
									style={ {
										marginTop: '8px',
										maxHeight: '200px',
										overflowY: 'auto',
									} }
								>
									{ importResult.errors
										.slice( 0, 20 )
										.map( ( err, i ) => (
											<li key={ i }>{ err }</li>
										) ) }
								</ul>
							) }
						</Notice>
					) }

					<div
						style={ {
							maxWidth: '500px',
							display: 'flex',
							flexDirection: 'column',
							gap: '16px',
							marginTop: '16px',
						} }
					>
						<input
							ref={ fileInputRef }
							type="file"
							accept=".csv,.json"
							onChange={ onFileChange }
							style={ { padding: '8px 0' } }
						/>

						{ fileName.toLowerCase().endsWith( '.csv' ) && (
							<>
								<SelectControl
									label={ __(
										'Delimiter (auto-detected by default)',
										'stampy'
									) }
									value={ delimiter }
									options={
										[
											{
												label: __(
													'Auto-detect',
													'stampy'
												),
												value: 'auto',
											},
											{
												label: __(
													'Comma (,)',
													'stampy'
												),
												value: ',',
											},
											{
												label: __(
													'Semicolon (;)',
													'stampy'
												),
												value: ';',
											},
											{
												label: __( 'Tab', 'stampy' ),
												value: '\t',
											},
											{
												label: __(
													'Pipe (|)',
													'stampy'
												),
												value: '|',
											},
											{
												label: __( 'Custom', 'stampy' ),
												value: 'custom',
											},
										] as { label: string; value: string }[]
									}
									onChange={ ( val ) => {
										setDelimiter( val );
									} }
								/>
								{ delimiter === 'custom' && (
									<TextControl
										label={ __(
											'Custom delimiter',
											'stampy'
										) }
										value={ customDelimiter }
										onChange={ setCustomDelimiter }
									/>
								) }
								{ delimiter !== 'auto' && (
									<Button
										variant="secondary"
										onClick={ reparse }
									>
										{ __(
											'Re-parse with new delimiter',
											'stampy'
										) }
									</Button>
								) }
							</>
						) }

						{ parsedRows.length > 0 && (
							<>
								<TextControl
									label={ __( 'New list name', 'stampy' ) }
									value={ listName }
									onChange={ setListName }
									placeholder={ __(
										'e.g. Imported from Mailchimp',
										'stampy'
									) }
								/>
								<p>
									{ sprintf(
										/* translators: %d: row count */
										__(
											'%d rows parsed. Preview of first 10 rows:',
											'stampy'
										),
										parsedRows.length
									) }
								</p>
								<table
									className="widefat striped"
									style={ { maxWidth: '100%' } }
								>
									<thead>
										<tr>
											{ previewColumns.map( ( col ) => (
												<th key={ col }>{ col }</th>
											) ) }
										</tr>
									</thead>
									<tbody>
										{ previewRows.map( ( row, i ) => (
											<tr key={ i }>
												{ previewColumns.map(
													( col ) => (
														<td key={ col }>
															{ row[ col ] || '' }
														</td>
													)
												) }
											</tr>
										) ) }
									</tbody>
								</table>
								<Button
									variant="primary"
									onClick={ handleImport }
									disabled={ importing || ! listName.trim() }
								>
									{ importing ? (
										<>
											<Spinner />
											{ __( 'Importing…', 'stampy' ) }
										</>
									) : (
										sprintf(
											/* translators: %d: row count */
											__(
												'Import %d subscribers',
												'stampy'
											),
											parsedRows.length
										)
									) }
								</Button>
							</>
						) }
					</div>
				</div>
			) }
		</div>
	);
}

const container = document.getElementById( 'stampy-import-export-app' );
if ( container ) {
	createRoot( container ).render( <ImportExportApp /> );
}
