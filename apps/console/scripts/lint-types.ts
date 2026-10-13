/**
 * Type-check the console against a baseline of known errors. Each file maps
 * every error message to how often it occurs there, without line numbers, so
 * edits that only move code keep matching. Fail when an error is new or occurs
 * more often than the baseline allows, and when one is gone or occurs less
 * often, so the baseline shrinks with every fix and never leaves room for new
 * errors.
 *
 * Usage:
 *   bun run lint:types           check against types-baseline.json
 *   bun run lint:types --update  rewrite the baseline from the current errors
 */
import { join } from 'path'

const ROOT = join(import.meta.dirname, '..')
const BASELINE = join(ROOT, 'types-baseline.json')
const MARKER = 'error TS'
// Errors without a location, like tsconfig problems.
const GLOBAL = '<global>'
// tsc exits 1 or 2 when it reports errors; anything else means it did not finish.
const EXIT_CODES = [0, 1, 2]

type Baseline = Record<string, Record<string, number>>

type Diagnostic = {
  file: string
  message: string
}

// `src/a.tsx(12,5): error TS2339: Property 'x'...` -> `src/a.tsx`, `TS2339: Property 'x'...`
function parse(line: string): Diagnostic | null {
  const start = line.indexOf(MARKER)
  if (start === -1) {
    return null
  }
  // Messages can quote absolute module paths, which differ between machines.
  const message = line.slice(start + 'error '.length).replaceAll(`${ROOT}/`, '')
  if (start === 0) {
    return { file: GLOBAL, message }
  }
  const location = line.slice(0, start).trimEnd()
  if (!location.endsWith('):')) {
    return null
  }
  return { file: location.slice(0, location.lastIndexOf('(')), message }
}

function typecheck(): { output: string; diagnostics: Diagnostic[] } {
  const result = Bun.spawnSync(
    ['bunx', 'tsc', '--noEmit', '--pretty', 'false'],
    { cwd: ROOT, stdout: 'pipe', stderr: 'inherit' },
  )
  const output = result.stdout.toString()
  if (!EXIT_CODES.includes(result.exitCode ?? -1)) {
    console.error(output)
    throw new Error(
      `tsc did not finish (exit ${result.exitCode}, signal ${result.signalCode ?? 'none'}), so its errors are incomplete`,
    )
  }
  const diagnostics = output
    .split('\n')
    .map(parse)
    .filter((diagnostic): diagnostic is Diagnostic => diagnostic !== null)
  if (result.exitCode !== 0 && diagnostics.length === 0) {
    console.error(output)
    throw new Error(`tsc exited with ${result.exitCode} and reported no errors`)
  }
  return { output, diagnostics }
}

function group(diagnostics: Diagnostic[]): Baseline {
  const baseline: Baseline = {}
  for (const { file, message } of diagnostics) {
    baseline[file] ??= {}
    baseline[file][message] = (baseline[file][message] ?? 0) + 1
  }
  return baseline
}

const sorted = (baseline: Baseline): Baseline =>
  Object.fromEntries(
    Object.keys(baseline)
      .sort()
      .map((file) => [
        file,
        Object.fromEntries(
          Object.entries(baseline[file]).sort(([a], [b]) => a.localeCompare(b)),
        ),
      ]),
  )

async function main() {
  const { output, diagnostics } = typecheck()
  const current = group(diagnostics)
  const files = Object.keys(current).length

  if (process.argv.includes('--update')) {
    await Bun.write(BASELINE, `${JSON.stringify(sorted(current), null, 2)}\n`)
    console.log(`Baseline: ${diagnostics.length} error(s) in ${files} file(s).`)
    return
  }

  const baseline: Baseline = await Bun.file(BASELINE).json()
  const added: string[] = []
  const fixed: string[] = []

  for (const file of new Set([
    ...Object.keys(baseline),
    ...Object.keys(current),
  ])) {
    const known = baseline[file] ?? {}
    const found = current[file] ?? {}
    for (const message of new Set([
      ...Object.keys(known),
      ...Object.keys(found),
    ])) {
      const allowed = known[message] ?? 0
      const actual = found[message] ?? 0
      const entry = `${file}: ${message} (${actual}, baseline ${allowed})`
      if (actual > allowed) {
        added.push(entry)
      } else if (actual < allowed) {
        fixed.push(entry)
      }
    }
  }

  if (added.length > 0) {
    console.error(`New type errors:\n  ${added.sort().join('\n  ')}`)
  }
  if (fixed.length > 0) {
    console.error(
      `\nFewer type errors than the baseline, run bun run lint:types --update:\n  ${fixed.sort().join('\n  ')}`,
    )
  }
  if (added.length > 0 || fixed.length > 0) {
    if (added.length > 0) {
      const failing = new Set(added.map((entry) => entry.split(': ')[0]))
      console.error(
        `\n${output
          .split('\n')
          .filter((line) => failing.has(parse(line)?.file ?? ''))
          .join('\n')}`,
      )
    }
    process.exit(1)
  }

  console.log(
    `Types: ${diagnostics.length} known error(s) in ${files} file(s), none new.`,
  )
}

if (import.meta.main) {
  await main()
}
