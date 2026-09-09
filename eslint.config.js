import reactHooks from 'eslint-plugin-react-hooks'
import unusedImports from 'eslint-plugin-unused-imports'
import tseslint from 'typescript-eslint'

const NATIVE_DIALOG_MESSAGE =
  'Native browser dialogs are not allowed. Use ConfirmActionDialog / useConfirmDialog for confirmations and PromptDialog / usePromptDialog for text input.'
const NATIVE_DIALOG_FUNCTIONS = ['alert', 'confirm', 'prompt']
const NATIVE_DIALOG_HOSTS = ['window', 'globalThis', 'self']

/**
 * Bans `alert()`, `confirm()` and `prompt()` (bare or via window/globalThis/self).
 * CI enforces these two rules on their own via scripts/lint-native-dialogs.ts.
 */
export const nativeDialogRules = {
  'no-restricted-globals': [
    'error',
    ...NATIVE_DIALOG_FUNCTIONS.map((name) => ({
      name,
      message: NATIVE_DIALOG_MESSAGE,
    })),
  ],
  'no-restricted-properties': [
    'error',
    ...NATIVE_DIALOG_HOSTS.flatMap((object) =>
      NATIVE_DIALOG_FUNCTIONS.map((property) => ({
        object,
        property,
        message: NATIVE_DIALOG_MESSAGE,
      })),
    ),
  ],
}

/**
 * @type {import('eslint').Linter.Config}
 */
export default tseslint.config(
  {
    ignores: [
      'dist/**',
      'node_modules/**',
      'scripts/**',
      'src/components/ui/**',
      'src/components/pages/projects/$projectId/shared/connect-snippets/**',
      '.output',
      '.nitro',
    ],
  },
  ...tseslint.configs.recommended,
  {
    plugins: {
      'react-hooks': reactHooks,
      'unused-imports': unusedImports,
    },
    rules: {
      '@typescript-eslint/no-unused-vars': 'error',
      'unused-imports/no-unused-imports': 'error',
      'react-hooks/exhaustive-deps': 'warn',
      'no-irregular-whitespace': [
        'error',
        { skipStrings: false, skipTemplates: false, skipJSXText: false },
      ],
      ...nativeDialogRules,
    },
  },
)
