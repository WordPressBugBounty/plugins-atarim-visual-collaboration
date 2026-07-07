/**
 * Extends the default @wordpress/scripts webpack config to emit two bundles:
 *   - index.js          (existing settings-page React app)
 *   - offboarding.js  (deactivate/delete modals for plugins.php)
 *
 * Build still runs with --webpack-no-externals (see package.json), so React
 * and @wordpress/* are bundled into each output, matching the existing setup.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'src/index.js' ),
		'offboarding': path.resolve( __dirname, 'src/offboarding.js' ),
	},
};
