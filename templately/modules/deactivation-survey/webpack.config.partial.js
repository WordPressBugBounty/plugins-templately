const path = require('path');

// The entry keys are UNPREFIXED, so these stay REAL webpack entries emitting
// assets/js/deactivation-survey.js and assets/css/deactivation-survey-style.css,
// which module.php enqueues by those paths on plugins.php. A `modules/` prefix
// would fold this into the SPA bundle (react-src/generated/moduleManifest.ts,
// Constitution XIII) — and the SPA never loads on plugins.php, so the survey
// would simply never run. Same reasoning as post-import-feedback's entry.
module.exports = {
	entry: {
		'deactivation-survey': path.resolve(__dirname, 'assets/js/index.tsx'),
		'deactivation-survey-style': path.resolve(__dirname, 'assets/scss/index.scss'),
	},
};
