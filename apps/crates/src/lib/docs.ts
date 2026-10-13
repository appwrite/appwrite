// The JSON `bin/compat crates` writes to apps/crates/data (see
// crates/tools/compat/src/runner/crates.rs), loaded as Vite modules.

export type Status = 'complete' | 'progress' | 'started' | 'planned'

/** Whether a Rust crate follows its PHP library's latest changes. */
export type SyncState = 'synced' | 'behind' | 'planned' | 'unknown' | 'untracked'

export interface Commit {
  hash: string
  short: string
  date: string
  author: string
  subject: string
}

export interface Release {
  tag: string
  version: string
  date: string
}

export interface Sync {
  state: SyncState
  estimated?: boolean
  synced?: Commit
  latest?: Commit | null
  behind?: { commits: number; files: number; insertions: number; deletions: number; list: Commit[] }
  releases?: Release[]
  release?: Release | null
  /** `head`: the date of the ref's last commit; `fetched`: when the repository last fetched (older data leaves it out). */
  upstream?: { ref: string; head: string | null; fetched?: string | null; commits: number; list: Commit[] } | null
}

/**
 * When a library's parts last changed (commit dates; older data leaves it out): its compat cases and spec, its crate,
 * its benchmark results and its PHP package. `bench_stale`: the crate changed after the commit the benchmarks ran on.
 * `at`: the latest of them, the benchmark run included.
 */
export interface Updated {
  cases: string | null
  crate: string | null
  bench: string | null
  bench_stale: boolean | null
  php: string | null
  at: string | null
}

export interface LibrarySummary {
  slug: string
  title: string
  category: string
  description: string
  php_description?: string | null
  status: Status
  php: { package: string; source: 'monorepo' | 'composer'; dir: string; version: string | null } | null
  rust: { name: string; crate: string; dir: string; group: string; version: string } | null
  compat: { complete: boolean; operations: number; cases: number; summary: string } | null
  sync: { state: SyncState; estimated: boolean | null; behind: number | null; releases: number; upstream: number | null }
  counts: {
    items: number
    documented: number
    examples: number
    guide: boolean
    php_symbols: number
    linked: number
    /** The PHP library's tasks and how many the crate ports or waives (older data leaves it out). */
    tasks?: { total: number; ported: number; waived: number; pending: number }
    /**
     * How many dependencies of each kind (older data leaves it out). `php` counts the PHP package's `require`.
     * `dependents` counts every library depending on it, through either runtime, plus Appwrite when it does
     * (older data leaves `php_used_by`, `appwrite` and `dependents` out).
     */
    dependencies?: { rust: number; dev: number; build: number; used_by: number; php: number; php_used_by?: number; appwrite?: boolean; dependents?: number }
  }
  /**
   * Geometric means over the benchmarks, compat ones left out: `speedup` (PHP time / Rust time) and `cpu` (PHP CPU
   * time / Rust CPU time per call; null or absent when the results predate CPU measurement).
   */
  bench: { speedup: number; benchmarks: number; cpu?: number | null } | null
  updated?: Updated
}

export interface Category {
  id: string
  title: string
  description: string
  libraries: string[]
}

export interface Index {
  categories: Category[]
  libraries: LibrarySummary[]
  commit: string | null
  branch: string | null
  /** When `bin/compat crates` wrote the data (UTC), and the date of its commit (older data leaves them out). */
  generated_at?: string
  commit_date?: string | null
}

export interface Code {
  shown: string
  program: string
  attributes: string[]
}

export interface Example {
  title: string | null
  rust: Code | null
  php: Code | null
}

export interface Item {
  path: string
  module: string
  name: string
  owner: string | null
  kind: 'fn' | 'struct' | 'enum' | 'trait' | 'type' | 'const' | 'method'
  implements: string | null
  signature: string
  doc: string
  /** PHP mentions moved out of `doc`, for the migration view. */
  php_notes?: string
  inherits: string | null
  inherited_doc: string | null
  file: string
  line: number
  export: string | null
  examples: Example[]
  php: string[]
  /** A function's parts, for outlines: receiver (`&self`, or null for an associated function), parameters and return type. */
  function: { receiver: string | null; params: { name: string; type: string }[]; output: string; async: boolean } | null
}

