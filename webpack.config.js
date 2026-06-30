const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'smart-media-audit-admin': path.resolve( __dirname, 'src/smart-media-audit/index.js' ),
	},
};
