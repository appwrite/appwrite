/// <reference types="bun" />
/**
 * Writes the site's data (data/index.json, data/<library>.json, data/graph.json) from the repository:
 *
 * - the crates and their dependencies: each crate's `Cargo.toml`, with the workspace's filling `.workspace = true`;
 * - each crate's API reference: the rustdoc JSON docs.rs built for its published version;
 * - guides: `guide.md` next to each crate's `Cargo.toml`;
 * - where each library is in the move from PHP: the utopia PHP extension (crates/extension), whose
 *   `stable("<library>")` and `enabled("<library>")` gates say what Rust serves, and
 *   `tests/libraries.expected`, the PHP classes it registers;
 * - what is still PHP: `packages/<library>/src` and the `utopia-php/*` packages in `composer.lock`;
 * - which parts of Appwrite use which library: the `use Utopia\...` imports under `app/` and `src/`.
 *
 * See src/lib/docs.ts for the shapes.
 */
import { $ } from 'bun'
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import type {
  AppwriteComponent,
  Category,
  Code,
  Dependencies,
  Example,
  Graph,
  GuideBlock,
  Index,
  Item,
  Library,
  LibrarySummary,
  Lines,
  Module,
  RustDependency,
  Status,
} from '../src/lib/docs'

const app = join(import.meta.dir, '..')
const root = join(app, '../../..')
const out = join(app, 'data')
const cache = join(app, 'node_modules/.cache/rustdoc')
/** The rustdoc JSON schema this script reads. docs.rs builds on nightly, so a new build can move it. */
const FORMAT = 61

const read = (path: string) => readFileSync(join(root, path), 'utf8')
const json = <T>(path: string) => JSON.parse(read(path)) as T
const exists = (path: string) => existsSync(join(root, path))

/** Every file under `dir` (repository path) ending in `extension`, as repository paths. */
function files(dir: string, extension: string): string[] {
  if (!exists(dir)) return []
  const found: string[] = []
  for (const entry of readdirSync(join(root, dir), { withFileTypes: true })) {
    const path = `${dir}/${entry.name}`
    if (entry.isDirectory()) found.push(...files(path, extension))
    else if (entry.name.endsWith(extension)) found.push(path)
  }
  return found.sort()
}

/** A PHP package's library source: its `src/`, without PHPStan extensions, which are tooling for its users' static analysis. */
const sources = (dir: string) => files(`${dir}/src`, '.php').filter((f) => !f.includes('/PHPStan/'))

const git = async (...args: string[]) => (await $`git -C ${root} ${args}`.quiet().nothrow().text()).trim()

// --- Text -------------------------------------------------------------------------------------------------

/** `user-agent` → `User agent`, `http` → `HTTP`. */
function title(slug: string) {
  const named: Record<string, string> = { openapi: 'OpenAPI', psr7: 'PSR-7', 'php-std': 'PHP standard library' }
  if (named[slug]) return named[slug]
  if (['http', 'dns', 'dsn', 'cdn', 'cli', 'smtp', 'mqtt', 'nats', 'vcs', 'di'].includes(slug)) return slug.toUpperCase()
  const words = slug.split('-').join(' ')
  return words[0].toUpperCase() + words.slice(1)
}

/** Lines of code, comments and blanks in Rust or PHP sources. */
function count(paths: string[]): Lines {
  const lines: Lines = { total: 0, code: 0, comments: 0, blank: 0, files: paths.length }
  for (const path of paths) {
    let block = false
    for (const raw of read(path).split('\n')) {
      const line = raw.trim()
      lines.total++
      if (block) {
        lines.comments++
        if (line.includes('*/')) block = false
      } else if (!line) lines.blank++
      else if (line.startsWith('//') || line.startsWith('#!') || (path.endsWith('.php') && line.startsWith('#') && !line.startsWith('#['))) lines.comments++
      else if (line.startsWith('/*')) {
        lines.comments++
        block = !line.includes('*/')
      } else lines.code++
    }
  }
  return lines
}

const sum = (all: Lines[]): Lines =>
  all.reduce((a, b) => ({ total: a.total + b.total, code: a.code + b.code, comments: a.comments + b.comments, blank: a.blank + b.blank, files: a.files + b.files }), {
    total: 0,
    code: 0,
    comments: 0,
    blank: 0,
    files: 0,
  })

// --- Examples: a doc comment or guide as Markdown text and runnable examples -----------------------------

const RUSTDOC = ['rust', 'no_run', 'should_panic', 'edition2018', 'edition2021', 'edition2024']

function language(attributes: string[]): 'rust' | 'php' | 'other' {
  if (attributes.includes('php')) return 'php'
  if (attributes.some((a) => a === 'ignore' || a === 'compile_fail' || a === 'text')) return 'other'
  return attributes.every((a) => RUSTDOC.includes(a)) ? 'rust' : 'other'
}

