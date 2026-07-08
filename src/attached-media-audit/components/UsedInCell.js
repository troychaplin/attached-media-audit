import { useState, useRef } from '@wordpress/element';
import { Button, Popover, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function UsedInCell( { item, indexBuilt } ) {
	const [ isOpen, setIsOpen ]       = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ locations, setLocations ] = useState( null );
	const [ hasMore, setHasMore ]     = useState( false );
	const [ limit, setLimit ]         = useState( 0 );
	const anchorRef = useRef( null );
	const cacheRef  = useRef( null );

	if ( item.usage_count === 0 ) {
		if ( ! indexBuilt ) {
			return <span className="wp-attached-media-audit-unscanned">{ __( 'Scan required', 'attached-media-audit' ) }</span>;
		}
		return <span className="wp-attached-media-audit-unused">{ __( 'Unused', 'attached-media-audit' ) }</span>;
	}

	const applyResult = ( result ) => {
		setLocations( result.locations );
		setHasMore( result.hasMore );
		setLimit( result.limit );
	};

	const fetchLocations = () => {
		if ( cacheRef.current ) {
			applyResult( cacheRef.current );
			return;
		}
		setIsLoading( true );
		const { ajaxUrl, nonce } = window.wpAttachedMediaAudit;
		fetch( `${ ajaxUrl }?action=attached_media_audit_locations&nonce=${ nonce }&attachment_id=${ item.id }` )
			.then( ( r ) => r.json() )
			.then( ( json ) => {
				const data = json.data || {};
				const result = {
					locations: data.locations || [],
					hasMore: !! data.has_more,
					limit: data.limit || 0,
				};
				cacheRef.current = result;
				applyResult( result );
			} )
			.catch( () => applyResult( { locations: [], hasMore: false, limit: 0 } ) )
			.finally( () => setIsLoading( false ) );
	};

	const handleToggle = () => {
		if ( ! isOpen ) {
			fetchLocations();
		}
		setIsOpen( ( v ) => ! v );
	};

	const label =
		item.usage_count === 1
			? __( '1 post', 'attached-media-audit' )
			: sprintf(
					/* translators: %d: number of posts */
					__( '%d posts', 'attached-media-audit' ),
					item.usage_count
			  );

	return (
		<span ref={ anchorRef } className="wp-attached-media-audit-used-in">
			<Button variant="link" onClick={ handleToggle } aria-expanded={ isOpen }>
				{ label }
			</Button>
			{ isOpen && anchorRef.current && (
				<Popover
					anchor={ anchorRef.current }
					onClose={ () => setIsOpen( false ) }
					placement="bottom-start"
					focusOnMount={ false }
				>
					<div className="wp-attached-media-audit-popover">
						{ isLoading && <Spinner /> }
						{ ! isLoading && locations && (
							<ul className="wp-attached-media-audit-locations-list">
								{ locations.map( ( loc, i ) => (
									<li key={ i }>
										<a href={ loc.edit_url }>{ loc.post_title }</a>
										<span className="wp-attached-media-audit-ref-type">
											{ loc.reference_type.replace( '_', ' ' ) }
										</span>
									</li>
								) ) }
							</ul>
						) }
						{ ! isLoading && hasMore && (
							<p className="wp-attached-media-audit-locations-more">
								{ sprintf(
									/* translators: %d: maximum number of posts shown */
									__( 'Showing first %d. More references exist.', 'attached-media-audit' ),
									limit
								) }
							</p>
						) }
					</div>
				</Popover>
			) }
		</span>
	);
}
