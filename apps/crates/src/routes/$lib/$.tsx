import { Link, createFileRoute, getRouteApi, notFound, redirect } from '@tanstack/react-router'
import { ChevronRight, ChevronsDownUp, ChevronsUpDown, Search } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { CodeBlock, Examples } from '@/components/Code'
import { Markdown } from '@/components/Markdown'
import { PageHeader, Section, WithToc } from '@/components/Page'
import { PhpCounterpart } from '@/components/Php'
import { PhpNotes } from '@/components/Migration'
import {
  KIND_LABEL,
  KIND_ORDER,
  KIND_TITLE,
  ROOT,
  isTopLevel,
  itemHash,
  loadLibrary,
  methodHash,
  moduleSplat,
  sourceUrl,
  summary,
  usePath,
  type PhpSymbol,
} from '@/lib/docs'

const root = getRouteApi('__root__')
const layout = getRouteApi('/$lib')
const KINDS = new Set(['struct', 'enum', 'trait', 'fn', 'type', 'const'])

/** One namespace (a Rust module, or the crate root) and everything in it. */
async function load(params: { lib: string; _splat?: string }) {
  const lib = await loadLibrary(params.lib)
  if (!lib) throw notFound()
  const segments = (params._splat ?? '').split('/').filter(Boolean)
  const last = segments[segments.length - 1] ?? ''
  // Item pages are gone: `text/struct.Text` now lives at `text#item.Text`.
  if (last.includes('.') && KINDS.has(last.split('.')[0])) {
    const name = last.split('.', 2)[1]
    throw redirect({ to: '/$lib/$', params: { lib: params.lib, _splat: moduleSplat(segments.slice(0, -1).join('::')) }, hash: itemHash(name), replace: true })
  }
  const path = segments.length === 1 && segments[0] === ROOT ? '' : segments.join('::')
  const module = lib.modules.find((m) => m.path === path)
  if (!module) throw notFound()
  const php = (symbols: string[]) => symbols.map((s) => lib.php_api.find((p) => p.symbol === s)).filter((p): p is PhpSymbol => !!p)
  const traitNames = new Set(lib.items.filter((i) => i.kind === 'trait').map((i) => i.name))
  const where = (name: string) => {
    const found = lib.items.find((i) => isTopLevel(i) && i.name === name)
    return found ? { splat: moduleSplat(found.module), hash: itemHash(found.name) } : null
  }
  // A namespace page holds its whole subtree (the crate root only itself): own items first, then each sub-namespace's.
  const inside = (m: string) => m === path || (!!path && m.startsWith(`${path}::`))
  const items = lib.items
    .filter((i) => isTopLevel(i) && inside(i.module))
    .sort(
      (a, b) =>
        (a.module === path ? -1 : 0) - (b.module === path ? -1 : 0) ||
        a.module.localeCompare(b.module) ||
        KIND_ORDER.indexOf(a.kind as never) - KIND_ORDER.indexOf(b.kind as never) ||
        a.name.localeCompare(b.name),
    )
    .map((item) => {
      const unique = lib.items.filter((i) => isTopLevel(i) && i.name === item.name).length === 1
      const methods = lib.items
        .filter((i) => i.kind === 'method' && i.owner === item.name && (i.module === item.module || unique))
        .map((m) => ({ ...m, phpSymbols: php(m.php) }))
      const traits = [...new Set(methods.map((m) => m.implements).filter((t): t is string => !!t))]
      return {
        item,
        php: php(item.php),
        inherent: methods.filter((m) => !m.implements),
        // Traits of this library get their methods listed; standard ones (Debug, From, ...) are named.
        impls: traits.filter((t) => traitNames.has(t)).map((t) => ({ name: t, at: where(t), methods: methods.filter((m) => m.implements === t) })),
        external: traits.filter((t) => !traitNames.has(t)),
        implementors:
          item.kind === 'trait'
            ? [...new Set(lib.items.filter((i) => i.implements === item.name && i.owner).map((i) => i.owner as string))].map((n) => ({ name: n, at: where(n) }))
            : [],
      }
    })
  // Sub-namespaces with something to show: items, or words of their own.
  const parts = lib.modules
    .filter((m) => m.path !== path && inside(m.path))
    .filter((m) => m.doc || items.some((e) => e.item.module === m.path))
    .sort((a, b) => a.path.localeCompare(b.path))
    .map((m) => ({ ...m, name: m.path.slice(path.length + 2) }))
  // The crate root lists its top-level namespaces; the menu shows the same.
  const children = path
    ? []
    : lib.modules.filter((m) => m.path && !m.path.includes('::')).map((m) => ({ path: m.path, summary: summary(m.doc) }))
  return { module, items, parts, children }
}