/** rustdoc's rendering of a doctest (`# ` lines hidden), and the complete program a reader copies. */
function rust(body: string, attributes: string[]): Code {
  const shown: string[] = []
  const program: string[] = []
  for (const line of body.split('\n')) {
    const text = line.trimStart()
    const indent = line.slice(0, line.length - text.length)
    if (text === '#') program.push('')
    else if (text.startsWith('# ')) program.push(indent + text.slice(2))
    else {
      const kept = text.startsWith('##') ? indent + text.slice(1) : line
      shown.push(kept)
      program.push(kept)
    }
  }
  let code = program.join('\n')
  if (!code.includes('fn main')) {
    const head = program.filter((l) => l.startsWith('#![') || l.startsWith('extern crate'))
    const rest = program.filter((l) => !head.includes(l))
    const fallible = rest.some((l) => l.trimStart().startsWith('Ok::<'))
    const main = fallible ? 'fn main() -> Result<(), Box<dyn std::error::Error>> {' : 'fn main() {'
    code = `${head.length ? `${head.join('\n')}\n\n` : ''}${main}\n${rest.map((l) => (l ? `    ${l}` : '')).join('\n')}\n}`
  }
  return { shown: shown.join('\n').trim(), program: `${code.trim()}\n`, attributes }
}

function php(body: string): Code {
  const shown = body.trim()
  return { shown, program: shown.startsWith('<?php') ? `${shown}\n` : `<?php\n\nrequire __DIR__ . '/vendor/autoload.php';\n\n${shown}\n`, attributes: [] }
}

/** Text and examples in the order written. A PHP block right after a Rust one is the same example in PHP. */
function blocks(doc: string): GuideBlock[] {
  const result: GuideBlock[] = []
  let prose: string[] = []
  const flush = () => {
    const text = prose
      .join('\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim()
    if (text) result.push({ text })
    prose = []
  }
  /** A short lead-in ending with `:` right before the code becomes its title. */
  const caption = () => {
    while (prose.length && !prose[prose.length - 1].trim()) prose.pop()
    const last = prose[prose.length - 1]?.trim()
    if (!last || !last.endsWith(':') || last.length > 120 || last.startsWith('#') || last.startsWith('|')) return null
    prose.pop()
    return last.slice(0, -1)
  }
  const lines = doc.split('\n')
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    if (!line.trimStart().startsWith('```')) {
      prose.push(line)
      continue
    }
    const attributes = line
      .trimStart()
      .slice(3)
      .split(',')
      .map((a) => a.trim())
      .filter(Boolean)
    const body: string[] = []
    for (i++; i < lines.length && !lines[i].trimStart().startsWith('```'); i++) body.push(lines[i])
    const code = body.join('\n')
    const kind = language(attributes)
    if (kind === 'other') {
      prose.push(line, ...body, '```')
      continue
    }
    const last = result[result.length - 1]
    if (kind === 'php' && prose.every((l) => !l.trim()) && last && 'example' in last && last.example.rust && !last.example.php) {
      prose = []
      last.example.php = php(code)
      continue
    }
    const heading = caption()
    flush()
    result.push({ example: { title: heading, rust: kind === 'rust' ? rust(code, attributes) : null, php: kind === 'php' ? php(code) : null } })
  }
  flush()
  return result
}

/** The doc text without its examples, and the examples. */
function split(doc: string | null): { text: string; examples: Example[] } {
  const all = blocks(doc ?? '')
  return { text: all.flatMap((b) => ('text' in b ? [b.text] : [])).join('\n\n'), examples: all.flatMap((b) => ('example' in b ? [b.example] : [])) }
}

// --- rustdoc JSON -----------------------------------------------------------------------------------------

/* eslint-disable @typescript-eslint/no-explicit-any */
type Json = any

interface Rustdoc {
  root: number
  crate_version: string
  format_version: number
  index: Record<string, Json>
}

/** The rustdoc JSON of a published crate, from docs.rs (its build of that version, else of the latest); null when unpublished. */
async function rustdoc(name: string, version: string): Promise<Rustdoc | null> {
  mkdirSync(cache, { recursive: true })
  const cached = join(cache, `${name}-${version}.json`)
  if (!existsSync(cached)) {
    let response = await fetch(`https://docs.rs/crate/${name}/${version}/json`)
    if (response.status === 404) response = await fetch(`https://docs.rs/crate/${name}/latest/json`)
    if (response.status === 404) return null
    if (!response.ok) throw new Error(`docs.rs has no rustdoc JSON for ${name} ${version}: ${response.status}`)
    writeFileSync(cached, Bun.zstdDecompressSync(new Uint8Array(await response.arrayBuffer())))
  }
  const doc = JSON.parse(readFileSync(cached, 'utf8')) as Rustdoc
  if (doc.format_version !== FORMAT) {
    rmSync(cached)
    throw new Error(`${name}: rustdoc JSON format ${doc.format_version}, this script reads ${FORMAT}. Update the printers in scripts/generate.ts.`)
  }
  return doc
}

/** The day crates.io published this version of a crate. */
async function released(name: string, version: string): Promise<string | null> {
  const response = await fetch(`https://crates.io/api/v1/crates/${name}/${version}`, { headers: { 'User-Agent': 'utopia-docs (https://github.com/appwrite/appwrite)' } })
  return response.ok ? ((await response.json()) as Json).version.created_at.slice(0, 10) : null
}

const path = (p: Json) => `${p.path.split('::').pop()}${args(p.args)}`

