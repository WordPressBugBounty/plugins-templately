const path = require('path');

// Folds into the one first-party bundle via react-src/generated/moduleManifest.ts
// (Constitution XIII) — it no longer emits assets/js/modules/wp-abilities-api.js, and
// Admin::enqueue_module_assets() that used to glob-enqueue it is gone. The code self-gates
// on window.templately.mcp before
// registering the Settings → MCP tab, so it is inert on sites without the localized data
// (injected by REST/Connection.php).
module.exports = {
	entry: {
		'modules/wp-abilities-api': path.resolve(__dirname, 'assets/js/index.tsx'),
	},
};