export interface Module {
  path: string
  doc: string
  php_notes?: string
  examples: Example[]
  file: string
  public: boolean
}

export interface PhpSymbol {
  symbol: string
  class: string | null
  namespace: string
  name: string
  signature: string
  static: boolean
  params: { name: string; type: string; default: string | null; optional: boolean }[]
  returns: string
  doc: string
  class_doc: string
  file: string
  line: number
  rust: string[]
}

export type GuideBlock = { text: string } | { example: Example }

/** A `- `name`: description` entry of a task's documentation. */
export interface TaskEntry {
  name: string
  description: string
}

/** The PHP program a task is, as `compat coverage` discovers it. */
export interface PhpTask {
  /** The program's repository path, or `<package>/composer.json#<script>`. */
  id: string
  kind: 'bin' | 'script' | 'file'
  /** The package directory it runs from. */
  dir: string
  /** How PHP runs it, from the package directory. */
  command: string
  /** Composer scripts that run it. */
  scripts: { name: string; command: string }[]
}

/**
 * A program the library ships besides its API (a data import, a generator). A ported task is a
 * binary of the crate; a waived one has a reason; a pending one is PHP only.
 */
export type Task =
  | {
      name: string
      status: 'ported'
      bin: string
      package: string
      /** `cargo run -p <package> --bin <bin>`. */
      command: string
      summary: string
      /** Shell examples from the binary's docs. */
      usage: string[]
      /** The rest of its docs, as Markdown. */
      doc: string
      commands: TaskEntry[]
      arguments: TaskEntry[]
      environment: TaskEntry[]
      exit_codes: TaskEntry[]
      php_notes: string
      /** Repository paths of the files it writes. */
      writes: string[]
      /** Case files that run it on both runtimes. */
      cases: string[]
      php: PhpTask
    }
  | { name: string; status: 'waived'; reason: string; php: PhpTask }
  | { name: string; status: 'pending'; php: PhpTask }

/** A task's anchor on its library's page. */
export const taskHash = (name: string) => `task.${name}`

/**
 * One runtime's timing of a benchmark (`compat bench`; tests/compat/README.md#benchmarks): wall nanoseconds per
 * call (median, fastest and slowest sample). Files written since the drivers measure CPU and memory add `cpu`
 * (process CPU ns per call), `peak` (most heap held above the start of the samples, bytes), `retained` (heap
 * growth per call, bytes) and `rss` (the driver's peak resident memory so far, bytes); older files leave them out.
 * Rust also counts heap `allocations` and `bytes` allocated per call (Rust's `peak` and `retained` need it too).
 */
export interface Timing {
  ns: number
  min: number
  max: number
  samples: number
  calls: number
  cpu?: number
  peak?: number
  retained?: number
  rss?: number
  allocations?: number
  bytes?: number
}

export interface Bench {
  lib: string
  environment: {
    date: string
    commit: string
    machine: { os: string; arch: string; cpu: string }
    // `image`, `os` and `kernel` (and Rust's `runs`) since benchmarks run both
    // drivers in containers; older files leave them out (Rust ran on the host).
    php: { version: string; opcache: boolean; jit: string; runs: string; image?: string | null; os?: string; kernel?: string }
    rust: { version: string; profile: string; runs?: string; image?: string | null; os?: string; kernel?: string }
  }
  /**
   * `bound`: why the benchmark is bound by work both runtimes wait on equally (it must reach parity, not win).
   * `compat`: why it times a PHP-compatibility codec no Rust hot path uses (reported, never gated, left out of the mean).
   */
  results: { name: string; description: string | null; php: Timing; rust: Timing; speedup: number; bound?: string | null; compat?: string | null }[]
}

