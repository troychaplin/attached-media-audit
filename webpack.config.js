const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'media-audit-admin': path.resolve( __dirname, 'src/media-audit/index.js' ),
	},
};
