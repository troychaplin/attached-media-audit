import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';

apiFetch.use( apiFetch.createNonceMiddleware( window.wpAttachedMediaAudit.restNonce ) );

const container = document.getElementById( 'wp-attached-media-audit-root' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
