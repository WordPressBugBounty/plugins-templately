const path = require('path');

// Entry key is `post-import-feedback` (not `dashboard`) to clarify that this entry
// point is specific to the feedback feature, not a general "dashboard" bundle (spec
// 027's own Build Artifacts note). Ships in production (no devOnly flag) — the widget
// is eligibility-gated at enqueue time in PHP, not excluded from the build.
module.exports = {
	entry: {
		// The entry KEY stays 'post-import-feedback' — unprefixed, so this stays a REAL
		// webpack entry emitting assets/js/post-import-feedback.js, which Widget.php enqueues
		// by that path on the dashboard. A `modules/` prefix would fold it into the SPA
		// bundle (react-src/generated/moduleManifest.ts, Constitution XIII) and break that
		// enqueue. Only the SOURCE path changed when the entry became TypeScript.
		'post-import-feedback': path.resolve(__dirname, 'assets/js/index.tsx'),
		'post-import-feedback-style': path.resolve(__dirname, 'assets/scss/index.scss'),
	},
};
