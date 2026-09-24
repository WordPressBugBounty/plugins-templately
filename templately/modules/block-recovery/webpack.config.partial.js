const path = require('path');

module.exports = {
	entry: {
		'modules/block-recovery': path.resolve(__dirname, 'assets/js/index.tsx'),

		// Unprefixed key ON PURPOSE (spec 060, constitution XIII "screen-bound bundles").
		// This bundle's only host is the block editor, where the SPA graph is not present,
		// and Editor.php enqueues it BY PATH as `js/block-recovery-editor.js` — a `modules/`
		// key would fold it into the SPA manifest and delete the file that enqueue names.
		// Same exception as `ai-editor` / `gutenberg`. It imports nothing from the SPA
		// runtime: the pure gate, `repairPost`, and the host's own `wp.*` globals.
		'block-recovery-editor': path.resolve(__dirname, 'assets/js/editor/index.ts'),
	},
};