type Data = Awaited<ReturnType<typeof load>>
type Entry = Data['items'][number]
type MethodData = Entry['inherent'][number]

export const Route = createFileRoute('/$lib/$')({
  loader: ({ params }) => load(params),
  component: NamespacePage,
  notFoundComponent: () => <div className="text-muted-foreground">Nothing documented at this path.</div>,
})

function Source({ file, line }: { file: string; line?: number }) {
  const index = root.useLoaderData()
  return (
    <a href={sourceUrl(index?.branch ?? index?.commit, file, line)} target="_blank" rel="noopener" className="text-[12px] text-muted-foreground hover:text-foreground">
      {file}
      {line ? `:${line}` : ''}
    </a>
  )
}

function Doc({ doc, inherited, from }: { doc: string; inherited?: string | null; from?: string | null }) {
  const nav = layout.useLoaderData()
  if (doc) return <Markdown text={doc} lib={nav} slug={nav.library.slug} />
  if (inherited)
    return (
      <div className="grid gap-1.5">
        <Markdown text={inherited} lib={nav} slug={nav.library.slug} />
        <span className="text-[12px] text-muted-foreground">
          Documented on <code className="font-mono">{from}</code>
        </span>
      </div>
    )
  return <p className="m-0 text-muted-foreground italic">Undocumented.</p>
}

function Method({ owner, m }: { owner: string; m: MethodData }) {
  const id = methodHash(owner, m.name)
  return (
    <div id={id} className="grid min-w-0 scroll-mt-20 gap-3 border-t border-border pt-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <a href={`#${id}`} className="font-mono text-[14px] font-medium hover:underline">
          {m.name}
        </a>
        <Source file={m.file} line={m.line} />
      </div>
      <CodeBlock code={m.signature} lang="rust" />
      <Doc doc={m.doc} inherited={m.inherited_doc} from={m.inherits} />
      <PhpNotes notes={m.php_notes} />
      <Examples examples={m.examples} />
      <PhpCounterpart symbols={m.phpSymbols} />
    </div>
  )
}

/** Methods under a heading that folds, so a long type stays scannable. It opens when a link targets one of its methods. */
function Methods({ title, owner, methods, open: initial = false }: { title: ReactNode; owner: string; methods: MethodData[]; open?: boolean }) {
  const [open, setOpen] = useState(initial)
  useEffect(() => {
    const reveal = () => {
      const target = decodeURIComponent(window.location.hash.slice(1))
      if (!methods.some((m) => methodHash(owner, m.name) === target)) return
      setOpen(true)
      // Below the sticky header, as the method's scroll margin asks.
      requestAnimationFrame(() => {
        const el = document.getElementById(target)
        if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 80 })
      })
    }
    reveal()
    window.addEventListener('hashchange', reveal)
    return () => window.removeEventListener('hashchange', reveal)
  }, [methods, owner])
  if (!methods.length) return null
  return (
    <div className="grid gap-3">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex items-center gap-1.5 justify-self-start text-[13px] font-medium text-muted-foreground hover:text-foreground"
      >
        <ChevronRight className={`size-3.5 transition-transform ${open ? 'rotate-90' : ''}`} />
        {title}
        <span className="text-[12px] font-normal tabular-nums">{methods.length}</span>
      </button>
      {open ? (
        <div className="grid gap-5 pl-5">
          {methods.map((m) => (
            <Method key={m.path + m.line} owner={owner} m={m} />
          ))}
        </div>
      ) : null}
    </div>
  )
}

/** `(dsn: &str) -> Result<Self, Error>`: a method's shape, without its name. */
function shape(m: MethodData) {
  const f = m.function
  if (!f) return ''
  const params = [...(f.receiver ? [f.receiver] : []), ...f.params.map((p) => `${p.name}: ${p.type}`)].join(', ')
  return `(${params})${f.output ? ` -> ${f.output}` : ''}`
}

