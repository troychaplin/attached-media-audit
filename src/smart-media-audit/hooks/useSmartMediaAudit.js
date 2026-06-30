import { useState, useEffect, useRef } from '@wordpress/element';

export default function useSmartMediaAudit( view, scanVersion ) {
	const [ items, setItems ]           = useState( [] );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ isLoading, setIsLoading ]   = useState( true );
	const abortRef = useRef( null );
	const cacheRef = useRef( new Map() );
	const cacheVersionRef = useRef( scanVersion );

	useEffect( () => {
		if ( abortRef.current ) {
			abortRef.current.abort();
		}

		// A scan/clear/delete bumps scanVersion and makes the index stale, so
		// drop the whole client cache rather than letting old entries linger.
		if ( cacheVersionRef.current !== scanVersion ) {
			cacheRef.current.clear();
			cacheVersionRef.current = scanVersion;
		}

		const params = new URLSearchParams();
		params.set( 'page', view.page );
		params.set( 'per_page', view.perPage );

		if ( view.search ) {
			params.set( 'search', view.search );
		}

		if ( view.sort?.field ) {
			params.set( 'orderby', view.sort.field );
			params.set( 'order', view.sort.direction === 'asc' ? 'ASC' : 'DESC' );
		}

		const mediaTypeFilter = view.filters?.find( ( f ) => f.field === 'media_type' );
		if ( mediaTypeFilter?.value ) {
			params.set( 'media_type', mediaTypeFilter.value );
		}

		const refTypeFilter = view.filters?.find( ( f ) => f.field === 'reference_type' );
		if ( refTypeFilter?.value ) {
			params.set( 'reference_type', refTypeFilter.value );
		}

		// Serve an identical prior view from cache. scanVersion is part of the
		// key, so a scan/clear/delete (which bumps it) invalidates every entry.
		const cacheKey = `${ scanVersion }|${ params.toString() }`;
		const cached = cacheRef.current.get( cacheKey );
		if ( cached ) {
			setItems( cached.items );
			setTotalItems( cached.total );
			setIsLoading( false );
			return;
		}

		abortRef.current = new AbortController();
		setIsLoading( true );

		fetch( `${ window.wpSmartMediaAudit.restUrl }?${ params.toString() }`, {
			headers: { 'X-WP-Nonce': window.wpSmartMediaAudit.restNonce },
			signal: abortRef.current.signal,
		} )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				const items = data.items || [];
				const total = data.total || 0;
				cacheRef.current.set( cacheKey, { items, total } );
				setItems( items );
				setTotalItems( total );
			} )
			.catch( ( err ) => {
				if ( err.name !== 'AbortError' ) {
					// eslint-disable-next-line no-console
					console.error( 'WP Media Audit fetch error:', err );
				}
			} )
			.finally( () => setIsLoading( false ) );
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ view, scanVersion ] );

	return { items, totalItems, isLoading };
}
