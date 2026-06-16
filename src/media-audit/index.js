import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';

apiFetch.use( apiFetch.createNonceMiddleware( window.wpMediaAudit.restNonce ) );

const container = document.getElementById( 'wp-media-audit-root' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
