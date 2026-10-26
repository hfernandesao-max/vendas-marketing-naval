// Normas de código JavaScript (ESLint, configuração plana).
import js from '@eslint/js';
import globals from 'globals';

export default [
  { linterOptions: { reportUnusedDisableDirectives: 'error' } },
  { ignores: ['node_modules/', 'reports/vendor/', 'geo/', 'playwright-report/', 'test-results/'] },
  js.configs.recommended,
  {
    files: ['reports/js/**/*.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.browser, ExcelJS: 'readonly', JSZip: 'readonly' } },
    rules: {
      'no-unused-vars': ['error', { args: 'after-used', caughtErrors: 'none' }],
      'no-empty': ['error', { allowEmptyCatch: true }],
      eqeqeq: ['error', 'smart'],
      'prefer-const': 'error',
      'no-var': 'error',
    },
  },
  {
    files: ['tests/**/*.js', '*.config.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.node, ...globals.browser } },
    rules: { 'no-empty': ['error', { allowEmptyCatch: true }], 'no-unused-vars': ['error', { caughtErrors: 'none' }] },
  },
];
