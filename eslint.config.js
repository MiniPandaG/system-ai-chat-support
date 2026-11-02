import eslint from '@eslint/js';
import eslintReact from '@eslint-react/eslint-plugin';
import jsxA11y from 'eslint-plugin-jsx-a11y';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['public/build/**', 'public/widget.js', 'vendor/**', 'node_modules/**', 'storage/**', 'eslint.config.js'] },
    eslint.configs.recommended,
    tseslint.configs.strictTypeChecked,
    tseslint.configs.stylisticTypeChecked,
    eslintReact.configs['recommended-typescript'],
    reactHooks.configs.flat.recommended,
    jsxA11y.flatConfigs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}', 'vite.config.ts', 'vite.widget.config.ts', 'vitest.config.ts'],
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
    {
        // Esta regla de jsx-a11y se cae al recorrer los hijos de un label porque el override de minimatch 11 (por una
        // vulnerabilidad) cambió la API que usa. Aquí el label sí está asociado (htmlFor al input que contiene) y lo
        // comprueban las pruebas de FileUpload. Quitar cuando jsx-a11y soporte minimatch 11.
        files: ['resources/js/components/FileUpload.tsx'],
        rules: { 'jsx-a11y/label-has-associated-control': 'off' },
    },
);
