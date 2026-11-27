import { Link, createFileRoute, getRouteApi, notFound } from '@tanstack/react-router'
import { ArrowUpRight, Network } from 'lucide-react'
import { CodeBlock, ExampleView, Examples } from '@/components/Code'
import { Markdown } from '@/components/Markdown'
import { PageHeader, Section, WithToc } from '@/components/Page'
import { StatusBadge } from '@/components/Status'
import { Time } from '@/components/Time'
import { SizeLine } from '@/components/Size'
import { KIND_ORDER, KIND_TITLE, STATUS, formatCount, guideHeadings, isTopLevel, itemHash, loadLibrary, moduleSplat, slug, summary, type LibrarySummary } from '@/lib/docs'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/$lib/')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib) throw notFound()
    const top = lib.items.filter(isTopLevel)
    return {
      library: lib.library,
      install: lib.install,
      overview: lib.overview,
      guide: lib.guide,
      classes: lib.classes,
      examples: lib.examples,
      modules: lib.modules
        .filter((m) => m.path)
        .map((m) => ({ path: m.path, public: m.public, summary: summary(m.doc), items: top.filter((i) => i.module === m.path).length })),
      root: top.filter((i) => i.export || i.module === '').map((i) => ({ name: i.export ?? i.name, kind: i.kind, module: i.module, item: i.name, summary: summary(i.doc || i.inherited_doc) })),
    }
  },
  component: LibraryPage,
})

/** Where the library sits: its dependencies, its dependents, its place on the map, and its links out. */
function Ecosystem({ l }: { l: LibrarySummary }) {
  const { rust, used_by } = l.counts.dependencies
  const link = 'inline-flex items-center gap-1 hover:text-foreground'
  return (
    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-muted-foreground">
      <Link to="/$lib/dependencies" params={{ lib: l.slug }} className={link}>
        {rust} {rust === 1 ? 'dependency' : 'dependencies'} · {[used_by ? `used by ${used_by} ${used_by === 1 ? 'crate' : 'crates'}` : null, l.counts.appwrite ? 'in Appwrite' : null].filter(Boolean).join(', ') || 'used by nothing yet'}
      </Link>
      <Link to="/map" hash={l.slug} className={link}>
        <Network className="size-3.5" />
        On the map
      </Link>
      {l.rust ? (
        <a href={l.rust.docs} className={link}>
          docs.rs
          <ArrowUpRight className="size-3" />
        </a>
      ) : null}
      {l.updated ? (
        <span>
          Updated <Time date={l.updated} />
        </span>
      ) : null}
    </div>
  )
}

/** What Appwrite runs of this library today: the classes the extension serves from Rust, and what is still PHP. */
function Migration({ l, classes }: { l: LibrarySummary; classes: { rust: string[]; php: string[] } }) {
  const list = (title: string, names: string[]) =>
    names.length ? (
      <details>
        <summary className="cursor-pointer text-[11px] tracking-wider text-muted-foreground uppercase select-none hover:text-foreground">
          {title} ({names.length})
        </summary>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {names.map((n) => (
            <code key={n} className="rounded border border-border px-1.5 py-0.5 font-mono text-[11.5px] [overflow-wrap:anywhere]">
              {n}
            </code>
          ))}
        </div>
      </details>
    ) : null
  return (
    <Section id="migration" title="In Appwrite" description={STATUS[l.status].note}>
      <div className="grid gap-4 rounded-lg border border-border bg-card p-4 text-[13px]">
        <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2">
          {l.classes.rust ? (
            <span>
              <b className="font-display text-[20px] font-medium tabular-nums">{formatCount(l.classes.rust)}</b> PHP classes served from Rust
            </span>
          ) : null}
          {l.classes.php ? (
            <span>
              <b className="font-display text-[20px] font-medium tabular-nums">{formatCount(l.classes.php)}</b> still PHP
            </span>
          ) : null}
          <SizeLine lib={l} />
        </div>
        {list('Served from Rust', classes.rust)}
        {list('Still PHP', classes.php)}
      </div>
    </Section>
  )
}

