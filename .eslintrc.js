module.exports = {
	root: true,
	extends: [
		'plugin:@wordpress/eslint-plugin/recommended-with-formatting',
		'plugin:compat/recommended',
	],
	env: {
		browser: true,
	},
	rules: {
		// No semi-colons because they're a hassle.
		semi: [ 'error', 'never' ],

		// Only use parenthesis on arrow functions that need them since it's a hassle.
		'arrow-parens': [ 'error', 'as-needed' ],

		// Allow our deprecated properties since they're readable.
		camelcase: [ 'error', {
			allow: [ '\\w+(_\\d+)+' ],
		} ],

		// Force destructuring assignments to be multiline if they have lots of variables.
		'object-curly-newline': [ 'error', {
			ObjectExpression: {
				multiline: true,
				minProperties: 3,
				consistent: true,
			},
			ObjectPattern: {
				multiline: true,
				minProperties: 3,
				consistent: true,
			},
			ImportDeclaration: {
				multiline: true,
				minProperties: 3,
				consistent: false,
			},
			ExportDeclaration: {
				multiline: true,
				minProperties: 3,
				consistent: false,
			},
		} ],

		'no-shadow': 'off',
		'no-nested-ternary': 'off',
		'no-mixed-spaces-and-tabs': [ 'error', 'smart-tabs' ],
		'sort-vars': [ 'error', { ignoreCase: true } ],
		'array-element-newline': [ 'error', 'consistent' ],
		'@wordpress/valid-sprintf': 'off',
		'@wordpress/no-unused-vars-before-return': 'off',
		'jsdoc/no-undefined-types': 'off',
		'@wordpress/no-unguarded-get-range-at': 'off',
		'linebreak-style': [ 'error', 'unix' ],
		'no-unused-expressions': 'off',
		'import/no-extraneous-dependencies': 'off',
		'import/no-unresolved': 'off',
		'@wordpress/i18n-text-domain': 'off',
		'@wordpress/i18n-translator-comments': 'off',
		'@wordpress/no-base-control-with-label-without-id': 'off',
		'no-unused-vars': [ 'error', { varsIgnorePattern: '^_' } ],
		'react/jsx-indent': [ 2, 'tab', { indentLogicalExpressions: true } ],
		'react/jsx-curly-brace-presence': [ 'error', { props: 'never', children: 'never' } ],
	},
	globals: {
		localStorage: true,
		fetch: true,
		btoa: true,
		alert: true,
		Element: true,
		FileReader: true,
		MutationObserver: true,
		IntersectionObserver: true,
	},
}
