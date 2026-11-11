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
    // páginas públicas sem módulos: cartaz do cliente (c/) e painel da sala comercial (sala/)
    files: ['c/**/*.js', 'sala/**/*.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'script', globals: { ...globals.browser } },
    rules: { 'no-unused-vars': ['error', { caughtErrors: 'none' }], eqeqeq: ['error', 'smart'] },
  },
  {
    // conversores de exportações do Pinguim BO (Node, CommonJS), corridos na linha de comandos
    files: ['scripts/**/*.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'commonjs', globals: { ...globals.node } },
    rules: { 'no-empty': ['error', { allowEmptyCatch: true }], 'no-unused-vars': ['error', { caughtErrors: 'none' }] },
  },
  {
    // ferramentas da linha de comandos em Node (ex.: teste de carga)
    files: ['ferramentas/**/*.mjs'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.node } },
    rules: { 'no-empty': ['error', { allowEmptyCatch: true }], 'no-unused-vars': ['error', { caughtErrors: 'none' }] },
  },
  {
    files: ['tests/**/*.js', '*.config.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.node, ...globals.browser } },
    rules: { 'no-empty': ['error', { allowEmptyCatch: true }], 'no-unused-vars': ['error', { caughtErrors: 'none' }] },
  },
];