function LibraryPage() {
  const d = Route.useLoaderData()
  const index = root.useLoaderData()
  const l = d.library
  const category = index?.categories.find((c) => c.id === l.category)
  const migration = !!(l.classes.rust || l.classes.php)
  const toc = [
    ...(migration ? [{ id: 'migration', label: 'In Appwrite' }] : []),
    { id: 'install', label: 'Install' },
    ...(d.guide.length ? [{ id: 'getting-started', label: 'Getting started' }, ...guideHeadings(d.guide).map((h) => ({ id: slug(h), label: h, depth: 1 }))] : []),
    ...(d.overview || d.examples.length ? [{ id: 'overview', label: d.guide.length ? 'Reference' : 'Overview' }] : []),
    ...(d.modules.length ? [{ id: 'modules', label: 'Modules' }] : []),
    ...(d.root.length ? [{ id: 'api', label: 'API' }] : []),
  ]
  return (
    <WithToc toc={toc}>
      <PageHeader
        eyebrow={
          category ? (
            <Link to="/categories/$category" params={{ category: category.id }} className="hover:text-foreground">
              {category.title}
            </Link>
          ) : null
        }
        title={l.title}
        aside={<StatusBadge status={l.status} />}
      >
        {l.description}
        <Ecosystem l={l} />
      </PageHeader>

      {migration ? <Migration l={l} classes={d.classes} /> : null}

      <Section id="install" title="Install">
        <CodeBlock code={d.install.cargo} lang="bash" />
      </Section>

      {d.guide.length ? (
        <Section id="getting-started" title="Getting started" description={`Common tasks with ${l.title}, each a complete program you can copy and run.`}>
          <div className="grid gap-4">
            {d.guide.map((b, i) => ('text' in b ? <Markdown key={i} text={b.text} /> : <ExampleView key={i} example={b.example} />))}
          </div>
        </Section>
      ) : null}

      {d.overview || d.examples.length ? (
        <Section id="overview" title={d.guide.length ? 'Reference' : 'Overview'}>
          <Markdown text={d.overview} />
          <Examples examples={d.examples} />
        </Section>
      ) : null}

      {d.modules.length ? (
        <Section id="modules" title="Modules" description="Each module groups one concept of the library. Internal ones hold items the crate re-exports at its root.">
          <div className="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-3">
            {d.modules.map((m) => (
              <Link key={m.path} to="/$lib/$" params={{ lib: l.slug, _splat: moduleSplat(m.path) }} className="grid content-start gap-1.5 rounded-lg border border-border bg-card p-3.5 transition-colors hover:border-ring hover:bg-accent/40">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-mono text-[13px] font-medium">{m.path}</span>
                  <span className="text-[11px] text-muted-foreground">{m.public ? `${m.items} items` : 'internal'}</span>
                </div>
                <span className="line-clamp-3 text-[12.5px] text-muted-foreground">{m.summary || 'Undocumented.'}</span>
              </Link>
            ))}
          </div>
        </Section>
      ) : null}

      {d.root.length ? (
        <Section id="api" title="API" description={`What ${l.rust?.crate ?? l.slug} exports at its root.`}>
          {KIND_ORDER.map((k) => {
            const items = d.root.filter((i) => i.kind === k).sort((a, b) => a.name.localeCompare(b.name))
            return items.length ? (
              <div key={k} className="grid gap-2">
                <h3 className="text-[15px]">{KIND_TITLE[k]}</h3>
                <div className="overflow-hidden rounded-lg border border-border">
                  {items.map((i) => (
                    <Link key={i.name + i.module} to="/$lib/$" params={{ lib: l.slug, _splat: moduleSplat(i.module) }} hash={itemHash(i.item)} className="grid gap-x-4 border-b border-border px-3.5 py-2.5 transition-colors last:border-b-0 hover:bg-accent/40 md:grid-cols-[minmax(10rem,16rem)_minmax(0,1fr)]">
                      <span className="font-mono text-[13px] font-medium">{i.name}</span>
                      <span className="text-[13px] text-muted-foreground">{i.summary || 'Undocumented.'}</span>
                    </Link>
                  ))}
                </div>
              </div>
            ) : null
          })}
        </Section>
      ) : null}
    </WithToc>
  )
}
