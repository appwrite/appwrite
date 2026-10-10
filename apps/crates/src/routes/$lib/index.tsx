import { Link, createFileRoute, getRouteApi, notFound } from '@tanstack/react-router'
import { Check } from 'lucide-react'
import { CodeBlock, ExampleView, Examples } from '@/components/Code'
import { Markdown } from '@/components/Markdown'
import { PageHeader, Section, WithToc } from '@/components/Page'
import { StatusBadge } from '@/components/Status'
import { SyncBadge, SyncPanel } from '@/components/Sync'
import { KIND_ORDER, KIND_TITLE, STATUS, guideHeadings, isTopLevel, itemHash, loadLibrary, moduleSplat, slug, summary, type Status } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'
import { MigrationCard, MigrationOnly, PhpNotes } from '@/components/Migration'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/$lib/')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib) throw notFound()
    const top = lib.items.filter(isTopLevel)
    const classes = new Map<string, { name: string; doc: string; methods: number }>()
    for (const p of lib.php_api) {
      const c = p.class ?? `${p.namespace}\\functions`
      const entry = classes.get(c) ?? { name: c, doc: p.class_doc, methods: 0 }
      entry.methods++
      classes.set(c, entry)
    }
    return {
      library: lib.library,
      install: lib.install,
      overview: lib.overview,
      overview_notes: lib.overview_notes,
      guide: lib.guide,
      sync: lib.sync,
      examples: lib.examples,
      modules: lib.modules
        .filter((m) => m.path)
        .map((m) => ({ path: m.path, public: m.public, summary: summary(m.doc), items: top.filter((i) => i.module === m.path).length })),
      root: top.filter((i) => i.export || i.module === '').map((i) => ({ name: i.export ?? i.name, kind: i.kind, module: i.module, item: i.name, summary: summary(i.doc || i.inherited_doc) })),
      classes: [...classes.values()].sort((a, b) => a.name.localeCompare(b.name)).map((c) => ({ ...c, doc: summary(c.doc) })),
    }
  },
  component: LibraryPage,
})

const STEPS: Status[] = ['planned', 'started', 'progress', 'complete']

function Progress({ status }: { status: Status }) {
  const at = STEPS.indexOf(status)
  return (
    <ol className="m-0 grid list-none grid-cols-4 gap-2 p-0">
      {STEPS.map((s, i) => (
        <li key={s} className="grid gap-1.5">
          <span className={`h-1 rounded-full ${i <= at ? (status === 'complete' ? 'bg-ok' : 'bg-progress') : 'bg-muted'}`} />
          <span className={`inline-flex items-center gap-1 text-[11.5px] ${i === at ? 'font-medium text-foreground' : 'text-muted-foreground'}`}>
            {i < at || status === 'complete' ? <Check className="size-3" /> : null}
            {STATUS[s].label}
          </span>
        </li>
      ))}
    </ol>
  )
}

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="grid gap-0.5">
      <span className="text-[11px] tracking-wider text-muted-foreground uppercase">{label}</span>
      <span className="text-[13px] [overflow-wrap:anywhere]">{value}</span>
    </div>
  )
}

