import { useState, useRef } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function UsedInCell( { item } ) {
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ isLoading, setIsLoading ]   = useState( false );
	const [ locations, setLocations ]   = useState( null );
	const cacheRef = useRef( null );

	if ( item.usage_count === 0 ) {
		return <span className="wp-media-audit-unused">{ __( 'Unused', 'wp-media-audit' ) }</span>;
	}

	const fetchLocations = () => {
		if ( cacheRef.current ) {
			setLocations( cacheRef.current );
			return;
		}
		setIsLoading( true );
		const { ajaxUrl, nonce } = window.wpMediaAudit;
		fetch( `${ ajaxUrl }?action=media_audit_locations&nonce=${ nonce }&attachment_id=${ item.id }` )
			.then( ( r ) => r.json() )
			.then( ( json ) => {
				const data = json.data || [];
				cacheRef.current = data;
				setLocations( data );
			} )
			.catch( () => setLocations( [] ) )
			.finally( () => setIsLoading( false ) );
	};

	const handleToggle = () => {
		if ( ! isExpanded ) {
			fetchLocations();
		}
		setIsExpanded( ( v ) => ! v );
	};

	const label =
		item.usage_count === 1
			? __( '1 post', 'wp-media-audit' )
			: sprintf(
					/* translators: %d: number of posts */
					__( '%d posts', 'wp-media-audit' ),
					item.usage_count
			  );

	return (
		<div className="wp-media-audit-used-in">
			<Button
				variant="link"
				onClick={ handleToggle }
				aria-expanded={ isExpanded }
			>
				{ label }
			</Button>
			{ isExpanded && (
				<div className="wp-media-audit-used-in-list">
					{ isLoading && <Spinner /> }
					{ ! isLoading && locations && (
						<ul className="wp-media-audit-locations-list">
							{ locations.map( ( loc, i ) => (
								<li key={ i }>
									<a href={ loc.edit_url }>{ loc.post_title }</a>
									<span className="wp-media-audit-ref-type">
										{ loc.reference_type.replace( '_', ' ' ) }
									</span>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</div>
	);
}