function args(a: Json): string {
  if (!a) return ''
  if (a.angle_bracketed) {
    const list = [
      ...a.angle_bracketed.args.map((g: Json) => (g === 'infer' ? '_' : g.lifetime ?? (g.type ? type(g.type) : g.const?.expr ?? '_'))),
      ...a.angle_bracketed.constraints.map((c: Json) => `${c.name}${args(c.args)}${c.binding.equality ? ` = ${c.binding.equality.type ? type(c.binding.equality.type) : c.binding.equality.constant.expr}` : `: ${bounds(c.binding.constraint)}`}`),
    ]
    return list.length ? `<${list.join(', ')}>` : ''
  }
  if (a.parenthesized) return `(${a.parenthesized.inputs.map(type).join(', ')})${a.parenthesized.output ? ` -> ${type(a.parenthesized.output)}` : ''}`
  return ''
}

const bounds = (list: Json[]) =>
  list
    .map((b) => (b.trait_bound ? `${b.trait_bound.modifier === 'maybe' ? '?' : ''}${path(b.trait_bound.trait)}` : b.outlives ?? ''))
    .filter(Boolean)
    .join(' + ')

function type(t: Json): string {
  if (t.resolved_path) return path(t.resolved_path)
  if (t.generic) return t.generic
  if (t.primitive) return t.primitive
  if (t.borrowed_ref) return `&${t.borrowed_ref.lifetime ? `${t.borrowed_ref.lifetime} ` : ''}${t.borrowed_ref.is_mutable ? 'mut ' : ''}${type(t.borrowed_ref.type)}`
  if (t.tuple) return `(${t.tuple.map(type).join(', ')})`
  if (t.slice) return `[${type(t.slice)}]`
  if (t.array) return `[${type(t.array.type)}; ${t.array.len}]`
  if (t.impl_trait) return `impl ${bounds(t.impl_trait)}`
  if (t.dyn_trait) return `dyn ${[...t.dyn_trait.traits.map((p: Json) => path(p.trait)), ...(t.dyn_trait.lifetime ? [t.dyn_trait.lifetime] : [])].join(' + ')}`
  if (t.raw_pointer) return `*${t.raw_pointer.is_mutable ? 'mut' : 'const'} ${type(t.raw_pointer.type)}`
  if (t.qualified_path) return `${t.qualified_path.trait ? `<${type(t.qualified_path.self_type)} as ${path(t.qualified_path.trait)}>` : type(t.qualified_path.self_type)}::${t.qualified_path.name}${args(t.qualified_path.args)}`
  if (t.function_pointer) return `fn(${t.function_pointer.sig.inputs.map(([, input]: [string, Json]) => type(input)).join(', ')})${t.function_pointer.sig.output ? ` -> ${type(t.function_pointer.sig.output)}` : ''}`
  if (t.pat) return type(t.pat.type)
  return '_'
}

/** `<'a, T: Clone, const N: usize>`, without the parameters `impl Trait` arguments add. */
function generics(g: Json): string {
  const list = g.params
    .filter((p: Json) => !p.kind.type?.is_synthetic)
    .map((p: Json) => {
      if (p.kind.lifetime) return `${p.name}${p.kind.lifetime.outlives.length ? `: ${p.kind.lifetime.outlives.join(' + ')}` : ''}`
      if (p.kind.const) return `const ${p.name}: ${type(p.kind.const.type)}`
      return `${p.name}${p.kind.type.bounds.length ? `: ${bounds(p.kind.type.bounds)}` : ''}${p.kind.type.default ? ` = ${type(p.kind.type.default)}` : ''}`
    })
  return list.length ? `<${list.join(', ')}>` : ''
}

function where(g: Json): string {
  const list = g.where_predicates.flatMap((w: Json) => (w.bound_predicate ? [`${type(w.bound_predicate.type)}: ${bounds(w.bound_predicate.bounds)}`] : w.lifetime_predicate ? [`${w.lifetime_predicate.lifetime}: ${w.lifetime_predicate.outlives.join(' + ')}`] : []))
  return list.length ? `\nwhere\n    ${list.join(',\n    ')}` : ''
}

/** `self`, `&self`, `&mut self`, or `self: Type` for another receiver. */
function receiver(t: Json): string {
  if (t.generic === 'Self') return 'self'
  if (t.borrowed_ref?.type.generic === 'Self') return `&${t.borrowed_ref.lifetime ? `${t.borrowed_ref.lifetime} ` : ''}${t.borrowed_ref.is_mutable ? 'mut ' : ''}self`
  return `self: ${type(t)}`
}

