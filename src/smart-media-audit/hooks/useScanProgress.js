import { useState, useEffect, useRef, useCallback } from '@wordpress/element';

export default function useScanProgress( { onComplete } = {} ) {
	const [ state, setState ] = useState(
		() => window.wpSmartMediaAudit?.initialProgress || { status: 'idle', progress: 0, total: 0 }
	);

	const prevStatusRef = useRef( state.status );
	const intervalRef   = useRef( null );
	const onCompleteRef = useRef( onComplete );
	onCompleteRef.current = onComplete;

	const poll = useCallback( () => {
		const { ajaxUrl, nonce } = window.wpSmartMediaAudit;
		fetch( `${ ajaxUrl }?action=smart_media_audit_progress&nonce=${ nonce }` )
			.then( ( r ) => r.json() )
			.then( ( json ) => {
				if ( ! json.success ) return;
				const data = json.data;
				setState( data );
				if ( prevStatusRef.current === 'scanning' && data.status !== 'scanning' ) {
					clearInterval( intervalRef.current );
					intervalRef.current = null;
					onCompleteRef.current?.();
				}
				prevStatusRef.current = data.status;
			} )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		if ( state.status === 'scanning' && ! intervalRef.current ) {
			intervalRef.current = setInterval( poll, 2000 );
		}
		return () => {
			if ( intervalRef.current && state.status !== 'scanning' ) {
				clearInterval( intervalRef.current );
				intervalRef.current = null;
			}
		};
	}, [ state.status, poll ] );

	const startScan = useCallback( () => {
		const { ajaxUrl, nonce } = window.wpSmartMediaAudit;
		const body = new FormData();
		body.append( 'action', 'smart_media_audit_scan' );
		body.append( 'nonce', nonce );

		fetch( ajaxUrl, { method: 'POST', body } )
			.then( () => {
				prevStatusRef.current = 'scanning';
				setState( { status: 'scanning', progress: 0, total: 0 } );
			} )
			.catch( () => {} );
	}, [] );

	const resetToIdle = useCallback( () => {
		setState( { status: 'idle', progress: 0, total: 0 } );
		prevStatusRef.current = 'idle';
	}, [] );

	return { ...state, startScan, resetToIdle };
}
