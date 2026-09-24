const path = require('path');

// Folds into the one first-party bundle via react-src/generated/moduleManifest.ts
// (Constitution XIII) — it no longer emits assets/js/modules/mcp-server.js, and
// Admin::enqueue_module_assets() that used to glob-enqueue it is gone. The code self-gates
// on window.templately.mcpServer
// before registering the Settings → AI Agents tab, so it is inert on sites without the
// localized data (injected by REST/Connections.php).
module.exports = {
	entry: {
		'modules/mcp-server': path.resolve(__dirname, 'assets/js/index.tsx'),
	},
};
