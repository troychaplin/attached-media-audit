const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'attached-media-audit-admin': path.resolve( __dirname, 'src/attached-media-audit/index.js' ),
	},
};