/** `1234.5` ns → `1.23 µs`, `2594060000` ns → `2.59 s`: three significant digits in the largest unit that fits. */
export function duration(ns: number) {
  const [v, u] = ns >= 1e9 ? [ns / 1e9, 's'] : ns >= 1e6 ? [ns / 1e6, 'ms'] : ns >= 1e3 ? [ns / 1e3, 'µs'] : [ns, 'ns']
  return `${v.toFixed(v >= 100 ? 0 : v >= 10 ? 1 : u === 'ns' && v >= 1 ? 1 : 2)} ${u}`
}

/** `2.167` → `2.2×`. */
export const times = (x: number) => `${x >= 10 ? Math.round(x) : x.toFixed(1)}×`

/** `1536` → `1.50 KiB`: binary units, three significant digits. */
export function bytes(b: number) {
  const units = ['B', 'KiB', 'MiB', 'GiB']
  let v = Math.abs(b)
  let u = 0
  while (v >= 1024 && u < units.length - 1) {
    v /= 1024
    u++
  }
  const n = u === 0 ? Math.round(v).toString() : v.toFixed(v >= 100 ? 0 : v >= 10 ? 1 : 2)
  return `${b < 0 ? '-' : ''}${n} ${units[u]}`
}

/** A dependency of the Rust crate, from its `Cargo.toml` (workspace entries resolved). */
export interface RustDependency {
  /** The key in the manifest. */
  name: string
  /** The crate's name on crates.io (differs from `name` when renamed). */
  package: string
  kind: 'normal' | 'dev' | 'build'
  /** A `[target.'cfg(…)'.dependencies]` table's target. */
  target: string | null
  requirement: string | null
  /** Repository path of a workspace member. */
  path: string | null
  git: string | null
  workspace: boolean
  optional: boolean
  default_features: boolean
  features: string[]
  /** The crate features that turn an optional dependency on. */
  enabled_by: string[]
  /** The version `Cargo.lock` resolved. */
  locked: string | null
  /** The library's slug, for another Utopia crate of this repository. */
  utopia: string | null
  /** The comment above the entry, in the crate's manifest or the workspace's. */
  note: string | null
}

/** A requirement of the PHP package's `composer.json`. */
export interface PhpDependency {
  name: string
  kind: 'normal' | 'dev'
  requirement: string
  /** `php`, `ext-*` and `lib-*`: the runtime, not a package. */
  platform: boolean
  locked: string | null
  utopia: string | null
}

export interface Dependencies {
  rust: RustDependency[]
  dev: RustDependency[]
  build: RustDependency[]
  /** The Utopia crates that depend on this one, and how. */
  used_by: { slug: string; kinds: RustDependency['kind'][] }[]
  /** The Utopia PHP packages requiring this library's package (older data leaves it out). */
  php_used_by?: { slug: string; kinds: PhpDependency['kind'][] }[]
  /** How Appwrite depends on it, when it does (older data leaves it out). */
  appwrite?: AppwriteUse | null
  php: PhpDependency[]
}

/** Appwrite's use of a library: its Rust crates (`crates/appwrite/*`) and its PHP server. */
export interface AppwriteUse {
  rust: { crate: string; dir: string; kinds: RustDependency['kind'][] }[]
  /**
   * `require`: the root `composer.json` requires the package. `import`: the server's code imports it
   * without requiring it (a package of this monorepo, wired by the root autoload). `files` counts the
   * PHP files in `app/` and `src/` importing it.
   */
  php: { via: 'require' | 'import'; kinds: PhpDependency['kind'][]; files: number } | null
}

/** A library, or Appwrite itself, in the ecosystem's dependency graph. */
export interface GraphNode {
  id: string
  kind: 'library' | 'app'
  title: string
  category: string | null
  status: Status | null
  description: string
  /** The Rust package name. */
  rust: string | null
  /** The PHP package name. */
  php: string | null
  /** Appwrite's crates. */
  crates?: string[]
}

