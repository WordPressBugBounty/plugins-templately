const path = require('path');

module.exports = {
	entry: {
		// Folds into the SPA bundle via react-src/generated/moduleManifest.ts (Constitution
		// XIII). That bundle is enqueued unconditionally by Admin::enqueue_scripts(), which
		// runs on the editor screen too — the same reach the retired enqueue_module_assets()
		// glob gave this module's own bundle, so registerBlockType still runs there.
		'modules/block-patterns': path.resolve(__dirname, 'assets/js/index.tsx'),

		// The stylesheet must stay a REAL entry (unprefixed key → assets/css/style-block-patterns.css
		// — @wordpress/scripts prefixes `style-` when the source file is named `style.scss`),
		// because it is the one piece of this module that does NOT ride the SPA bundle: the
		// placeholder renders inside the editor's canvas IFRAME, which only sees what
		// `enqueue_block_assets` puts there. Imported from the JS index instead, it would be
		// swept into style-templately.css along with every other module's CSS — a file that is
		// enqueued on the admin page, never inside the canvas, and far too big to inject there.
		// Same precedent as modules/post-import-feedback's `-style` entry.
		'block-patterns': path.resolve(__dirname, 'assets/scss/style.scss'),
	},
};
