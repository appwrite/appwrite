/**
 * Fail when source code opens a native browser dialog: alert(), confirm() or
 * prompt(), bare or via window / globalThis / self.
 *
 * The console has its own modals: ConfirmActionDialog and PromptDialog, plus
 * the useConfirmDialog / usePromptDialog hooks for imperative call sites.
 * Native dialogs cannot be styled or translated and block the page.
 *
 * The rules live in eslint.config.js so editors flag violations too. This
 * script runs only those rules, so CI can enforce them independently of the
 * wider ESLint baseline.
 *
 * Usage:
 *   bun run lint:native-dialogs
 *
 * Exit 1 when violations are found (for CI).
 */
import { join } from 'path'
import { ESLint } from 'eslint'

const ROOT = join(import.meta.dirname, '..')
const TARGETS = ['src', 'e2e', 'tests', '*.ts', '*.js', '*.cjs', '*.mjs']
const NATIVE_DIALOG_RULES = new Set([
  'no-restricted-globals',
  'no-restricted-properties',
])

async function main() {
  const eslint = new ESLint({
    cwd: ROOT,
    errorOnUnmatchedPattern: false,
    ruleFilter: ({ ruleId }) => NATIVE_DIALOG_RULES.has(ruleId),
    overrideConfig: [
      { linterOptions: { reportUnusedDisableDirectives: 'off' } },
    ],
  })

  const results = await eslint.lintFiles(TARGETS)
  const failing = results.filter((result) => result.errorCount > 0)

  if (failing.length > 0) {
    const formatter = await eslint.loadFormatter('stylish')
    console.error(await formatter.format(failing))
    console.error(
      'Replace native dialogs with ConfirmActionDialog / PromptDialog, or the useConfirmDialog / usePromptDialog hooks.',
    )
    process.exit(1)
  }

  console.log(`Native dialogs: none found in ${results.length} file(s).`)
}

if (import.meta.main) {
  await main()
}
