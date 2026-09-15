import tseslint from 'typescript-eslint'
import { nativeDialogRules } from './eslint.config.js'

export default [
  {
    ignores: [
      'dist/**',
      'node_modules/**',
      'scripts/**',
      'src/components/pages/projects/$projectId/shared/connect-snippets/**',
      '.output/**',
      '.nitro/**',
    ],
  },
  {
    files: ['**/*.{js,jsx,cjs,mjs,ts,tsx,cts,mts}'],
    languageOptions: {
      parser: tseslint.parser,
      parserOptions: { ecmaFeatures: { jsx: true } },
    },
    rules: nativeDialogRules,
  },
]
