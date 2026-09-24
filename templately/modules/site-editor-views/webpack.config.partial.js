const path = require('path');

module.exports = {
	entry: {
		// A standalone entry, NOT a `modules/`-prefixed key: this script is
		// enqueued by hand on the Site Editor screen only (module.php), and a
		// `modules/` key would fold it into the SPA bundle, which never loads
		// there. Same reasoning as gutenberg-integration and elementor-integration.
		'site-editor-views': path.resolve(__dirname, 'assets/js/index.ts'),
	},
};
