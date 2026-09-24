const path = require('path');

// Routes-only bundle. ai-studio has no reducer to attach — this entry exists so the
// module can self-register its /ai-sites route via
// addFilter('templately.profile.routes', …) at boot, and so its RTK endpoints are
// injected, instead of core importing either. See assets/js/index.tsx.
module.exports = {
	entry: {
		'modules/ai-studio': path.resolve(__dirname, 'assets/js/index.tsx'),
	},
};