/** An IDE-style outline of a type's methods: kind, name, signature and summary, each linking to the method. */
const BADGE: Record<string, { letter: string; tone: string }> = {
  struct: { letter: 'S', tone: 'bg-started-soft text-started-ink' },
  enum: { letter: 'E', tone: 'bg-progress-soft text-progress-ink' },
  trait: { letter: 'T', tone: 'bg-ok-soft text-ok-ink' },
  fn: { letter: 'f', tone: 'bg-muted text-muted-foreground' },
  type: { letter: 't', tone: 'bg-muted text-muted-foreground' },
  const: { letter: 'c', tone: 'bg-muted text-muted-foreground' },
  method: { letter: 'm', tone: 'text-muted-foreground' },
  associated: { letter: 'f', tone: 'text-muted-foreground' },
}

function Badge({ kind }: { kind: string }) {
  const b = BADGE[kind] ?? BADGE.fn
  return <span className={`grid size-4 flex-none place-items-center rounded-[4px] font-mono text-[9.5px] font-semibold ${b.tone}`}>{b.letter}</span>
}

/** The section the reader is in: the last anchor above the fold line. */
function useCurrent(ids: string[]) {
  const [current, setCurrent] = useState<string | null>(null)
  useEffect(() => {
    let frame = 0
    const update = () => {
      frame = 0
      let at: string | null = null
      for (const id of ids) {
        const el = document.getElementById(id)
        if (el && el.offsetParent !== null && el.getBoundingClientRect().top < 120) at = id
      }
      // At the end of the page the last sections never reach the top: the linked one wins.
      const target = decodeURIComponent(window.location.hash.slice(1))
      const bottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4
      if (bottom && ids.includes(target) && document.getElementById(target)) at = target
      setCurrent(at)
    }
    const schedule = () => {
      if (!frame) frame = requestAnimationFrame(update)
    }
    update()
    window.addEventListener('scroll', schedule, { passive: true })
    window.addEventListener('hashchange', schedule)
    return () => {
      window.removeEventListener('scroll', schedule)
      window.removeEventListener('hashchange', schedule)
      if (frame) cancelAnimationFrame(frame)
    }
  }, [ids])
  return current
}

/**
 * The page's symbols as an IDE outline: items grouped by kind, each type
 * opening onto its methods (inherent, then one group per trait it
 * implements). Follows the scroll, filters, folds.
 */
