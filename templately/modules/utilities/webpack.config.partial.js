const path = require('path');

// Folds into the one first-party bundle via react-src/generated/moduleManifest.ts
// (Constitution XIII) — no separate script is emitted or enqueued. The code
// self-gates on window.templately.utilities (injected by module.php), so it is
// inert on sites where the module did not boot.
module.exports = {
	entry: {
		'modules/utilities': path.resolve(__dirname, 'assets/js/index.tsx'),
	},
};
