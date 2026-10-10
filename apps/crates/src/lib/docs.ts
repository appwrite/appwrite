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
  upstream?: { ref: string; head: string | null; commits: number; list: Commit[] } | null
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
  counts: { items: number; documented: number; examples: number; guide: boolean; php_symbols: number; linked: number }
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

export interface Library {
  library: LibrarySummary
  install: { cargo: string | null; composer: string | null }
  overview: string
  overview_notes: string
  guide: GuideBlock[]
  sync: Sync
  examples: Example[]
  modules: Module[]
  items: Item[]
  reexports: { name: string; source: string }[]
  php_api: PhpSymbol[]
}

const indexModule = import.meta.glob<Index>('../../data/index.json', { import: 'default' })
const libraryModules = import.meta.glob<Library>(['../../data/*.json', '!../../data/index.json'], { import: 'default' })

export async function loadIndex(): Promise<Index | null> {
  const load = Object.values(indexModule)[0]
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

/** The crate root's namespace page (`/$lib/root`). */
export const ROOT = 'root'

/** A namespace's page below `/$lib`: `json` for `json`, `a/b` for `a::b`, `root` for the crate root. */
export const moduleSplat = (path: string) => (path ? path.split('::').join('/') : ROOT)

/** Where an item is documented: its namespace page. */
export const itemSplat = (i: Pick<Item, 'module'>) => moduleSplat(i.module)

/** An item's anchor on its namespace page. */
export const itemHash = (name: string) => `item.${name}`

/** A method's anchor on its type's namespace page. */
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
    modules: lib.modules.map((m) => ({ ...m, doc: '', examples: [] as Example[] })),
    items: lib.items.map((i) => ({ ...i, doc: '', inherited_doc: null, signature: '', examples: [] as Example[] })),
    reexports: lib.reexports,
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

/** `2026-10-08T16:36:30+02:00` → `Oct 8, 2026`. */
export function day(date: string | null | undefined) {
  if (!date) return ''
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
}
