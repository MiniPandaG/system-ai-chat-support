import eslint from '@eslint/js';
import eslintReact from '@eslint-react/eslint-plugin';
import jsxA11y from 'eslint-plugin-jsx-a11y';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['public/build/**', 'vendor/**', 'node_modules/**', 'storage/**', 'design/**', 'docs/**', 'eslint.config.js'] },
    eslint.configs.recommended,
    tseslint.configs.strictTypeChecked,
    tseslint.configs.stylisticTypeChecked,
    eslintReact.configs['recommended-typescript'],
    reactHooks.configs.flat.recommended,
    jsxA11y.flatConfigs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}', 'vite.config.ts'],
        languageOptions: {
            globals: { ...globals.browser },
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
        rules: {
            'no-console': 'error',
            'no-debugger': 'error',
            '@typescript-eslint/no-explicit-any': 'error',
            '@typescript-eslint/ban-ts-comment': 'error',
            '@typescript-eslint/consistent-type-imports': 'error',
        },
    },
);
