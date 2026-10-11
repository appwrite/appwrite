// The Appwrite API's migration from PHP to Rust, as `bin/compat appwrite`
// writes it to apps/crates/data/appwrite.json (see
// crates/tools/compat/src/runner/appwrite/mod.rs).

/** A service as a whole: every route served by Rust, some, or none. */
export type ServiceStatus = 'rust' | 'partial' | 'php'

/**
 * One route: `served` (Traefik sends it to Rust, which implements it),
 * `ready` (Rust implements it, Traefik does not send it yet), `missing`
 * (Traefik sends it to Rust, which lacks it) or `php`.
 */
export type RouteStatus = 'served' | 'ready' | 'missing' | 'php'

export type HookStatus = 'done' | 'partial' | 'missing' | 'unknown'

export interface Counts {
  endpoints: number
  served: number
  ready: number
  missing: number
  /** Routes published in an SDK. */
  sdk: number
  sdk_served: number
  /** Endpoints with a benchmark scenario, skipped with a reason, or neither. */
  bench_covered: number
  bench_skipped: number
  bench_missing: number
}

export interface RunSummary {
  suite: string
  runtime: 'rust' | 'php'
  date: string
  commit: string | null
  tests: number
  passed: number
  failures: number
  errors: number
  skipped: number
}

export interface ServiceSummary {
  id: string
  title: string
  description: string
  sources: string[]
  registered: boolean
  status: ServiceStatus
  counts: Counts
  tests: { suites: string[]; classes: number; tests: number; rust: boolean; latest: RunSummary[] }
  bench: { date: string; file: string } | null
  /** The service's k6 script, when it has one. */
  bench_script: string | null
}

export interface Endpoint {
  method: string
  path: string
  aliases: { path: string; rust: RouteStatus }[]
  name: string | null
  desc: string
  groups: string[]
  scopes: string[]
  sdk: { method: string; visibility: 'visible' | 'hidden' | 'platforms' | 'preview'; deprecated: boolean }[]
  docs: boolean
  event: string | null
  labels: string[]
  params: number
  file: string
  line: number
  rust: RouteStatus
  rust_file: string | null
  rust_line: number | null
  bench: { status: 'covered' | 'skipped' | 'missing'; reason?: string }
}

export interface Suite {
  name: string
  dir: string
  tests: number
  classes: { name: string; file: string; tests: number }[]
  /** CI steps that run the suite through Traefik, against Rust. */
  rust: { workflow: string; job: string; line: number }[]
}

export interface Run extends RunSummary {
  dir: string
  endpoint: string
  branch: string | null
  command: string
  assertions: number
  /** Wall-clock seconds. */
  wall: number
  failed: { class: string; name: string; message: string }[]
  environment: {
    php: { container: string; image: string | null; id: string | null; created: string | null } | null
    rust: { container: string; image: string | null; id: string | null; created: string | null } | null
    processes: number | null
  }
}

export interface Latency {
  avg?: number
  p50?: number
  p90?: number
  p95?: number
  p99?: number
  max?: number
}

export interface BenchStep {
  target: 'php' | 'rust'
  mode: 'rate' | 'vus'
  level: number
  rps: number
  requests: number
  failures: number
  dropped: number
  latency: Latency
  cpu_ms_per_request: number
  db_cpu_ms_per_request: number
  memory_mib: { baseline: number; avg: number; peak: number }
  endpoints: Record<string, Latency & { route: string | null; note?: string; count: number | null; rps: number | null }>
}

export interface Benchmark {
  service: string
  file: string
  workload: { script: string; duration: string; hashing: boolean; seed?: number; users?: number; run: string }
  environment: {
    date: string
    commit: string | null
    branch: string | null
    machine: { os: string; arch: string; cpu: string | null; cores: number | null; memory_gib: number | null }
    docker: string | null
    k6: string | null
    cpu_source: string | null
    php: { container: string; image?: string; id?: string }
    rust: { container: string; image?: string; id?: string }
  }
  steps: BenchStep[]
}

export interface Hook {
  id: string
  title: string | null
  description: string
  kind: 'init' | 'shutdown' | 'error' | 'options' | 'wildcard' | 'resource'
  file: string
  line: number | null
  class?: string | null
  groups: string[]
  injects: string[]
  labels: string[]
  errors: string[]
  headers: string[]
  /** It runs for a route Rust serves today. */
  needed: boolean
  /** Described in crates/appwrite/hooks.json. */
  matched: boolean
  rust: { status: HookStatus; note: string | null; where: { file: string; anchor: string; line: number | null; found: boolean }[] }
}

