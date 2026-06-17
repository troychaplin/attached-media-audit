import { useState, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import ScanToolbar from './components/ScanToolbar';
import ThumbnailCell from './components/ThumbnailCell';
import TitleCell from './components/TitleCell';
import UsedInCell from './components/UsedInCell';
import useMediaAudit from './hooks/useMediaAudit';
import useScanProgress from './hooks/useScanProgress';
import './styles.scss';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'date', direction: 'desc' },
	fields: [ 'thumbnail', 'title', 'media_type', 'usage', 'file_size', 'alt_text', 'date' ],
};

function formatFileSize( bytes ) {
	if ( ! bytes ) return '—';
	if ( bytes < 1024 ) return bytes + ' B';
	if ( bytes < 1024 * 1024 ) return Math.round( bytes / 1024 ) + ' KB';
	return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
}

export default function App() {
	const [ view, setView ]               = useState( DEFAULT_VIEW );
	const [ scanVersion, setScanVersion ] = useState( 0 );
	const [ indexBuilt, setIndexBuilt ]   = useState( () => window.wpMediaAudit?.indexBuilt ?? false );

	const { items, totalItems, isLoading } = useMediaAudit( view, scanVersion );
	const { status, progress, total, startScan, resetToIdle } = useScanProgress( {
		onComplete: () => { setIndexBuilt( true ); setScanVersion( ( v ) => v + 1 ); },
	} );

	const handleClear = useCallback( async () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm(
			__( 'Clear the media index? All scan data will be removed. Run a new scan to rebuild it.', 'attached-media-audit' )
		) ) return;

		const { ajaxUrl, nonce } = window.wpMediaAudit;
		const body = new FormData();
		body.append( 'action', 'media_audit_clear_index' );
		body.append( 'nonce', nonce );

		await fetch( ajaxUrl, { method: 'POST', body } ).catch( () => {} );

		setIndexBuilt( false );
		resetToIdle();
		setScanVersion( ( v ) => v + 1 );
	}, [ resetToIdle ] );

	const handleDeleteItems = useCallback( async ( selectedItems ) => {
		const count = selectedItems.length;
		const confirmMsg =
			count === 1
				? sprintf(
						/* translators: %s: file name */
						__( 'Delete "%s"? This cannot be undone.', 'attached-media-audit' ),
						selectedItems[ 0 ].title
				  )
				: sprintf(
						/* translators: %d: number of files */
						__( 'Delete %d files? This cannot be undone.', 'attached-media-audit' ),
						count
				  );

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( confirmMsg ) ) return;

		await Promise.all(
			selectedItems.map( ( item ) =>
				apiFetch( {
					path: `/wp/v2/media/${ item.id }?force=true`,
					method: 'DELETE',
				} )
			)
		);

		setScanVersion( ( v ) => v + 1 );
	}, [] );

	const handleDeleteSingle = useCallback( async ( item ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm(
			sprintf(
				/* translators: %s: file name */
				__( 'Delete "%s"? This cannot be undone.', 'attached-media-audit' ),
				item.title
			)
		) ) return;

		await apiFetch( {
			path: `/wp/v2/media/${ item.id }?force=true`,
			method: 'DELETE',
		} );

		setScanVersion( ( v ) => v + 1 );
	}, [] );

	const fields = useMemo(
		() => [
			{
				id: 'thumbnail',
				label: __( 'Preview', 'attached-media-audit' ),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				render: ( { item } ) => <ThumbnailCell item={ item } />,
			},
			{
				id: 'title',
				label: __( 'File Name', 'attached-media-audit' ),
				enableSorting: true,
				enableHiding: false,
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<TitleCell item={ item } onDelete={ handleDeleteSingle } />
				),
			},
			{
				id: 'media_type',
				label: __( 'Type', 'attached-media-audit' ),
				enableSorting: false,
				elements: [
					{ value: 'Image', label: __( 'Image', 'attached-media-audit' ) },
					{ value: 'Video', label: __( 'Video', 'attached-media-audit' ) },
					{ value: 'Audio', label: __( 'Audio', 'attached-media-audit' ) },
					{ value: 'Document', label: __( 'Document', 'attached-media-audit' ) },
				],
				filterBy: { isPrimary: true, operators: [ 'is' ] },
			},
			{
				id: 'reference_type',
				label: __( 'Location', 'attached-media-audit' ),
				enableSorting: false,
				elements: [
					{ value: 'block', label: __( 'Block', 'attached-media-audit' ) },
					{ value: 'featured_image', label: __( 'Featured Image', 'attached-media-audit' ) },
					{ value: 'classic', label: __( 'Classic Editor', 'attached-media-audit' ) },
					{ value: 'postmeta', label: __( 'Post Meta', 'attached-media-audit' ) },
				],
				filterBy: { isPrimary: true, operators: [ 'is' ] },
			},
			{
				id: 'usage',
				label: __( 'Used In', 'attached-media-audit' ),
				enableSorting: true,
				enableGlobalSearch: false,
				getValue: ( { item } ) => item.usage_count,
				render: ( { item } ) => <UsedInCell item={ item } indexBuilt={ indexBuilt } />,
			},
			{
				id: 'file_size',
				label: __( 'Size', 'attached-media-audit' ),
				enableSorting: true,
				enableGlobalSearch: false,
				getValue: ( { item } ) => item.file_size,
				render: ( { item } ) => formatFileSize( item.file_size ),
			},
			{
				id: 'alt_text',
				label: __( 'Alt Text', 'attached-media-audit' ),
				enableSorting: false,
				enableGlobalSearch: false,
				render: ( { item } ) => {
					if ( item.media_type !== 'Image' || ! item.content_alt_missing ) return null;
					return (
						<span className="wp-media-audit-no-alt">
							{ __( 'No alt', 'attached-media-audit' ) }
						</span>
					);
				},
			},
			{
				id: 'date',
				label: __( 'Date', 'attached-media-audit' ),
				enableSorting: true,
				enableGlobalSearch: false,
				getValue: ( { item } ) => item.date,
				render: ( { item } ) =>
					new Date( item.date ).toLocaleDateString( undefined, {
						year: 'numeric',
						month: 'short',
						day: 'numeric',
					} ),
			},
		],
		[ handleDeleteSingle, indexBuilt ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'delete',
				label: __( 'Delete', 'attached-media-audit' ),
				isDestructive: true,
				isEligible: ( item ) => item.usage_count === 0,
				callback: handleDeleteItems,
			},
		],
		[ handleDeleteItems ]
	);

	const paginationInfo = {
		totalItems,
		totalPages: Math.ceil( totalItems / view.perPage ),
	};

	return (
		<div className="wp-media-audit-app">
			<ScanToolbar
				status={ status }
				progress={ progress }
				total={ total }
				onScan={ startScan }
				onClear={ handleClear }
			/>
			<DataViews
				data={ items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				actions={ actions }
				defaultLayouts={ { table: {}, list: {} } }
				isLoading={ isLoading }
			/>
		</div>
	);
}
