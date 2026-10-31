// The JSON `bin/compat report` writes to apps/compat/data (see
// crates/tools/compat/src/runner/report.rs), loaded as Vite modules so it works
// in dev, in SSR and in a build alike.

export type Verdict = 'same' | 'compatible' | 'review' | 'differs' | 'type' | 'missing'
export type Fit = 'same' | 'compatible' | 'review' | 'differs'

export interface LibSummary {
  lib: string
  description: string
  complete: boolean
  php: string[]
  crate: string
  coverage: {
    symbols: number
    covered: number
    waived: number
    percent: number
    uncovered: string[]
    dangling: string[]
    ops: number
    missing_php: string[]
    missing_rust: string[]
    ops_without_cases: string[]
    cases: number
    steps: number
    steps_without_expect: number
    tests: number
    tests_unported: string[]
    ok: boolean
  }
  run: { cases: number; passed: number; failed: number; differences: number; faults: number }
  fuzz: { profiles: number; inputs: number; configured: number; differences: number; faults: number }
  docs: { symbols: number; linked: number; php_documented: number; rust_items: number; rust_only: number }
  interface: { methods: number; verdicts: Partial<Record<Verdict, number>>; untested: number }
}

export interface Index {
  generated: string
  commit: string | null
  branch: string | null
  seed: number
  iterations: number
  libs: LibSummary[]
}

export interface Step {
  op: string
  side?: string | null
  args: unknown
  bind?: string | null
  masks?: { paths: string[]; reason: string }[]
  php?: unknown
  rust?: unknown
  expect?: unknown
  status: 'match' | 'differ' | 'expect' | 'fault'
  at?: string | null
  faults?: string[]
}

export interface Case {
  file: string
  name: string
  interop: boolean
  ok: boolean
  differences: string[]
  faults: string[]
  steps: Step[]
}

export interface FuzzProfile {
  op: string
  profile: string
  inputs: number
  iterations: number
  isolate: boolean
  ok: boolean
  problem: string | null
}

export interface RustFunction {
  receiver: string | null
  params: { name: string; type: string }[]
  output: string
  async: boolean
}

export interface RustItem {
  path: string
  module: string
  name: string
  owner: string | null
  kind: string
  implements: string | null
  signature: string
  doc: string
  inherits: string | null
  inherited_doc: string | null
  file: string
  line: number
  function: RustFunction | null
  via?: string
}

export interface PhpParam {
  name: string
  type: string
  default: string | null
  optional: boolean
  variadic: boolean
  reference: boolean
}

export interface PhpDoc {
  signature: string
  static: boolean
  params: PhpParam[]
  returns: string
  doc: string
  classDoc: string
  file: string
  line: number
}

export interface DocEntry {
  symbol: string
  php: PhpDoc | null
  rust: RustItem[]
  ops: string[]
  waiver: string | null
}

export interface ParamRow {
  php: PhpParam | null
  rust: { name: string; type: string } | null
  fit: Fit
  note: string | null
}

export interface Comparison {
  rust: string
  kind: string
  receiver: string | null
  async: boolean
  params: ParamRow[]
  returns: { php: string; rust: string; fit: Fit; note: string | null; throws: boolean }
  notes: string[]
  verdict: Fit
}

export interface Method {
  symbol: string
  php: Pick<PhpDoc, 'signature' | 'static' | 'params' | 'returns' | 'file' | 'line'>
  verdict: Verdict
  best: Comparison | null
  others: { rust: string; verdict: Fit }[]
  types: string[]
  waiver: string | null
  evidence: { ops: string[]; steps: number; failing: number; fuzz: number; fuzz_ok: boolean }
}

export interface Spec {
  description: string
  services: string[]
  state: string[]
  ops: Record<string, { doc: string; covers: string[]; mask: { paths: string[]; reason: string } | null; fuzz: { name: string; iterations: number; isolate: boolean }[] }>
  waivers: Record<string, string>
  tests_waived: Record<string, string>
  quirks: { symbol: string; description: string; case: string }[]
  deviations: { symbol: string; php: string; reason: string }[]
}

export interface Detail {
  summary: LibSummary
  spec: Spec
  cases: Case[]
  fuzz: FuzzProfile[]
  docs: DocEntry[]
  interface: Method[]
  rust_only: RustItem[]
  problems: string[]
}

const indexModule = import.meta.glob<Index>('../../data/index.json', { import: 'default' })
const libModules = import.meta.glob<Detail>(['../../data/*.json', '!../../data/index.json'], { import: 'default' })

/** The report's index, or `null` before `bin/compat report` has run. */
export async function loadIndex(): Promise<Index | null> {
  const load = Object.values(indexModule)[0]
  return load ? load() : null
}

/** One library's report, or `null` when it is not in the report. */
export async function loadLib(lib: string): Promise<Detail | null> {
  const load = libModules[`../../data/${lib}.json`]
  return load ? load() : null
}

export const PAGE = 100

/** `github.com/appwrite/appwrite` link to a file (and line) at the report's branch. */
export function sourceUrl(index: Pick<Index, 'branch' | 'commit'> | null, file: string, line?: number) {
  const ref = index?.branch || index?.commit || 'main'
  return `https://github.com/appwrite/appwrite/blob/${ref}/${file}${line ? `#L${line}` : ''}`
}

export function problemCount(l: LibSummary) {
  return l.run.differences + l.run.faults + l.fuzz.differences + l.fuzz.faults
}