/** `source` depends on `target` through one runtime; `kinds` holds `normal`, `dev` and `build`. */
export interface GraphEdge {
  source: string
  target: string
  runtime: 'rust' | 'php'
  kinds: ('normal' | 'dev' | 'build')[]
  /** Appwrite's crates behind a Rust edge. */
  crates?: string[]
  /** How Appwrite's PHP server depends on it. */
  via?: 'require' | 'import'
}

export interface Graph {
  nodes: GraphNode[]
  edges: GraphEdge[]
}

/** The graph's id for Appwrite. */
export const APPWRITE = 'appwrite'

export interface Library {
  library: LibrarySummary
  bench: Bench | null
  install: { cargo: string | null; composer: string | null }
  overview: string
  overview_notes: string
  guide: GuideBlock[]
  /** Older data leaves it out. */
  tasks?: Task[]
  /** Older data leaves it out. */
  dependencies?: Dependencies
  sync: Sync
  examples: Example[]
  modules: Module[]
  items: Item[]
  reexports: { name: string; source: string }[]
  php_api: PhpSymbol[]
}

const indexModule = import.meta.glob<Index>('../../data/index.json', { import: 'default' })
// `appwrite.json` is the Appwrite migration (src/lib/appwrite.ts) and `graph.json` the ecosystem's dependencies, not libraries.
const libraryModules = import.meta.glob<Library>(['../../data/*.json', '!../../data/index.json', '!../../data/appwrite.json', '!../../data/graph.json'], { import: 'default' })
const graphModule = import.meta.glob<Graph>('../../data/graph.json', { import: 'default' })

export async function loadIndex(): Promise<Index | null> {
  const load = Object.values(indexModule)[0]
  return load ? load() : null
}

/** The ecosystem's dependency graph; older data has none. */
export async function loadGraph(): Promise<Graph | null> {
  const load = Object.values(graphModule)[0]
  return load ? load() : null
}

export async function loadLibrary(slug: string): Promise<Library | null> {
  const load = libraryModules[`../../data/${slug}.json`]
  return load ? load() : null
}

export const STATUS: Record<Status, { label: string; note: string }> = {
  complete: { label: 'Converted', note: 'Every PHP behaviour is matched in Rust and proven by bin/compat.' },
  progress: { label: 'In progress', note: 'The Rust crate is being checked against PHP; the gaps left are listed.' },
  started: { label: 'Started', note: 'A Rust crate exists, but it is not compared with PHP yet.' },
  planned: { label: 'Planned', note: 'Available in PHP; the Rust conversion has not started.' },
}

export const isTopLevel = (i: Item) => i.kind !== 'method'

/** The crate root's module page (`/$lib/root`). */
export const ROOT = 'root'

/** A module's page below `/$lib`: `json` for `json`, `a/b` for `a::b`, `root` for the crate root. */
export const moduleSplat = (path: string) => (path ? path.split('::').join('/') : ROOT)

/** Where an item is documented: its module page. */
export const itemSplat = (i: Pick<Item, 'module'>) => moduleSplat(i.module)

/** An item's anchor on its module page. */
export const itemHash = (name: string) => `item.${name}`

/** A method's anchor on its type's module page. */
export const methodHash = (owner: string, name: string) => `method.${owner}.${name}`

export const KIND_TITLE: Record<string, string> = {
  struct: 'Structs',
  enum: 'Enums',
  trait: 'Traits',
  fn: 'Functions',
  type: 'Type aliases',
  const: 'Constants',
}
export const KIND_LABEL: Record<string, string> = { struct: 'Struct', enum: 'Enum', trait: 'Trait', fn: 'Function', type: 'Type alias', const: 'Constant', method: 'Method' }
export const KIND_ORDER = ['struct', 'enum', 'trait', 'fn', 'type', 'const'] as const

