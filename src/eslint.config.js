// La configuració d'abans (.eslintrc.cjs) passada al format «flat» d'ESLint 9, amb les mateixes peces:
// les regles essencials de Vue 3, les recomanades d'ESLint i de TypeScript, i res de format (ho fa Prettier).
import js from '@eslint/js';
import skipFormatting from '@vue/eslint-config-prettier/skip-formatting';
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import pluginVue from 'eslint-plugin-vue';

export default defineConfigWithVueTs(
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'bootstrap/ssr/**', 'storage/**'] },
    { files: ['resources/js/**/*.{js,ts,vue}'] },
    js.configs.recommended,
    pluginVue.configs['flat/essential'],
    vueTsConfigs.recommended,
    skipFormatting,
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            // TypeScript ja diu què no existeix; route() de Ziggy és global
            'no-undef': 'off',
        },
    },
);