function Symbols({ items, base }: { items: Entry[]; base: string }) {
  const [q, setQ] = useState('')
  const [open, setOpen] = useState<Set<string>>(() => new Set())
  const term = q.trim().toLowerCase()
  const groups = (e: Entry) => [{ title: null as string | null, methods: e.inherent }, ...e.impls.map((t) => ({ title: `impl ${t.name}`, methods: t.methods }))].filter((g) => g.methods.length)
  const ids = useMemo(
    () => items.flatMap((e) => [itemHash(e.item.name), ...[...e.inherent, ...e.impls.flatMap((t) => t.methods)].map((m) => methodHash(e.item.name, m.name))]),
    [items],
  )
  const current = useCurrent(ids)
  // Grouped by sub-namespace when the page holds several, by kind otherwise.
  const modules = [...new Set(items.map((e) => e.item.module))]
  const sections =
    modules.length > 1
      ? modules.map((m) => ({
          key: m,
          title: m === base ? 'Defined here' : m.slice(base.length + 2),
          mono: m !== base,
          href: `#ns.${m.slice(base.length + 2)}`,
          has: (e: Entry) => e.item.module === m,
        }))
      : KIND_ORDER.map((k) => ({ key: k, title: KIND_TITLE[k], mono: false, href: '', has: (e: Entry) => e.item.kind === k }))
  const expandable = items.filter((e) => groups(e).length).map((e) => e.item.name)
  const all = expandable.length > 0 && expandable.every((n) => open.has(n))
  const toggle = (name: string) =>
    setOpen((o) => {
      const next = new Set(o)
      if (next.has(name)) next.delete(name)
      else next.add(name)
      return next
    })
  const row = 'flex min-w-0 items-center gap-1.5 rounded-md py-1 pr-2 text-[12.5px] transition-colors hover:bg-sidebar-accent hover:text-sidebar-strong'
  const active = (id: string) => (current === id ? 'bg-sidebar-accent text-sidebar-strong' : 'text-sidebar-ink')
  if (!items.length) return null
  return (
    <nav className="sticky top-20 grid max-h-[calc(100vh-6rem)] grid-rows-[auto_auto_minmax(0,1fr)] gap-2" aria-label="Symbols">
      <div className="flex items-center justify-between">
        <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Outline</span>
        {expandable.length ? (
          <button
            type="button"
            onClick={() => setOpen(all ? new Set() : new Set(expandable))}
            className="inline-flex size-6 items-center justify-center rounded-md text-sidebar-ink hover:bg-sidebar-accent hover:text-sidebar-strong"
            title={all ? 'Collapse all' : 'Expand all'}
            aria-label={all ? 'Collapse all' : 'Expand all'}
          >
            {all ? <ChevronsDownUp className="size-3.5" /> : <ChevronsUpDown className="size-3.5" />}
          </button>
        ) : null}
      </div>
      <label className="flex items-center gap-2 rounded-md border border-border bg-background px-2 py-1 text-muted-foreground focus-within:border-ring">
        <Search className="size-3.5" />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Filter symbols"
          aria-label="Filter symbols"
          className="min-w-0 flex-1 bg-transparent text-[12.5px] text-foreground outline-none placeholder:text-muted-foreground"
        />
      </label>
      <div className="grid content-start gap-2 overflow-y-auto overscroll-contain pb-4">
        {sections.map((sec) => {
          const entries = items
            .filter(sec.has)
            .map((e) => {
              const self = !term || e.item.name.toLowerCase().includes(term)
              const shown = groups(e)
                .map((g) => ({ ...g, methods: self ? g.methods : g.methods.filter((m) => m.name.toLowerCase().includes(term)) }))
                .filter((g) => g.methods.length)
              return { e, shown, visible: self || shown.length > 0 }
            })
            .filter((x) => x.visible)
          if (!entries.length) return null
          return (
            <div key={sec.key} className="grid gap-px">
              {sec.mono ? (
                <a href={sec.href} className="truncate px-1.5 pb-0.5 font-mono text-[11.5px] font-medium text-sidebar-strong hover:underline">
                  {sec.title}
                </a>
              ) : (
                <div className="px-1.5 pb-0.5 text-[10.5px] font-semibold tracking-wider text-muted-foreground uppercase">{sec.title}</div>
              )}
              {entries.map(({ e, shown }) => {
                const id = itemHash(e.item.name)
                const inside = current !== null && current.startsWith(`method.${e.item.name}.`)
                const expanded = shown.length > 0 && (open.has(e.item.name) || inside || (!!term && shown.length > 0))
                return (
                  <div key={id} className="grid gap-px">
                    <div className="flex items-center">
                      {shown.length ? (
                        <button
                          type="button"
                          onClick={() => toggle(e.item.name)}
                          aria-expanded={expanded}
                          aria-label={`${expanded ? 'Collapse' : 'Expand'} ${e.item.name}`}
                          className="grid size-5 flex-none place-items-center rounded text-muted-foreground hover:text-foreground"
                        >
                          <ChevronRight className={`size-3 transition-transform ${expanded ? 'rotate-90' : ''}`} />
                        </button>
                      ) : (
                        <span className="size-5 flex-none" />
                      )}
                      <a href={`#${id}`} className={`${row} flex-1 pl-1 ${active(id)}`} title={summary(e.item.doc || e.item.inherited_doc) || e.item.name}>
                        <Badge kind={e.item.kind} />
                        <span className="truncate font-mono">{e.item.name}</span>
                        {shown.length ? <span className="ml-auto text-[10.5px] text-muted-foreground tabular-nums">{shown.reduce((n, g) => n + g.methods.length, 0)}</span> : null}
                      </a>
                    </div>
                    {expanded ? (
                      <div className="ml-[9px] grid gap-px border-l border-border pl-2.5">
                        {shown.map((g) => (
                          <div key={g.title ?? 'inherent'} className="grid gap-px">
                            {g.title ? <div className="truncate px-1.5 pt-1 font-mono text-[10.5px] text-muted-foreground">{g.title}</div> : null}
                            {g.methods.map((m) => {
                              const mid = methodHash(e.item.name, m.name)
                              return (
                                <a key={mid} href={`#${mid}`} className={`${row} pl-1.5 ${active(mid)}`} title={`${m.name}${shape(m)}`}>
                                  <Badge kind={m.function?.receiver ? 'method' : 'associated'} />
                                  <span className="truncate font-mono">{m.name}</span>
                                </a>
                              )
                            })}
                          </div>
                        ))}
                      </div>
                    ) : null}
                  </div>
                )
              })}
            </div>
          )
        })}
      </div>
    </nav>
  )
}

