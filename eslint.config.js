import eslint from '@eslint/js';
import globals from 'globals';

export default [
    {
        ignores: [
            'node_modules/**',
            'public/build/**',
            'storage/**',
        ],
    },
    eslint.configs.recommended,
    {
        files: ['resources/js/**/*.js', 'vite.config.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.node,
            },
        },
        rules: {
            'no-console': 'error',
            'no-eval': 'error',
            'no-implied-eval': 'error',
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            'prefer-const': 'error',
        },
    },
];