function fn(item: Json, visibility: string): Pick<Item, 'signature' | 'function'> {
  const f = item.inner.function
  const [first, ...rest] = f.sig.inputs as [string, Json][]
  const self = first?.[0] === 'self' ? receiver(first[1]) : null
  const params = (self ? rest : f.sig.inputs).map(([name, t]: [string, Json]) => ({ name, type: type(t) }))
  const output = f.sig.output ? type(f.sig.output) : ''
  const qualifiers = `${f.header.is_const ? 'const ' : ''}${f.header.is_async ? 'async ' : ''}${f.header.is_unsafe ? 'unsafe ' : ''}`
  const list = [...(self ? [self] : []), ...params.map((p: { name: string; type: string }) => `${p.name}: ${p.type}`)]
  const inline = `${visibility}${qualifiers}fn ${item.name}${generics(f.generics)}(${list.join(', ')})${output ? ` -> ${output}` : ''}`
  const signature = inline.length > 100 ? `${visibility}${qualifiers}fn ${item.name}${generics(f.generics)}(\n${list.map((p) => `    ${p},`).join('\n')}\n)${output ? ` -> ${output}` : ''}` : inline
  return { signature: signature + where(f.generics), function: { receiver: self, params, output, async: f.header.is_async } }
}

/** The declaration of a struct, enum, trait, alias or constant, as it would be written with private parts left out. */
function declaration(doc: Rustdoc, item: Json): string {
  const inner = item.inner
  const fields = (ids: number[], visibility: string) =>
    ids
      .map((id) => doc.index[id])
      .filter(Boolean)
      .map((f) => `    ${visibility}${f.name}: ${type(f.inner.struct_field)},`)
  if (inner.struct) {
    const head = `pub struct ${item.name}${generics(inner.struct.generics)}`
    const kind = inner.struct.kind
    if (kind === 'unit') return `${head};`
    if (kind.tuple) return `${head}(${kind.tuple.map((id: number | null) => (id == null ? '_' : `pub ${type(doc.index[id].inner.struct_field)}`)).join(', ')});`
    const lines = [...fields(kind.plain.fields, 'pub '), ...(kind.plain.has_stripped_fields ? ['    // private fields'] : [])]
    return `${head}${where(inner.struct.generics)} {\n${lines.join('\n')}\n}`
  }
  if (inner.enum) {
    const variants = inner.enum.variants.map((id: number) => {
      const v = doc.index[id]
      const kind = v.inner.variant.kind
      if (kind === 'plain') return `    ${v.name},`
      if (kind.tuple) return `    ${v.name}(${kind.tuple.map((f: number | null) => (f == null ? '_' : type(doc.index[f].inner.struct_field))).join(', ')}),`
      return `    ${v.name} {\n${fields(kind.struct.fields, '').map((l) => `    ${l}`).join('\n')}\n    },`
    })
    return `pub enum ${item.name}${generics(inner.enum.generics)} {\n${variants.join('\n')}\n}`
  }
  if (inner.trait) return `pub ${inner.trait.is_unsafe ? 'unsafe ' : ''}trait ${item.name}${generics(inner.trait.generics)}${inner.trait.bounds.length ? `: ${bounds(inner.trait.bounds)}` : ''}${where(inner.trait.generics)}`
  if (inner.type_alias) return `pub type ${item.name}${generics(inner.type_alias.generics)} = ${type(inner.type_alias.type)};`
  if (inner.constant) return `pub const ${item.name}: ${type(inner.constant.type)} = ${inner.constant.const.value ?? inner.constant.const.expr};`
  if (inner.static) return `pub static ${item.name}: ${type(inner.static.type)};`
  return ''
}

const KINDS: Record<string, Item['kind']> = { struct: 'struct', enum: 'enum', trait: 'trait', function: 'fn', type_alias: 'type', constant: 'const', static: 'const' }