/** The first paragraph of a doc comment, as plain text. */
export function summary(doc: string | null | undefined) {
  return String(doc ?? '')
    .split(/\n\s*\n/)[0]
    .replace(/\n/g, ' ')
    .replace(/\[`([^`]+)`\]/g, '`$1`')
    .trim()
}

/** The path a user imports an item by. */
export function usePath(l: LibrarySummary, i: Item) {
  const crate = l.rust?.crate ?? l.slug
  return `${crate}::${i.export ?? (i.module ? `${i.module}::${i.name}` : i.name)}`
}

export function sourceUrl(ref: string | null | undefined, file: string, line?: number) {
  return `https://github.com/appwrite/appwrite/blob/${ref || 'main'}/${file}${line ? `#L${line}` : ''}`
}

/** Where an intra-doc link (`` [`Validator::description`] ``) points in this library. */
export function resolve(lib: Pick<Library, 'items' | 'modules'>, target: string): { splat: string; hash?: string } | null {
  const clean = target.replace(/\(\)$/, '').replace(/^(crate|self|Self)::/, '')
  if (!clean || clean.includes(' ')) return null
  const parts = clean.split('::')
  const top = lib.items.filter(isTopLevel)
  const find = (segments: string[]) => {
    const name = segments[segments.length - 1]
    const module = segments.slice(0, -1).join('::')
    return (
      top.find((i) => i.name === name && (module === '' ? i.export === name || i.module === '' : i.module === module || i.module.endsWith(`::${module}`))) ??
      (module === '' ? top.find((i) => i.name === name) : undefined)
    )
  }
  const item = find(parts)
  if (item) return { splat: itemSplat(item), hash: itemHash(item.name) }
  if (parts.length >= 2) {
    const owner = find(parts.slice(0, -1))
    const method = parts[parts.length - 1]
    if (owner)
      return {
        splat: itemSplat(owner),
        hash: lib.items.some((i) => i.owner === owner.name && i.name === method) ? methodHash(owner.name, method) : itemHash(owner.name),
      }
  }
  const module = lib.modules.find((m) => m.path === clean)
  return module?.path ? { splat: moduleSplat(module.path) } : null
}

/** The library without doc text: enough for navigation and link resolution. */
export function navigation(lib: Library) {
  return {
    library: lib.library,
    install: lib.install,
    modules: lib.modules.map((m) => ({ ...m, summary: summary(m.doc), doc: '', examples: [] as Example[] })),
    items: lib.items.map((i) => ({ ...i, doc: '', inherited_doc: null, signature: '', examples: [] as Example[] })),
    reexports: lib.reexports,
    tasks: (lib.tasks ?? []).map((t) => ({ name: t.name, status: t.status, bin: t.status === 'ported' ? t.bin : null })),
    phpClasses: [...new Set(lib.php_api.map((p) => p.class ?? p.namespace))].filter(Boolean) as string[],
  }
}
export type Navigation = ReturnType<typeof navigation>

/** A PHP docblock's description: the text before its `@tags`, without indentation Markdown would read as code. */
export function phpDescription(doc: string | null | undefined) {
  const lines: string[] = []
  for (const line of String(doc ?? '').split('\n')) {
    if (/^\s*@\w/.test(line)) break
    lines.push(line.trim())
  }
  return lines.join('\n').trim()
}

/** A heading's anchor: `Read a connection string` → `read-a-connection-string`. */
export function slug(text: string) {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

/** The `##` headings of a guide, for the page's table of contents. */
export function guideHeadings(guide: GuideBlock[]) {
  return guide.flatMap((b) => ('text' in b ? b.text.split('\n').filter((l) => l.startsWith('## ')).map((l) => l.slice(3).trim()) : []))
}

export const commitUrl = (hash: string) => `https://github.com/appwrite/appwrite/commit/${hash}`
export const releaseUrl = (slug: string, version: string) => `https://github.com/utopia-php/${slug}/releases/tag/${version}`
