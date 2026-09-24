const path = require('path');

module.exports = {
	entry: {
		// Unprefixed entry key preserved (not `modules/ai-editor`). A `modules/`
		// key folds the code into the SPA bundle graph
		// (react-src/generated/moduleManifest.ts, Constitution XIII) and stops
		// emitting a standalone file — but module.php enqueues this bundle BY
		// PATH as `js/ai-editor.js`, and only on editor screens, so it must keep
		// existing as its own file and must NOT join the always-loaded SPA
		// bundle. Same exception as `elementor` / `gutenberg`.
		'ai-editor': path.resolve(__dirname, 'assets/js/index.tsx'),
	},
};