/** A crate's modules, items and root re-exports, from its rustdoc JSON. `dir` is the crate's directory. */
function reference(doc: Rustdoc, dir: string) {
  const modules: Module[] = []
  const items: Item[] = []
  const reexports: { name: string; source: string }[] = []
  const emitted = new Map<number, Item>()
  const pending: { use: Json; module: string }[] = []
  const file = (item: Json) => `${dir}/${item.span?.filename ?? 'src/lib.rs'}`
  const line = (item: Json) => item.span?.begin[0] ?? 1

  const method = (item: Json, module: string, owner: string, implemented: Json | null) => {
    if (!item?.inner.function) return
    const { text, examples } = split(item.docs)
    // A trait implementation without docs of its own reads as the trait's method does.
    const inherited = !item.docs && implemented ? (doc.index[implemented.id]?.inner.trait?.items ?? []).map((id: number) => doc.index[id]).find((m: Json) => m?.name === item.name) : null
    items.push({
      path: [module, owner, item.name].filter(Boolean).join('::'),
      module,
      name: item.name,
      owner,
      kind: 'method',
      implements: implemented ? path(implemented) : null,
      ...fn(item, item.visibility === 'public' ? 'pub ' : ''),
      doc: text,
      inherits: inherited ? `${implemented.path.split('::').pop()}::${item.name}` : null,
      inherited_doc: inherited?.docs ? split(inherited.docs).text : null,
      file: file(item),
      line: line(item),
      export: null,
      examples,
    })
  }

  const declare = (item: Json, module: string) => {
    const kind = KINDS[Object.keys(item.inner)[0]]
    if (!kind || emitted.has(item.id)) return
    const { text, examples } = split(item.docs)
    const declared: Item = {
      path: [module, item.name].filter(Boolean).join('::'),
      module,
      name: item.name,
      owner: null,
      kind,
      implements: null,
      ...(kind === 'fn' ? fn(item, 'pub ') : { signature: declaration(doc, item), function: null }),
      doc: text,
      inherits: null,
      inherited_doc: null,
      file: file(item),
      line: line(item),
      export: null,
      examples,
    }
    emitted.set(item.id, declared)
    items.push(declared)
    for (const id of item.inner.trait?.items ?? []) method(doc.index[id], module, item.name, null)
    for (const id of (item.inner.struct ?? item.inner.enum)?.impls ?? []) {
      const impl = doc.index[id]
      // Written implementations only: not derives, auto traits or blanket implementations.
      if (!impl || impl.inner.impl.is_synthetic || impl.inner.impl.blanket_impl || impl.attrs.some((a: Json) => JSON.stringify(a).includes('automatically_derived'))) continue
      for (const member of impl.inner.impl.items) method(doc.index[member], module, item.name, impl.inner.impl.trait)
    }
  }

  const walk = (id: number, module: string) => {
    const item = doc.index[id]
    const { text, examples } = split(item.docs)
    modules.push({ path: module, doc: text, examples, file: file(item), public: true })
    for (const child of item.inner.module.items.map((c: number) => doc.index[c]).filter(Boolean)) {
      if (child.inner.module) walk(child.id, [module, child.name].filter(Boolean).join('::'))
      else if (child.inner.use) pending.push({ use: child.inner.use, module })
      else declare(child, module)
    }
  }
  walk(doc.root, '')

  // A `pub use` of an item from a private module documents it where it is re-exported; one of an item
  // already documented in its own module is, at the root, the short path readers import it by.
  const reexport = (target: Json, name: string, source: string, module: string) => {
    if (!target) return
    const known = emitted.get(target.id)
    if (!known) declare({ ...target, name }, module)
    else if (!module && known.module) known.export = name
    if (!module) reexports.push({ name, source: source.replace(/^(crate|self)::/, '') })
  }
  for (const { use, module } of pending) {
    const target = doc.index[use.id]
    if (!use.is_glob) reexport(target, use.name, use.source, module)
    else for (const id of target?.inner.module?.items ?? []) reexport(doc.index[id], doc.index[id]?.name, `${use.source}::${doc.index[id]?.name}`, module)
  }
  return { modules, items, reexports }
}

// --- PHP --------------------------------------------------------------------------------------------------

/** The names a PHP file imports with `use`, group imports expanded. */
function imports(text: string): string[] {
  const names: string[] = []
  for (const line of text.split('\n')) {
    const statement = line.trim()
    if (!statement.startsWith('use ') || !statement.endsWith(';')) continue
    const rest = statement.slice(4, -1).replace(/^(function|const) /, '').trim()
    const bare = (name: string) => name.split(' as ')[0].trim().replace(/^\\/, '')
    const brace = rest.indexOf('{')
    if (brace < 0) names.push(...rest.split(',').map(bare).filter(Boolean))
    else {
      const prefix = rest.slice(0, brace).trim().replace(/^\\/, '')
      names.push(...rest.slice(brace + 1).replace(/}$/, '').split(',').map(bare).filter(Boolean).map((n) => prefix + n))
    }
  }
  return names
}

/** The part of Appwrite a PHP file belongs to: an API module, a worker, the schedulers, or the realtime or MQTT server. */
function component(file: string): Pick<AppwriteComponent, 'kind' | 'name'> | null {
  const stem = file.split('/').pop()!.replace(/\.php$/, '')
  const modules = 'src/Appwrite/Platform/Modules/'
  if (file.startsWith(modules)) {
    const parts = file.slice(modules.length).split('/')
    if (parts[1] === 'Workers') return parts.length === 3 && !stem.endsWith('Exception') ? { kind: 'worker', name: stem } : null
    return parts[1] === 'Http' ? { kind: 'http', name: parts[0] } : null
  }
  if (dirname(file) === 'src/Appwrite/Platform/Workers') return { kind: 'worker', name: stem }
  if (dirname(file) === 'src/Appwrite/Platform/Tasks' && stem.startsWith('Schedule')) return { kind: 'schedule', name: 'Schedulers' }
  if (file === 'app/realtime.php' || file.startsWith('src/Appwrite/Realtime')) return { kind: 'realtime', name: 'Realtime' }
  if (file === 'app/mqtt.php' || file.startsWith('src/Appwrite/Mqtt')) return { kind: 'mqtt', name: 'MQTT' }
  return null
}

