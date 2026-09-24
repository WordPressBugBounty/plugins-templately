const path = require('path');

// Routes-only bundle (spec 029). profile-collections has no reducer/saga to attach —
// this entry exists solely so the module can self-register its /downloads, /favourites,
// /purchased-items routes via addFilter('templately.profile.routes', …) at boot, instead
// of core importing its page components. See assets/js/index.tsx.
module.exports = {
	entry: {
		'modules/profile-collections': path.resolve(__dirname, 'assets/js/index.tsx'),
	},
};
