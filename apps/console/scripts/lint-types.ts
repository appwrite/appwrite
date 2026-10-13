/**
 * Type-check the console against a baseline of known errors per file. Fail when
 * a file has more errors than the baseline allows, and when it has fewer, so the
 * baseline shrinks with every fix and never leaves room for new errors.
 *
 * Usage:
 *   bun run lint:types           check against types-baseline.json
 *   bun run lint:types --update  rewrite the baseline from the current errors
 */
import { join } from 'path'

const ROOT = join(import.meta.dirname, '..')
const BASELINE = join(ROOT, 'types-baseline.json')
const ERROR = '): error TS'

type Counts = Record<string, number>

// `src/a.tsx(12,5): error TS2339: ...` -> `src/a.tsx`
function fileOf(line: string): string | null {
  const end = line.indexOf(ERROR)
  if (end === -1) {
    return null
  }
  return line.slice(0, line.lastIndexOf('(', end))
}

function typecheck(): { output: string; counts: Counts } {
  const result = Bun.spawnSync(
    ['bunx', 'tsc', '--noEmit', '--pretty', 'false'],
    { cwd: ROOT, stdout: 'pipe', stderr: 'inherit' },
  )
  const output = result.stdout.toString()
  const counts: Counts = {}
  for (const line of output.split('\n')) {
    const file = fileOf(line)
    if (file) {
      counts[file] = (counts[file] ?? 0) + 1
    }
  }
  if (result.exitCode !== 0 && Object.keys(counts).length === 0) {
    console.error(output)
    throw new Error(`tsc exited with ${result.exitCode} and reported no errors`)
  }
  return { output, counts }
}

const sorted = (counts: Counts): Counts =>
  Object.fromEntries(
    Object.entries(counts).sort(([a], [b]) => a.localeCompare(b)),
  )

const total = (counts: Counts) =>
  Object.values(counts).reduce((sum, count) => sum + count, 0)

async function main() {
  const { output, counts } = typecheck()

  if (process.argv.includes('--update')) {
    await Bun.write(BASELINE, `${JSON.stringify(sorted(counts), null, 2)}\n`)
    console.log(
      `Baseline: ${total(counts)} error(s) in ${Object.keys(counts).length} file(s).`,
    )
    return
  }

  const baseline: Counts = await Bun.file(BASELINE).json()
  const files = new Set([...Object.keys(baseline), ...Object.keys(counts)])
  const added: string[] = []
  const fixed: string[] = []

  for (const file of [...files].sort()) {
    const allowed = baseline[file] ?? 0
    const actual = counts[file] ?? 0
    if (actual > allowed) {
      added.push(`${file}: ${actual} error(s), baseline ${allowed}`)
    } else if (actual < allowed) {
      fixed.push(`${file}: ${actual} error(s), baseline ${allowed}`)
    }
  }

  if (added.length > 0) {
    const failing = new Set(added.map((line) => line.split(':')[0]))
    console.error(
      output
        .split('\n')
        .filter((line) => failing.has(fileOf(line) ?? ''))
        .join('\n'),
    )
    console.error(`\nNew type errors:\n  ${added.join('\n  ')}`)
  }
  if (fixed.length > 0) {
    console.error(
      `\nFewer type errors than the baseline, run bun run lint:types --update:\n  ${fixed.join('\n  ')}`,
    )
  }
  if (added.length > 0 || fixed.length > 0) {
    process.exit(1)
  }

  console.log(
    `Types: ${total(counts)} known error(s) in ${Object.keys(counts).length} file(s), none new.`,
  )
}

if (import.meta.main) {
  await main()
}