/** A type, function or trait in full, as a section of its namespace page. */
function ItemSection({ entry }: { entry: Entry }) {
  const nav = layout.useLoaderData()
  const { item } = entry
  const compact = item.kind === 'const' || item.kind === 'type'
  return (
    <section id={itemHash(item.name)} className="grid min-w-0 scroll-mt-20 gap-3 border-t border-border pt-6">
      <div className="flex flex-wrap items-center gap-2.5">
        <a href={`#${itemHash(item.name)}`} className="font-mono text-[19px] font-medium tracking-tight hover:underline">
          {item.name}
        </a>
        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">{KIND_LABEL[item.kind]}</span>
        <span className="flex-1" />
        <Source file={item.file} line={item.line} />
      </div>
      {compact ? null : <code className="font-mono text-[12px] text-muted-foreground">use {usePath(nav.library, item)};</code>}
      <CodeBlock code={item.signature} lang="rust" />
      <Doc doc={item.doc} />
      <PhpNotes notes={item.php_notes} />
      <Examples examples={item.examples} />
      <PhpCounterpart symbols={entry.php} />
      {entry.external.length ? (
        <div className="text-[12.5px] text-muted-foreground">
          Also implements{' '}
          {entry.external.map((t, i) => (
            <span key={t}>
              {i ? ', ' : ''}
              <code className="font-mono text-foreground">{t}</code>
            </span>
          ))}
          .
        </div>
      ) : null}
      <Methods title="Methods" owner={item.name} methods={entry.inherent} />
      {entry.impls.map((t) => (
        <Methods
          key={t.name}
          owner={item.name}
          methods={t.methods}
          title={
            <span>
              impl <span className="font-mono">{t.name}</span>
            </span>
          }
        />
      ))}
      {entry.implementors.length ? (
        <div className="flex flex-wrap items-center gap-2 text-[12.5px] text-muted-foreground">
          Implemented by
          {entry.implementors.map((i) =>
            i.at ? (
              <Link
                key={i.name}
                to="/$lib/$"
                params={{ lib: nav.library.slug, _splat: i.at.splat }}
                hash={i.at.hash}
                className="rounded-md border border-border bg-card px-2 py-0.5 font-mono text-[12px] text-foreground hover:border-ring"
              >
                {i.name}
              </Link>
            ) : (
              <span key={i.name} className="rounded-md border border-border px-2 py-0.5 font-mono text-[12px]">
                {i.name}
              </span>
            ),
          )}
        </div>
      ) : null}
    </section>
  )
}

