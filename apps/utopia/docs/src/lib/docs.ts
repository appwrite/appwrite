// The JSON `bun run generate` (scripts/generate.ts) writes to apps/utopia/docs/data, loaded as Vite modules.

/**
 * Where a library is in the move from PHP to Rust, read from the utopia PHP extension (crates/extension):
 * `rust`: the extension always serves it and no PHP class of it is left; `partial`: the extension always
 * serves some of its classes and the rest are still PHP; `switchable`: the extension takes its PHP classes
 * over when switched on; `planned`: PHP only.
 */
export type Status = 'rust' | 'partial' | 'switchable' | 'planned'

/** Lines of source: code, comments (doc comments included) and blank lines. */
export interface Lines {
  total: number
  code: number
  comments: number
  blank: number
  files: number
}

/** A library's size: its crate's `src/` and what is left of its PHP package's `src/`. */
export interface Size {
  rust: Lines | null
  php: Lines | null
}

export interface LibrarySummary {
  slug: string
  title: string
  category: string
  description: string
  status: Status
  /** The PHP package: `dir` is null once the whole package is removed, `version` set for one Composer installs. */
  php: { package: string; dir: string | null; version: string | null } | null
  rust: { name: string; crate: string; dir: string; version: string; docs: string } | null
  /** PHP classes the extension serves from Rust, and classes of the package still written in PHP (null when its source is not in this repository). */
  classes: { rust: number; php: number | null }
  counts: {
    items: number
    documented: number
    examples: number
    guide: boolean
    dependencies: { rust: number; dev: number; build: number; used_by: number }
    /** Files of the Appwrite server importing the library. */
    appwrite: number
  }
  size: Size
  /** The date of the last commit to the crate, or to the PHP package without one. */
  updated: string | null
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
  generated_at: string
  commit_date: string | null
  /** Lines across the ecosystem: every crate, and every PHP package's remaining source. */
  size: { rust: Lines; php: Lines }
  /** PHP classes served from Rust, and classes still written in PHP, across the libraries in this repository. */
  classes: { rust: number; php: number }
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
  inherits: string | null
  inherited_doc: string | null
  file: string
  line: number
  export: string | null
  examples: Example[]
  /** A function's parts, for outlines: receiver (`&self`, or null for an associated function), parameters and return type. */
  function: { receiver: string | null; params: { name: string; type: string }[]; output: string; async: boolean } | null
}

export interface Module {
  path: string
  doc: string
  examples: Example[]
  file: string
  public: boolean
}

export type GuideBlock = { text: string } | { example: Example }

/** A dependency of the Rust crate, from `cargo metadata`. */
export interface RustDependency {
  /** The key in the manifest. */
  name: string
  /** The crate's name on crates.io (differs from `name` when renamed). */
  package: string
  kind: 'normal' | 'dev' | 'build'
  /** A `[target.'cfg(…)'.dependencies]` table's target. */
  target: string | null
  requirement: string | null
  optional: boolean
  /** The crate features that turn an optional dependency on. */
  enabled_by: string[]
  default_features: boolean
  features: string[]
  /** The version `Cargo.lock` resolved. */
  locked: string | null
  /** The library's slug, for another Utopia crate of this repository. */
  utopia: string | null
}

export interface Dependencies {
  rust: RustDependency[]
  dev: RustDependency[]
  build: RustDependency[]
  /** The Utopia crates that depend on this one, and how. */
  used_by: { slug: string; kinds: RustDependency['kind'][] }[]
  /** The parts of Appwrite whose files import the library, with how many do. */
  appwrite: { kind: AppwriteComponent['kind']; name: string; files: number }[]
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
  /** Appwrite's parts: its API modules, workers, schedulers, realtime and MQTT servers, each with the libraries its files import. */
  components?: AppwriteComponent[]
}

/** A part of Appwrite and the libraries it is built from, each with the number of its files importing it. */
export interface AppwriteComponent {
  kind: 'http' | 'worker' | 'schedule' | 'realtime' | 'mqtt'
  name: string
  libraries: Record<string, number>
}