function LibraryPage() {
  const d = Route.useLoaderData()
  const index = root.useLoaderData()
  const migration = useMigration()
  const l = d.library
  const category = index?.categories.find((c) => c.id === l.category)
  const toc = [
    ...(migration ? [{ id: 'migration', label: 'Conversion status' }] : []),
    ...(l.rust ? [{ id: 'install', label: 'Install' }] : []),
    ...(d.guide.length ? [{ id: 'getting-started', label: 'Getting started' }, ...guideHeadings(d.guide).map((h) => ({ id: slug(h), label: h, depth: 1 }))] : []),
    ...(d.overview || d.examples.length || (migration && d.overview_notes) ? [{ id: 'overview', label: d.guide.length ? 'Reference' : 'Overview' }] : []),
    ...(d.modules.length ? [{ id: 'namespaces', label: 'Namespaces' }] : []),
    ...(d.root.length ? [{ id: 'api', label: 'API' }] : []),
    ...(migration && !l.rust && d.classes.length ? [{ id: 'php-api', label: 'PHP API' }] : []),
  ]
  const eyebrow = category ? (
    <Link to="/categories/$category" params={{ category: category.id }} className="hover:text-foreground">
      {category.title}
    </Link>
  ) : null
  // Without a Rust crate, a library is only part of the migration view.
  if (!l.rust && !migration)
    return (
      <div className="grid max-w-[72ch] gap-6">
        <PageHeader eyebrow={eyebrow} title={l.title}>
          {l.description}
        </PageHeader>
        <div className="rounded-lg border border-border bg-card p-4 text-[13.5px] text-muted-foreground">
          {l.title} is not available in Rust yet. Turn on <b className="font-medium text-foreground">Migration</b> to follow its conversion and read its PHP API.
        </div>
      </div>
    )
  return (
    <WithToc toc={toc}>
      <PageHeader
        eyebrow={eyebrow}
        title={l.title}
        aside={
          <MigrationOnly>
            <StatusBadge status={l.status} />
            <SyncBadge sync={l.sync} />
          </MigrationOnly>
        }
      >
        {l.description}
      </PageHeader>

      <MigrationCard id="migration" title="Conversion status">
        <p className="m-0 text-[13px] text-muted-foreground">{STATUS[l.status].note}</p>
        <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
          <Progress status={l.status} />
          <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-4">
            <Fact label="Rust crate" value={l.rust ? <code className="font-mono">{l.rust.name}</code> : 'Not yet'} />
            <Fact label="PHP package" value={l.php ? <code className="font-mono">{l.php.package}</code> : 'None (engine built-ins)'} />
            {l.compat ? <Fact label="Compared with PHP" value={`${l.compat.cases} cases over ${l.compat.operations} operations`} /> : null}
            {l.rust ? <Fact label="Documented" value={`${l.counts.documented} of ${l.counts.items} items`} /> : null}
            {l.rust ? <Fact label="Examples" value={l.counts.examples ? `${l.counts.examples}, Rust and PHP` : 'None yet'} /> : null}
            {l.counts.php_symbols ? <Fact label="PHP API mapped" value={`${l.counts.linked} of ${l.counts.php_symbols} methods`} /> : null}
          </div>
          {l.status === 'progress' && l.compat?.summary ? <p className="m-0 text-[13px] text-muted-foreground">{l.compat.summary}</p> : null}
        </div>
        <div className="grid gap-1 pt-1">
          <h3 className="text-[15px]">Sync with PHP</h3>
          <p className="m-0 text-[13px] text-muted-foreground">The PHP library keeps changing while it is ported. This compares the Rust crate with the PHP commits since it last matched them.</p>
        </div>
        <SyncPanel slug={l.slug} sync={d.sync} />
      </MigrationCard>

      {d.install.cargo ? (
        <Section id="install" title="Install" description="The crate lives in the Appwrite repository; depend on it from Git.">
          <CodeBlock code={d.install.cargo} lang="toml" />
        </Section>
      ) : null}
      {d.install.composer ? (
        <MigrationCard title="PHP package">
          <CodeBlock code={d.install.composer} lang="bash" migration />
        </MigrationCard>
      ) : null}

      {d.guide.length ? (
        <Section id="getting-started" title="Getting started" description={`Common tasks with ${l.title}, each a complete program you can copy and run.`}>
          <div className="grid gap-4">
            {d.guide.map((b, i) => ('text' in b ? <Markdown key={i} text={b.text} /> : <ExampleView key={i} example={b.example} />))}
          </div>
        </Section>
      ) : null}

      {d.overview || d.examples.length || (migration && d.overview_notes) ? (
        <Section id="overview" title={d.guide.length ? 'Reference' : 'Overview'}>
          <Markdown text={d.overview} />
          <PhpNotes notes={d.overview_notes} title={l.rust ? 'From PHP' : 'PHP library'} />
          <Examples examples={d.examples} />
        </Section>
      ) : null}

      {d.modules.length ? (
        <Section id="namespaces" title="Namespaces" description="Each namespace groups one concept of the library. Internal ones hold items the crate re-exports at its root.">
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

      {migration && !l.rust && d.classes.length ? (
        <MigrationCard id="php-api" title="PHP API">
          <p className="m-0 text-[13px] text-muted-foreground">The PHP library's public classes, read from its source. Each will get a Rust counterpart when the library is converted.</p>
          <div className="overflow-hidden rounded-lg border border-border">
            {d.classes.map((c) => (
              <Link key={c.name} to="/$lib/php/$" params={{ lib: l.slug, _splat: c.name.split('\\').join('/') }} className="grid gap-x-4 border-b border-border px-3.5 py-2.5 transition-colors last:border-b-0 hover:bg-accent/40 md:grid-cols-[minmax(12rem,20rem)_minmax(0,1fr)_auto]">
                <span className="font-mono text-[12.5px] font-medium [overflow-wrap:anywhere]">{c.name}</span>
                <span className="text-[13px] text-muted-foreground">{c.doc || 'Undocumented.'}</span>
                <span className="text-[11px] text-muted-foreground tabular-nums">{c.methods} methods</span>
              </Link>
            ))}
          </div>
        </MigrationCard>
      ) : null}
    </WithToc>
  )
}
