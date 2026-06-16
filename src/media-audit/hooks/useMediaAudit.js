import { useState, useEffect, useRef } from '@wordpress/element';

export default function useMediaAudit( view, scanVersion ) {
	const [ items, setItems ]           = useState( [] );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ isLoading, setIsLoading ]   = useState( true );
	const abortRef = useRef( null );

	useEffect( () => {
		if ( abortRef.current ) {
			abortRef.current.abort();
		}
		abortRef.current = new AbortController();

		setIsLoading( true );

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

		const usageStatusFilter = view.filters?.find( ( f ) => f.field === 'usage_status' );
		if ( usageStatusFilter?.value ) {
			params.set( 'usage_filter', usageStatusFilter.value );
		}

		fetch( `${ window.wpMediaAudit.restUrl }?${ params.toString() }`, {
			headers: { 'X-WP-Nonce': window.wpMediaAudit.restNonce },
			signal: abortRef.current.signal,
		} )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				setItems( data.items || [] );
				setTotalItems( data.total || 0 );
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