function NamespacePage() {
  const { module, items, parts: subs, children } = Route.useLoaderData()
  const nav = layout.useLoaderData()
  const slug = nav.library.slug
  const crate = nav.library.rust?.crate ?? slug
  const parts = module.path ? module.path.split('::') : []
  const kinds = KIND_ORDER.filter((k) => items.some((e) => e.item.kind === k))
  const own = items.filter((e) => e.item.module === module.path)
  const groups = [
    ...(own.length ? [{ id: null as string | null, title: null as string | null, summary: '', entries: own }] : []),
    ...subs.map((p) => ({ id: `ns.${p.name}`, title: p.name, summary: summary(p.doc), entries: items.filter((e) => e.item.module === p.path) })),
  ]
  return (
    <WithToc rail={<Symbols items={items} base={module.path} />}>
      <div id="top" className="grid scroll-mt-20 gap-4">
        <PageHeader
          eyebrow={
            <span className="flex flex-wrap items-center gap-1 font-mono text-[12.5px]">
              <Link to="/$lib" params={{ lib: slug }} className="hover:text-foreground">
                {crate}
              </Link>
              {parts.slice(0, -1).map((p, i) => (
                <span key={i} className="flex items-center gap-1">
                  ::
                  <Link to="/$lib/$" params={{ lib: slug, _splat: moduleSplat(parts.slice(0, i + 1).join('::')) }} className="hover:text-foreground">
                    {p}
                  </Link>
                </span>
              ))}
            </span>
          }
          title={module.path ? parts[parts.length - 1] : 'Crate root'}
          mono={!!module.path}
          aside={<span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">Namespace</span>}
        >
          {module.path ? null : `What ${crate} defines at its root.`}
        </PageHeader>
        {module.path ? <Doc doc={module.doc} /> : null}
        {module.path ? <PhpNotes notes={module.php_notes} /> : null}
        {module.path ? <Examples examples={module.examples} /> : null}
      </div>

      {children.length ? (
        <Section title="Namespaces">
          <div className="overflow-hidden rounded-lg border border-border">
            {children.map((c) => (
              <Link
                key={c.path}
                to="/$lib/$"
                params={{ lib: slug, _splat: moduleSplat(c.path) }}
                className="grid gap-x-4 border-b border-border px-3.5 py-2.5 last:border-b-0 hover:bg-accent/40 md:grid-cols-[minmax(10rem,16rem)_minmax(0,1fr)]"
              >
                <span className="font-mono text-[13px] font-medium">{c.path}</span>
                <span className="text-[13px] text-muted-foreground">{c.summary || 'Undocumented.'}</span>
              </Link>
            ))}
          </div>
        </Section>
      ) : null}

      {items.length && subs.length ? (
        <Section title="Contents" description={`${subs.length} parts${own.length ? ', and what the namespace defines itself' : ''}.`}>
          <div className="grid gap-3 md:grid-cols-2">
            {groups.map((g) => (
              <div key={g.id ?? 'own'} className="grid content-start gap-1 rounded-lg border border-border p-3">
                {g.title ? (
                  <a href={`#${g.id}`} className="grid gap-0.5 hover:underline">
                    <span className="font-mono text-[13px] font-medium">{g.title}</span>
                    {g.summary ? <span className="line-clamp-2 text-[12.5px] text-muted-foreground">{g.summary}</span> : null}
                  </a>
                ) : (
                  <span className="text-[12px] font-medium text-muted-foreground">Defined here</span>
                )}
                <div className="flex flex-wrap gap-1.5 pt-1.5">
                  {g.entries.map((e) => (
                    <a
                      key={e.item.path + e.item.line}
                      href={`#${itemHash(e.item.name)}`}
                      className="inline-flex items-center gap-1.5 rounded-md border border-border px-1.5 py-0.5 font-mono text-[12px] hover:border-ring"
                      title={summary(e.item.doc || e.item.inherited_doc) || e.item.name}
                    >
                      <Badge kind={e.item.kind} />
                      {e.item.name}
                    </a>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </Section>
      ) : items.length ? (
        <Section title="Contents">
          <div className="grid gap-4 md:grid-cols-2">
            {kinds.map((k) => (
              <div key={k} className="grid content-start gap-1">
                <div className="px-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">{KIND_TITLE[k]}</div>
                {items
                  .filter((e) => e.item.kind === k)
                  .map((e) => (
                    <a key={e.item.name} href={`#${itemHash(e.item.name)}`} className="grid gap-0.5 rounded-md px-2 py-1.5 hover:bg-accent/50">
                      <span className="font-mono text-[13px] font-medium">{e.item.name}</span>
                      <span className="line-clamp-2 text-[12.5px] text-muted-foreground">{summary(e.item.doc || e.item.inherited_doc) || 'Undocumented.'}</span>
                    </a>
                  ))}
              </div>
            ))}
          </div>
        </Section>
      ) : null}

      {groups.map((g) => (
        <div key={g.id ?? 'own'} className="grid min-w-0 gap-9">
          {g.title ? (
            <div id={g.id ?? undefined} className="grid scroll-mt-20 gap-2 border-t-2 border-foreground/80 pt-5">
              <div className="flex flex-wrap items-baseline gap-2.5">
                <h2 className="font-mono text-[21px] font-medium tracking-tight">
                  <span className="text-muted-foreground">{module.path ? `${module.path.split('::').pop()}::` : ''}</span>
                  {g.title}
                </h2>
                <Link to="/$lib/$" params={{ lib: slug, _splat: moduleSplat(module.path ? `${module.path}::${g.title}` : g.title) }} className="text-[12px] text-muted-foreground hover:text-foreground">
                  Own page
                </Link>
              </div>
              {subs.find((p) => `ns.${p.name}` === g.id)?.doc ? <Doc doc={subs.find((p) => `ns.${p.name}` === g.id)?.doc ?? ''} /> : null}
            </div>
          ) : null}
          {g.entries.map((e) => (
            <ItemSection key={e.item.path + e.item.line} entry={e} />
          ))}
        </div>
      ))}
    </WithToc>
  )
}
