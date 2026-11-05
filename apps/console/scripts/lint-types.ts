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
 *   bun run lint:types --against <ref>
 *     also fail when the baseline allows more errors than the one at <ref>,
 *     so CI rejects a baseline rewritten to absorb new errors
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

// tsc prints union members in the order their types were first created, so a
// new literal anywhere can reorder a long union and change every message that
// quotes it. Sort the top-level members of each quoted type so a message only
// depends on what the union holds.
export function canonical(message: string): string {
  const quotes: number[] = []
  for (let i = 0; i < message.length; i++) {
    // An apostrophe inside a word, as in `can't`, does not quote a type.
    if (
      message[i] === "'" &&
      !(isWord(message[i - 1]) && isWord(message[i + 1]))
    ) {
      quotes.push(i)
    }
  }
  let result = ''
  let last = 0
  for (let q = 0; q + 1 < quotes.length; q += 2) {
    result +=
      message.slice(last, quotes[q] + 1) +
      sortUnion(message.slice(quotes[q] + 1, quotes[q + 1]))
    last = quotes[q + 1]
  }
  return result + message.slice(last)
}

function isWord(char: string | undefined): boolean {
  return (
    char !== undefined &&
    ((char >= 'a' && char <= 'z') ||
      (char >= 'A' && char <= 'Z') ||
      (char >= '0' && char <= '9'))
  )
}

function sortUnion(type: string): string {
  const members: string[] = []
  let depth = 0
  let quoted = false
  let start = 0
  for (let i = 0; i < type.length; i++) {
    const char = type[i]
    if (quoted) {
      if (char === '\\') i++
      else if (char === '"') quoted = false
    } else if (char === '"') {
      quoted = true
    } else if ('<({['.includes(char)) {
      depth++
    } else if ('>)}]'.includes(char) && type[i - 1] !== '=') {
      // `=>` closes nothing.
      depth--
    } else if (depth === 0 && type.startsWith(' | ', i)) {
      members.push(type.slice(start, i))
      start = i + 3
      i += 2
    }
  }
  if (members.length === 0) {
    return type
  }
  members.push(type.slice(start))
  const others = members.filter((member) => !isPath(member))
  if (others.length < members.length) {
    others.push(PATHS)
  }
  return others.sort().join(' | ')
}

// Route path unions list every route, so adding one would change every
// message that quotes them. Compare the paths in a union as one placeholder.
const PATHS = '<paths>'

function isPath(member: string): boolean {
  return member === '"."' || member === '".."' || /^"\/[^"]*"$/.test(member)
}

// Baselines written before `canonical` keep tsc's member order.
function normalize(baseline: Baseline): Baseline {
  const result: Baseline = {}
  for (const [file, messages] of Object.entries(baseline)) {
    for (const [message, count] of Object.entries(messages)) {
      const key = canonical(message)
      result[file] ??= {}
      result[file][key] = (result[file][key] ?? 0) + count
    }
  }
  return result
}

// `src/a.tsx(12,5): error TS2339: Property 'x'...` -> `src/a.tsx`, `TS2339: Property 'x'...`
function parse(line: string): Diagnostic | null {
  const start = line.indexOf(MARKER)
  if (start === -1) {
    return null
  }
  // Messages can quote absolute module paths, which differ between machines.
  const message = canonical(
    line.slice(start + 'error '.length).replaceAll(`${ROOT}/`, ''),
  )
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
    ['bunx', 'tsc', '--noEmit', '--pretty', 'false', '--noErrorTruncation'],
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

function compare(
  allowed: Baseline,
  actual: Baseline,
): { added: string[]; fixed: string[] } {
  const added: string[] = []
  const fixed: string[] = []
  for (const file of new Set([
    ...Object.keys(allowed),
    ...Object.keys(actual),
  ])) {
    const known = allowed[file] ?? {}
    const found = actual[file] ?? {}
    for (const message of new Set([
      ...Object.keys(known),
      ...Object.keys(found),
    ])) {
      const limit = known[message] ?? 0
      const count = found[message] ?? 0
      const entry = `${file}: ${message} (${count}, baseline ${limit})`
      if (count > limit) {
        added.push(entry)
      } else if (count < limit) {
        fixed.push(entry)
      }
    }
  }
  return { added, fixed }
}

// Entries the baseline allows beyond the baseline at `--against <ref>`.
function against(baseline: Baseline): string[] {
  const index = process.argv.indexOf('--against')
  if (index === -1) {
    return []
  }
  const ref = process.argv[index + 1]
  if (!ref) {
    throw new Error('--against needs a git ref')
  }
  const result = Bun.spawnSync(
    ['git', 'show', `${ref}:./types-baseline.json`],
    {
      cwd: ROOT,
      stdout: 'pipe',
      stderr: 'pipe',
    },
  )
  if (result.exitCode !== 0) {
    console.log(`No baseline at ${ref}, nothing to compare against.`)
    return []
  }
  return compare(normalize(JSON.parse(result.stdout.toString())), baseline)
    .added
}

async function main() {
  const { output, diagnostics } = typecheck()
  const current = group(diagnostics)
  const files = Object.keys(current).length

  if (process.argv.includes('--update')) {
    await Bun.write(BASELINE, `${JSON.stringify(sorted(current), null, 2)}\n`)
    console.log(`Baseline: ${diagnostics.length} error(s) in ${files} file(s).`)
    return
  }

  const baseline = normalize(await Bun.file(BASELINE).json())
  const { added, fixed } = compare(baseline, current)
  const grown = against(baseline)

  if (grown.length > 0) {
    console.error(
      `The baseline allows errors its base did not, fix them instead:\n  ${grown.sort().join('\n  ')}\n`,
    )
  }
  if (added.length > 0) {
    console.error(`New type errors:\n  ${added.sort().join('\n  ')}`)
  }
  if (fixed.length > 0) {
    console.error(
      `\nFewer type errors than the baseline, run bun run lint:types --update:\n  ${fixed.sort().join('\n  ')}`,
    )
  }
  if (added.length > 0 || fixed.length > 0 || grown.length > 0) {
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
