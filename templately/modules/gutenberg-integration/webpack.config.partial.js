const path = require('path');

module.exports = {
	entry: {
		// Original entry key preserved (not `modules/gutenberg-integration`) — the
		// PHP side (Platform/Gutenberg.php::scripts()) hardcodes the enqueue as
		// `js/gutenberg.js` / `css/gutenberg.css`. A `modules/`-prefixed key would now
		// fold this into the SPA bundle (react-src/generated/moduleManifest.ts,
		// Constitution XIII) and delete the file that enqueue names. See PRD §1 R2 and
		// the identical precedent in modules/elementor-integration (spec 032).
		gutenberg: path.resolve(__dirname, 'assets/js/index.tsx'),
	},
};
