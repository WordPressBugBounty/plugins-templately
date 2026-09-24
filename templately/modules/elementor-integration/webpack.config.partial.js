const path = require('path');

module.exports = {
	entry: {
		// Original entry key preserved (not `modules/elementor-integration`) — the
		// PHP side (Platform/Elementor.php::scripts()) hardcodes the enqueue as
		// `js/elementor.js` / `css/elementor.css`. A `modules/`-prefixed key would now
		// fold this into the SPA bundle (react-src/generated/moduleManifest.ts,
		// Constitution XIII) and delete the file that enqueue names. See PRD §1 R2.
		elementor: path.resolve(__dirname, 'assets/js/index.ts'),
	},
};
