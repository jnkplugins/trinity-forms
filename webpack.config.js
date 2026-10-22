/**
 * wp-scripts picks this file up for EVERY build script (blocks, pro, builder), and
 * the default config derives its entries from whichever --webpack-src-dir the CLI
 * passed. So the only thing we add here is the one entry that isn't a block:
 * conditional-panel, which registers a shared editor.BlockEdit filter and therefore
 * has no block.json for wp-scripts to discover.
 */
const path = require( "path" );
const defaultConfig = require( "@wordpress/scripts/config/webpack.config" );

// wp-scripts consumes --webpack-src-dir itself and re-exports it as WP_SRC_DIRECTORY
// rather than forwarding it to webpack, so this is the only place the current build's
// source dir is visible.
const isBlocksBuild = process.env.WP_SRC_DIRECTORY === "blocks";

module.exports = {
	...defaultConfig,
	entry: async () => {
		const base = typeof defaultConfig.entry === "function"
			? await defaultConfig.entry()
			: defaultConfig.entry;
		if ( ! isBlocksBuild ) {
			return base;
		}
		return {
			...base,
			"conditional-panel": path.resolve( __dirname, "blocks/conditional-panel.js" ),
		};
	},
};
