const path = require('path');

// Routes-only bundle: the Subscription screen self-registers its /subscription route
// via addFilter('templately.profile.routes', …) at boot, so core never imports it.
module.exports = {
	entry: {
		'modules/subscription': path.resolve(__dirname, 'assets/js/index.tsx'),
	},
};
