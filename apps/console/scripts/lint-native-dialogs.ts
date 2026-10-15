/**
 * Fail when source code opens a native browser dialog: alert(), confirm() or
 * prompt(), bare or via window / globalThis / self.
 *
 * The console has its own modals: ConfirmActionDialog and PromptDialog, plus
 * the useConfirmDialog / usePromptDialog hooks for imperative call sites.
 * Native dialogs cannot be styled or translated and block the page.
 *
 * The rules live in eslint.config.js so editors flag violations too. This
 * script runs only those rules, including in shared UI components excluded
 * from the wider ESLint baseline.
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

export function createNativeDialogLinter() {
  return new ESLint({
    cwd: ROOT,
    // Ignore directives for unrelated rules and enforce the native-dialog ban.
    allowInlineConfig: false,
    errorOnUnmatchedPattern: false,
    overrideConfigFile: join(ROOT, 'eslint.native-dialogs.config.js'),
  })
}

async function main() {
  const eslint = createNativeDialogLinter()
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