/** `source` depends on `target`: `rust` through Cargo, `php` through Composer or an import. */
export interface GraphEdge {
  source: string
  target: string
  runtime: 'rust' | 'php'
  kinds: ('normal' | 'dev' | 'build')[]
}

export interface Graph {
  nodes: GraphNode[]
  edges: GraphEdge[]
}

/** The graph's id for Appwrite. */
export const APPWRITE = 'appwrite'

export interface Library {
  library: LibrarySummary
  install: { cargo: string }
  overview: string
  guide: GuideBlock[]
  dependencies: Dependencies
  /** The PHP classes the extension serves from this crate, and the package's classes still written in PHP. */
  classes: { rust: string[]; php: string[] }
  examples: Example[]
  modules: Module[]
  items: Item[]
  reexports: { name: string; source: string }[]
}

const indexModule = import.meta.glob<Index>('../../data/index.json', { import: 'default' })
// `graph.json` is the ecosystem's dependencies, not a library.
const libraryModules = import.meta.glob<Library>(['../../data/*.json', '!../../data/index.json', '!../../data/graph.json'], { import: 'default' })
const graphModule = import.meta.glob<Graph>('../../data/graph.json', { import: 'default' })

export async function loadIndex(): Promise<Index | null> {
  const load = Object.values(indexModule)[0]
  return load ? load() : null
}

export async function loadGraph(): Promise<Graph | null> {
  const load = Object.values(graphModule)[0]
  return load ? load() : null
}

export async function loadLibrary(slug: string): Promise<Library | null> {
  const load = libraryModules[`../../data/${slug}.json`]
  return load ? load() : null
}

export const STATUS: Record<Status, { label: string; note: string }> = {
  rust: { label: 'Rust', note: 'Appwrite runs the Rust crate: the PHP extension serves the library and none of its PHP is left.' },
  partial: { label: 'Partly Rust', note: 'Appwrite runs some of its classes from the Rust crate; the rest are still PHP.' },
  switchable: { label: 'Rust, switchable', note: 'The Rust crate serves the PHP classes when switched on; the PHP source is still there to fall back to.' },
  planned: { label: 'PHP', note: 'Written in PHP; no Rust crate yet.' },
}

/** Furthest along first. */
export const STATUSES: Status[] = ['rust', 'partial', 'switchable', 'planned']

/**
 * How many of the PHP libraries have a Rust crate behind them (partly or switchable included), of how
 * many PHP libraries there are. Crates with no PHP library (php-std) are not part of the move.
 */
export function progress(libraries: Pick<LibrarySummary, 'status' | 'php'>[]) {
  const php = libraries.filter((l) => l.php)
  return { rust: php.filter((l) => l.status !== 'planned').length, total: php.length }
}

/** `13 of 52 libraries on Rust`. */
export function progressLine(libraries: Pick<LibrarySummary, 'status' | 'php'>[]) {
  const p = progress(libraries)
  return `${p.rust} of ${p.total} libraries on Rust`
}

export const isTopLevel = (i: Pick<Item, 'kind'>) => i.kind !== 'method'

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
export function resolve(lib: { items: Pick<Item, 'name' | 'module' | 'owner' | 'kind' | 'export'>[]; modules: Pick<Module, 'path'>[] }, target: string): { splat: string; hash?: string } | null {
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

/** `12345` → `12,345`. */
export const formatCount = (n: number) => n.toLocaleString('en-US')

/** The library without doc text: enough for navigation and link resolution. */
export function navigation(lib: Library) {
  return {
    library: lib.library,
    install: lib.install,
    modules: lib.modules.map((m) => ({ ...m, summary: summary(m.doc), doc: '', examples: [] as Example[] })),
    items: lib.items.map((i) => ({ ...i, doc: '', inherited_doc: null, signature: '', examples: [] as Example[] })),
    reexports: lib.reexports,
  }
}
export type Navigation = ReturnType<typeof navigation>

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
