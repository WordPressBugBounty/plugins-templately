/**
 * Jest Configuration for Templately React Unit Tests
 *
 * Uses @wordpress/jest-preset-default (bundled with @wordpress/scripts)
 * which provides jsdom environment, babel-jest transform, and WordPress mocks.
 */
module.exports = {
	preset: '@wordpress/jest-preset-default',

	roots: [ '<rootDir>/react-src' ],

	testMatch: [
		'<rootDir>/react-src/**/__tests__/**/*.[jt]s?(x)',
		'<rootDir>/react-src/**/*.test.[jt]s?(x)',
	],

	testPathIgnorePatterns: [
		'/node_modules/',
		'<rootDir>/vendor/',
		'<rootDir>/tests/e2e/',
	],

	moduleNameMapper: {
		// Static asset mocks (must come BEFORE aliases that resolve to asset files)
		'\\.(jpg|jpeg|png|gif|svg|webp)$':
			'<rootDir>/tests/js/__mocks__/fileMock.js',

		// Webpack aliases - icons are React components (iconMock), assets are images (fileMock).
		// Note: image file extensions above catch ~templately-icons/*.png before this pattern.
		'^~templately-icons$': '<rootDir>/tests/js/__mocks__/iconMock.js',
		'^~templately-icons/(.*)$': '<rootDir>/tests/js/__mocks__/iconMock.js',
		'^~templately-assets$': '<rootDir>/tests/js/__mocks__/fileMock.js',
		'^~templately-assets/(.*)$': '<rootDir>/tests/js/__mocks__/fileMock.js',
		'^~templately-hooks/(.*)$': '<rootDir>/react-src/app/hooks/$1',
		'^~templately-hooks$': '<rootDir>/react-src/app/hooks/index.js',
		'^~templately-utils/(.*)$': '<rootDir>/react-src/utils/$1',
		'^~templately-utils$': '<rootDir>/react-src/utils/index.js',
		'^~templately-redux/(.*)$': '<rootDir>/react-src/redux/$1',
		'^~templately-redux$': '<rootDir>/react-src/redux/index.js',

		// lodash-es -> lodash (ESM -> CJS for Jest)
		'^lodash-es$': 'lodash',
		'^lodash-es/(.*)$': 'lodash/$1',
	},

	setupFiles: [ '<rootDir>/tests/js/setup-globals.js' ],

	// @wordpress/jest-preset-default only sets up @wordpress/jest-console.
	// Extend with jest-dom matchers (toBeInTheDocument, toHaveClass, etc.).
	setupFilesAfterEnv: [ '@testing-library/jest-dom' ],

	// Transform all node_modules through babel. Slower but avoids ESM whack-a-mole.
	// The deep import chains (redux -> store -> components -> block-editor -> css-tree etc.)
	// pull in many ESM-only packages that Jest cannot handle natively.
	transformIgnorePatterns: [],
};