/** The classes, interfaces, traits and enums PHP sources declare, fully qualified. */
function declared(paths: string[]): string[] {
  const names: string[] = []
  for (const file of paths) {
    const text = read(file)
    const namespace = text.match(/^namespace\s+([^;]+);/m)?.[1]
    const name = text.match(/^(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m)?.[1]
    if (name) names.push(namespace ? `${namespace}\\${name}` : name)
  }
  return names.sort()
}

// --- Assembly ---------------------------------------------------------------------------------------------

/** A dependency as `cargo metadata` reports it, read from the manifest: no cargo needed to build the site. */
interface Dependency {
  name: string
  rename: string | null
  req: string
  kind: string | null
  optional: boolean
  uses_default_features: boolean
  features: string[]
  target: string | null
}

const workspace = Bun.TOML.parse(read('Cargo.toml')) as Json

/** A version requirement as cargo normalizes it: a bare version means caret. */
const requirement = (version?: string) => (!version ? '*' : '^~=<>*'.includes(version[0]) || version.includes(',') ? version : `^${version}`)

function dependencies(table: Json = {}, kind: string | null, target: string | null): Dependency[] {
  // In key order, as cargo lists them.
  return Object.keys(table).sort().map((key) => {
    let spec: Json = table[key]
    spec = typeof spec === 'string' ? { version: spec } : spec
    const inherited = spec.workspace ? workspace.workspace.dependencies[key] : {}
    const base = typeof inherited === 'string' ? { version: inherited } : inherited
    const name = spec.package ?? base.package ?? key
    return {
      name,
      rename: name === key ? null : key,
      req: requirement(spec.version ?? base.version),
      kind,
      optional: spec.optional ?? false,
      uses_default_features: (spec['default-features'] ?? base['default-features']) !== false,
      features: [...(base.features ?? []), ...(spec.features ?? [])],
      target,
    }
  })
}

/** A crate under crates/utopia, read from its Cargo.toml with `.workspace = true` fields filled from the root's. */
function manifest(dir: string) {
  const toml = Bun.TOML.parse(read(`${dir}/Cargo.toml`)) as Json
  const field = (key: string) => (toml.package[key]?.workspace ? workspace.workspace.package[key] : toml.package[key])
  const tables = (t: Json, target: string | null) => [
    ...dependencies(t.dependencies, null, target),
    ...dependencies(t['dev-dependencies'], 'dev', target),
    ...dependencies(t['build-dependencies'], 'build', target),
  ]
  const deps = [...tables(toml, null), ...Object.entries(toml.target ?? {}).flatMap(([target, t]) => tables(t, target))]
  // Cargo adds a feature for each optional dependency no feature names with `dep:`.
  const features: Record<string, string[]> = { ...toml.features }
  const named = Object.values(features).flat()
  for (const d of deps) {
    const key = d.rename ?? d.name
    if (d.optional && !named.includes(`dep:${key}`)) features[key] ??= [`dep:${key}`]
  }
  return {
    name: toml.package.name as string,
    version: field('version') as string,
    description: (field('description') as string | undefined) ?? null,
    metadata: (toml.package.metadata ?? null) as { utopia?: { category?: string } } | null,
    features,
    dependencies: deps,
    dir,
    slug: dir.split('/').pop()!,
  }
}

// Published crates only: a crate's page is its docs.rs reference, so one not yet on crates.io waits for its release.
const docs = new Map<string, Rustdoc>()
const crates = []
for (const dir of readdirSync(join(root, 'crates/utopia')).map((d) => `crates/utopia/${d}`).filter((d) => exists(`${d}/Cargo.toml`)).sort()) {
  const c = manifest(dir)
  const doc = await rustdoc(c.name, c.version)
  if (!doc) {
    console.warn(`${c.name}: not on docs.rs yet, left off the site`)
    continue
  }
  docs.set(c.name, doc)
  crates.push(c)
}
const slugOf = new Map(crates.map((c) => [c.name, c.slug]))

/** The versions `Cargo.lock` holds of each package. */
const locked = new Map<string, string[]>()
for (const entry of read('Cargo.lock').split('[[package]]').slice(1)) {
  const name = entry.match(/^name = "(.+)"$/m)?.[1]
  const version = entry.match(/^version = "(.+)"$/m)?.[1]
  if (name && version) locked.set(name, [...(locked.get(name) ?? []), version])
}

const taxonomy = JSON.parse(readFileSync(join(app, 'content/categories.json'), 'utf8')) as { categories: Omit<Category, 'libraries'>[]; libraries: Record<string, string> }

// What the extension serves. `stable`: always; `enabled`: when switched on, the PHP classes still exist.
const extension = read('crates/extension/src/lib.rs')
const gates = new Map<string, Status>()
for (const [, gate, name] of extension.matchAll(/\bif (stable|enabled)\("([\w-]+)"\)/g)) gates.set(name, gate === 'stable' ? 'rust' : 'switchable')

// The PHP packages: this repository's, and the Utopia ones Composer installs.
interface Composer {
  name: string
  description?: string
  autoload?: { 'psr-4'?: Record<string, string> }
  require?: Record<string, string>
  'require-dev'?: Record<string, string>
}
const packages = new Map<string, { composer: Composer; dir: string | null; version: string | null }>()
for (const entry of readdirSync(join(root, 'packages'))) {
  if (exists(`packages/${entry}/composer.json`)) packages.set(entry, { composer: json<Composer>(`packages/${entry}/composer.json`), dir: `packages/${entry}`, version: null })
}
const lock = json<{ packages: (Composer & { version: string })[]; 'packages-dev': (Composer & { version: string })[] }>('composer.lock')
for (const installed of [...lock.packages, ...lock['packages-dev']]) {
  const slug = installed.name.startsWith('utopia-php/') ? installed.name.slice('utopia-php/'.length) : null
  if (slug && !packages.has(slug)) packages.set(slug, { composer: installed, dir: null, version: installed.version })
}

/** PHP namespace prefixes and the library each belongs to, longest first. */
const namespaces: [string, string][] = []
for (const [slug, { composer }] of packages) for (const prefix of Object.keys(composer.autoload?.['psr-4'] ?? {})) namespaces.push([prefix, slug])
// A library whose PHP package is gone keeps its namespace: `Utopia\DSN\` is `dsn`.
for (const { slug } of crates) if (!packages.has(slug)) namespaces.push([`Utopia\\${slug.split('-').join('')}\\`.toLowerCase(), slug])
namespaces.sort((a, b) => b[0].length - a[0].length)
const libraryOf = (name: string) => namespaces.find(([prefix]) => `${name}\\`.toLowerCase().startsWith(prefix.toLowerCase()))?.[1] ?? null

// The PHP classes Rust serves, by library.
const served = new Map<string, string[]>()
for (const name of read('crates/extension/tests/libraries.expected').split('\n').map((l) => l.trim()).filter(Boolean)) {
  const slug = libraryOf(name)
  if (!slug) throw new Error(`crates/extension/tests/libraries.expected: no library owns ${name}`)
  served.set(slug, [...(served.get(slug) ?? []), name])
}

// Which files of Appwrite import which library.
const usage = new Map<string, number>()
const components = new Map<string, AppwriteComponent>()
for (const file of [...files('app', '.php'), ...files('src/Appwrite', '.php')]) {
  const used = new Set(imports(read(file)).map(libraryOf).filter((s): s is string => !!s))
  const part = component(file)
  for (const slug of used) {
    usage.set(slug, (usage.get(slug) ?? 0) + 1)
    if (!part) continue
    const key = `${part.kind}:${part.name}`
    const entry = components.get(key) ?? { ...part, libraries: {} }
    entry.libraries[slug] = (entry.libraries[slug] ?? 0) + 1
    components.set(key, entry)
  }
}

rmSync(out, { recursive: true, force: true })
mkdirSync(out, { recursive: true })
const write = (name: string, data: unknown) => writeFileSync(join(out, name), JSON.stringify(data))

const dependents = new Map<string, Dependencies['used_by']>()
for (const c of crates)
  for (const d of c.dependencies) {
    const target = slugOf.get(d.name)
    if (!target) continue
    const list = dependents.get(target) ?? []
    const kind = (d.kind ?? 'normal') as RustDependency['kind']
    const entry = list.find((u) => u.slug === c.slug)
    if (entry) entry.kinds.push(kind)
    else list.push({ slug: c.slug, kinds: [kind] })
    dependents.set(target, list)
  }

const summaries: LibrarySummary[] = []
for (const c of crates) {
  const php = packages.get(c.slug)
  const doc = docs.get(c.name)!
  const { modules, items, reexports } = reference(doc, c.dir)
  const guide = exists(`${c.dir}/guide.md`) ? blocks(read(`${c.dir}/guide.md`)) : []
  const overview = modules.find((m) => !m.path)
  const remaining = php?.dir ? declared(sources(php.dir)) : []
  const dependencies = c.dependencies.map((d): RustDependency => {
    const versions = locked.get(d.name) ?? []
    return {
      name: d.rename ?? d.name,
      package: d.name,
      kind: (d.kind ?? 'normal') as RustDependency['kind'],
      target: d.target,
      requirement: d.req === '*' ? null : d.req,
      optional: d.optional,
      enabled_by: d.optional ? Object.keys(c.features).filter((f) => c.features[f].some((x) => [`dep:${d.rename ?? d.name}`, d.rename ?? d.name].includes(x) || x.startsWith(`${d.rename ?? d.name}/`) || x.startsWith(`${d.rename ?? d.name}?/`))) : [],
      default_features: d.uses_default_features,
      features: d.features,
      locked: versions.length === 1 ? versions[0] : (versions.find((v) => v.startsWith(d.req.replace(/^[\^~=]/, '').split('.')[0])) ?? null),
      utopia: slugOf.get(d.name) ?? null,
    }
  })
  const of = (kind: RustDependency['kind']) => dependencies.filter((d) => d.kind === kind)
  const summary: LibrarySummary = {
    slug: c.slug,
    title: title(c.slug),
    category: c.metadata?.utopia?.category ?? taxonomy.libraries[c.slug] ?? 'utilities',
    description: c.description ?? '',
    // A crate the extension does not gate has no PHP library behind it: it is Rust and nothing else. A
    // stable one is all Rust once no PHP class of its package is left, here or in Composer's vendor/.
    status: gates.get(c.slug) === 'rust' && (remaining.length || (php && !php.dir)) ? 'partial' : (gates.get(c.slug) ?? 'rust'),
    php: php ? { package: php.composer.name, dir: php.dir, version: php.version } : gates.has(c.slug) ? { package: `utopia-php/${c.slug}`, dir: null, version: null } : null,
    rust: { name: c.name, crate: c.name.split('-').join('_'), dir: c.dir, version: doc.crate_version, docs: `https://docs.rs/${c.name}/${doc.crate_version}` },
    classes: { rust: served.get(c.slug)?.length ?? 0, php: php && !php.dir ? null : remaining.length },
    counts: {
      items: items.length,
      documented: items.filter((i) => i.doc || i.inherited_doc).length,
      examples: [...items, ...modules].reduce((n, i) => n + i.examples.length, 0) + guide.filter((b) => 'example' in b).length,
      guide: guide.length > 0,
      dependencies: { rust: of('normal').length, dev: of('dev').length, build: of('build').length, used_by: dependents.get(c.slug)?.length ?? 0 },
      appwrite: usage.get(c.slug) ?? 0,
    },
    size: { rust: count(files(`${c.dir}/src`, '.rs')), php: php?.dir ? count(sources(php.dir)) : null },
    released: await released(c.name, doc.crate_version),
  }
  summaries.push(summary)
  const library: Library = {
    library: summary,
    install: { cargo: `cargo add ${c.name}` },
    overview: overview?.doc ?? '',
    guide,
    dependencies: {
      rust: of('normal'),
      dev: of('dev'),
      build: of('build'),
      used_by: dependents.get(c.slug) ?? [],
      appwrite: [...components.values()].filter((p) => p.libraries[c.slug]).map((p) => ({ kind: p.kind, name: p.name, files: p.libraries[c.slug] })),
    },
    classes: { rust: served.get(c.slug) ?? [], php: remaining },
    examples: overview?.examples ?? [],
    modules,
    items,
    reexports,
  }
  write(`${c.slug}.json`, library)
}

// Libraries with no crate yet.
for (const [slug, { composer, dir, version }] of packages) {
  if (slugOf.has(`utopia-${slug}`) || crates.some((c) => c.slug === slug)) continue
  summaries.push({
    slug,
    title: title(slug),
    category: taxonomy.libraries[slug] ?? 'utilities',
    description: composer.description ?? '',
    status: 'planned',
    php: { package: composer.name, dir, version },
    rust: null,
    classes: { rust: 0, php: dir ? declared(sources(dir)).length : null },
    counts: { items: 0, documented: 0, examples: 0, guide: false, dependencies: { rust: 0, dev: 0, build: 0, used_by: 0 }, appwrite: usage.get(slug) ?? 0 },
    size: { rust: null, php: dir ? count(sources(dir)) : null },
    released: null,
  })
}
summaries.sort((a, b) => a.slug.localeCompare(b.slug))

const index: Index = {
  categories: taxonomy.categories.map((c) => ({ ...c, libraries: summaries.filter((l) => l.category === c.id).map((l) => l.slug) })).filter((c) => c.libraries.length),
  libraries: summaries,
  commit: (await git('rev-parse', 'HEAD')) || null,
  branch: (await git('rev-parse', '--abbrev-ref', 'HEAD')) || null,
  generated_at: new Date().toISOString(),
  commit_date: (await git('log', '-1', '--format=%cI')) || null,
  size: { rust: sum(summaries.flatMap((l) => (l.size.rust ? [l.size.rust] : []))), php: sum(summaries.flatMap((l) => (l.size.php ? [l.size.php] : []))) },
  classes: { rust: summaries.reduce((n, l) => n + l.classes.rust, 0), php: summaries.reduce((n, l) => n + (l.classes.php ?? 0), 0) },
}
write('index.json', index)

const kinds = (composer: Composer, name: string) => [...(composer.require?.[name] ? ['normal' as const] : []), ...(composer['require-dev']?.[name] ? ['dev' as const] : [])]
const graph: Graph = {
  nodes: [
    ...summaries.map((l) => ({ id: l.slug, kind: 'library' as const, title: l.title, category: l.category, status: l.status, description: l.description, rust: l.rust?.name ?? null, php: l.php?.package ?? null })),
    { id: 'appwrite', kind: 'app', title: 'Appwrite', category: null, status: null, description: 'The Appwrite server: its API modules, workers, schedulers, and realtime and MQTT servers.', rust: null, php: 'appwrite/appwrite', components: [...components.values()].sort((a, b) => a.name.localeCompare(b.name)) },
  ],
  edges: [
    ...[...dependents].flatMap(([target, users]) => users.map((u) => ({ source: u.slug, target, runtime: 'rust' as const, kinds: [...new Set(u.kinds)] }))),
    ...[...packages].flatMap(([source, { composer }]) => [...packages].filter(([target, t]) => target !== source && kinds(composer, t.composer.name).length).map(([target, t]) => ({ source, target, runtime: 'php' as const, kinds: kinds(composer, t.composer.name) }))),
    // Appwrite reaches a library through PHP either way; the edge says which runtime answers.
    ...summaries.filter((l) => usage.get(l.slug)).map((l) => ({ source: 'appwrite', target: l.slug, runtime: l.status === 'planned' ? ('php' as const) : ('rust' as const), kinds: ['normal' as const] })),
  ],
}
write('graph.json', graph)

const on = summaries.filter((l) => l.php && l.status !== 'planned')
console.log(`generate: ${on.length} of ${summaries.filter((l) => l.php).length} libraries on Rust, ${index.classes.rust} PHP classes served, ${summaries.reduce((n, l) => n + l.counts.items, 0)} documented items → ${relative(root, out)}`)
// Keep the timestamp of a failed run from hiding stale data.
if (!statSync(join(out, 'index.json')).size) throw new Error('generate: wrote an empty index')