export interface Router {
  name: string
  rule: string
  paths: string[]
  prefixes: string[]
  priority: string | null
  file: string
  line: number
  profile: string | null
}

export interface Migration {
  commit: string | null
  branch: string | null
  summary: {
    services: number
    rust: number
    partial: number
    php: number
    endpoints: number
    served: number
    ready: number
    missing: number
    sdk: number
    sdk_served: number
    bench_covered: number
    bench_skipped: number
    bench_missing: number
    suites: number
    tests: number
    rust_suites: number
    hooks: { total: number; needed: number; done: number; done_needed: number; partial: number; missing: number; missing_needed: number }
  }
  routing: Router[]
  rust: {
    crates: { dir: string; routes: number; registered: boolean }[]
    routes: number
    unmatched: { method: string; path: string; file: string; line: number; routed: boolean }[]
  }
  services: ServiceSummary[]
  details: Record<string, { endpoints: Endpoint[]; suites: Suite[]; results: Run[]; benchmarks: Benchmark[] }>
  hooks: Hook[]
  benchmarks: Benchmark[]
  unassigned_suites: { name: string; dir: string; tests: number; classes: number }[]
  warnings: string[]
}

const module = import.meta.glob<Migration>('../../data/appwrite.json', { import: 'default' })

export async function loadMigration(): Promise<Migration | null> {
  const load = Object.values(module)[0]
  return load ? load() : null
}

export const SERVICE_STATUS: Record<ServiceStatus, { label: string; note: string }> = {
  rust: { label: 'Rust', note: 'Traefik sends every route of the service to the Rust API.' },
  partial: { label: 'Partly Rust', note: 'Some routes are served by Rust, the rest by PHP.' },
  php: { label: 'PHP', note: 'Every route is still served by PHP.' },
}

export const ROUTE_STATUS: Record<RouteStatus, { label: string; note: string }> = {
  served: { label: 'Rust', note: 'Traefik sends it to the Rust API, which implements it.' },
  ready: { label: 'Rust, not routed', note: 'Rust implements it, but Traefik still sends it to PHP.' },
  missing: { label: 'Missing in Rust', note: 'Traefik sends it to Rust, which has no such route: it answers 404.' },
  php: { label: 'PHP', note: 'Served by PHP.' },
}

export const HOOK_STATUS: Record<HookStatus, { label: string; note: string }> = {
  done: { label: 'Done', note: 'appwrite-core reproduces it.' },
  partial: { label: 'Partly', note: 'appwrite-core reproduces part of it; the note says what is left.' },
  missing: { label: 'Not yet', note: 'Not reproduced in Rust.' },
  unknown: { label: 'Undescribed', note: 'Found in the code but not described in crates/appwrite/hooks.json yet.' },
}

export const KIND_LABEL: Record<Hook['kind'], string> = {
  init: 'Before the action',
  shutdown: 'After the response',
  error: 'On error',
  options: 'Preflight',
  wildcard: 'No route',
  resource: 'Request resource',
}

/** `45.2` → `45`, `0.82` → `0.8`. */
export const ms = (x: number | undefined) => (x === undefined ? '–' : x >= 100 ? x.toFixed(0) : x >= 10 ? x.toFixed(1) : x.toFixed(2))

/** The share `part / whole` as a whole percentage. */
export const percent = (part: number, whole: number) => (whole ? Math.round((part / whole) * 100) : 0)

/** Matching PHP and Rust steps of a benchmark, in ladder order. */
export function pairs(b: Benchmark) {
  const key = (s: BenchStep) => `${s.mode}:${s.level}`
  const out: { mode: 'rate' | 'vus'; level: number; php?: BenchStep; rust?: BenchStep }[] = []
  for (const s of b.steps) {
    let p = out.find((x) => `${x.mode}:${x.level}` === key(s))
    if (!p) {
      p = { mode: s.mode, level: s.level }
      out.push(p)
    }
    p[s.target] = s
  }
  return out.sort((a, b) => (a.mode === b.mode ? a.level - b.level : a.mode === 'rate' ? -1 : 1))
}

/** `rate 200` → `200 req/s`, `vus 50` → `50 users`. */
export const load = (mode: 'rate' | 'vus', level: number) => (mode === 'rate' ? `${level} req/s` : `${level} users, closed loop`)
