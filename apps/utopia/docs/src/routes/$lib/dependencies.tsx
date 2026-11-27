import { Link, createFileRoute, getRouteApi, notFound } from '@tanstack/react-router'
import { ChevronRight, ExternalLink, Network } from 'lucide-react'
import type { ReactNode } from 'react'
import { PageHeader, Section, WithToc } from '@/components/Page'
import { badge } from '@/components/Status'
import { APPWRITE, loadLibrary, type Dependencies, type LibrarySummary, type RustDependency } from '@/lib/docs'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/$lib/dependencies')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib) throw notFound()
    return { library: lib.library, dependencies: lib.dependencies }
  },
  head: ({ loaderData }) => ({ meta: [{ title: `Dependencies · ${loaderData?.library.title ?? 'Library'} · Utopia for Rust` }] }),
  component: DependenciesPage,
})

const byName = (a: { name: string }, b: { name: string }) => a.name.localeCompare(b.name)

function Tag({ children, title }: { children: ReactNode; title?: string }) {
  return (
    <span title={title} className={`${badge} bg-muted text-muted-foreground`}>
      {children}
    </span>
  )
}

function Rows({ children }: { children: ReactNode }) {
  return <div className="grid divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">{children}</div>
}

function Empty({ children }: { children: ReactNode }) {
  return <p className="m-0 rounded-lg border border-border bg-card px-4 py-3 text-[13px] text-muted-foreground">{children}</p>
}

/** The version a dependency asks for, and the one `Cargo.lock` resolved. */
function Version({ d }: { d: RustDependency }) {
  return (
    <span className="inline-flex items-baseline gap-1.5 text-[12px] text-muted-foreground">
      {d.requirement ? <code className="font-mono text-foreground">{d.requirement}</code> : null}
      {d.locked && d.locked !== d.requirement ? <span title="Resolved in Cargo.lock">locked at {d.locked}</span> : null}
    </span>
  )
}

function RustRow({ d, libs, compact }: { d: RustDependency; libs: Map<string, LibrarySummary>; compact?: boolean }) {
  const utopia = d.utopia ? libs.get(d.utopia) : undefined
  return (
    <div className={`grid gap-x-4 gap-y-1 px-4 sm:grid-cols-[minmax(150px,220px)_minmax(0,1fr)] ${compact ? 'py-2' : 'py-2.5'}`}>
      <div className="grid min-w-0 content-start gap-0.5">
        {utopia ? (
          <>
            <Link to="/$lib" params={{ lib: utopia.slug }} className="truncate font-display text-[14px] font-medium hover:underline">
              {utopia.title}
            </Link>
            <span className="truncate font-mono text-[11px] text-muted-foreground">{d.package}</span>
          </>
        ) : (
          <a
            href={`https://crates.io/crates/${d.package}`}
            target="_blank"
            rel="noopener"
            className="inline-flex min-w-0 items-center gap-1 font-mono text-[13px] font-medium hover:underline"
          >
            <span className="truncate">{d.name}</span>
            <ExternalLink className="size-3 flex-none text-muted-foreground" />
          </a>
        )}
        {!utopia && d.package !== d.name ? <span className="truncate font-mono text-[11px] text-muted-foreground">{d.package}</span> : null}
      </div>
      <div className="grid min-w-0 content-start gap-1.5">
        <div className="flex flex-wrap items-center gap-1.5">
          {utopia ? null : <Version d={d} />}
          {d.optional ? <Tag title="Built only when a crate feature turns it on">optional{d.enabled_by.length ? ` · ${d.enabled_by.join(', ')}` : ''}</Tag> : null}
          {!d.default_features ? <Tag>no default features</Tag> : null}
          {d.target ? <Tag>{d.target}</Tag> : null}
          {d.features.map((f) => (
            <span key={f} title="Feature enabled" className="rounded border border-border px-1.5 py-0.5 font-mono text-[11px] text-foreground/80">
              {f}
            </span>
          ))}
        </div>
        {utopia && !compact ? <p className="m-0 line-clamp-2 max-w-[72ch] text-[12.5px] text-muted-foreground">{utopia.description}</p> : null}
      </div>
    </div>
  )
}

const card = 'grid min-w-0 content-start gap-0.5 rounded-lg border border-border bg-card px-3 py-2 transition-colors hover:border-ring hover:bg-accent/40'

/** A crate of this repository that depends on the library, and how when not at run time. */
function DependentCard({ slug, kinds, lib }: { slug: string; kinds: string[]; lib?: LibrarySummary }) {
  return (
    <Link to="/$lib" params={{ lib: slug }} className={card}>
      <span className="flex items-center justify-between gap-2">
        <span className="truncate text-[13.5px] font-medium">{lib?.title ?? slug}</span>
        {kinds.includes('normal') ? null : <Tag>{kinds.join(', ')}</Tag>}
      </span>
      <span className="truncate font-mono text-[11px] text-muted-foreground">{lib?.rust?.name ?? slug}</span>
    </Link>
  )
}

const KIND = { http: 'API', worker: 'Worker', schedule: 'Schedulers', realtime: 'Realtime', mqtt: 'MQTT' } as const

/** A part of Appwrite whose PHP files import the library, which the extension answers from Rust. */
function AppwriteCard({ kind, name, files }: Dependencies['appwrite'][number]) {
  return (
    <Link to="/map" hash={APPWRITE} className={card} title="Appwrite on the map">
      <span className="truncate text-[13.5px] font-medium">{kind === 'http' || kind === 'worker' ? `${KIND[kind]} · ${name}` : name}</span>
      <span className="truncate font-mono text-[11px] text-muted-foreground">
        {files} {files === 1 ? 'file' : 'files'}
      </span>
    </Link>
  )
}

const grid = 'grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-2'

/** Who depends on the library: the Rust crates of this repository, and the parts of Appwrite that import it. */
function UsedBy({ l, d, libs, name }: { l: LibrarySummary; d: Dependencies; libs: Map<string, LibrarySummary>; name: string }) {
  const rust = [...d.used_by].sort((a, b) => (libs.get(a.slug)?.title ?? a.slug).localeCompare(libs.get(b.slug)?.title ?? b.slug))
  const appwrite = [...d.appwrite].sort((a, b) => b.files - a.files || a.name.localeCompare(b.name))
  const line = [rust.length ? `${rust.length} Rust ${rust.length === 1 ? 'crate depends' : 'crates depend'} on ${name}` : '', appwrite.length ? `${appwrite.length} ${appwrite.length === 1 ? 'part' : 'parts'} of Appwrite use it` : '']
    .filter(Boolean)
    .join('; ')
  return (
    <Section id="used-by" title="Used by" description={line ? `${line}.` : `Nothing in the ecosystem depends on ${name} yet.`}>
      {rust.length ? (
        <div className="grid gap-2">
          <GroupLabel count={rust.length}>Rust crates</GroupLabel>
          <div className={grid}>
            {rust.map((u) => (
              <DependentCard key={u.slug} slug={u.slug} kinds={u.kinds} lib={libs.get(u.slug)} />
            ))}
          </div>
        </div>
      ) : null}
      {appwrite.length ? (
        <div className="grid gap-2">
          <GroupLabel count={appwrite.length}>Appwrite</GroupLabel>
          <div className={grid}>
            {appwrite.map((p) => (
              <AppwriteCard key={`${p.kind}.${p.name}`} {...p} />
            ))}
          </div>
        </div>
      ) : null}
      <div>
        <Link to="/map" hash={l.slug} className="inline-flex items-center gap-1 text-[13px] text-foreground hover:underline">
          <Network className="size-3.5 text-muted-foreground" />
          See it on the map
        </Link>
      </div>
    </Section>
  )
}

function GroupLabel({ count, children }: { count: number; children: ReactNode }) {
  return (
    <div className="flex items-center gap-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
      {children}
      <span className="font-normal tabular-nums">{count}</span>
    </div>
  )
}

function DependenciesPage() {
  const { library: l, dependencies: d } = Route.useLoaderData()
  const index = root.useLoaderData()
  const libs = new Map((index?.libraries ?? []).map((x) => [x.slug, x]))
  const name = l.rust?.crate ?? l.slug
  const eyebrow = (
    <Link to="/$lib" params={{ lib: l.slug }} className="hover:text-foreground">
      {l.title}
    </Link>
  )
  const usedBy = <UsedBy l={l} d={d} libs={libs} name={name} />

  const utopia = d.rust.filter((x) => x.utopia).sort(byName)
  const external = d.rust.filter((x) => !x.utopia).sort(byName)
  const tooling = [...d.build, ...d.dev].sort((a, b) => (a.kind === b.kind ? byName(a, b) : a.kind === 'build' ? -1 : 1))
  const toc = [
    { id: 'used-by', label: 'Used by' },
    ...(utopia.length ? [{ id: 'utopia', label: 'Utopia crates' }] : []),
    ...(external.length ? [{ id: 'third-party', label: 'Third-party crates' }] : []),
    ...(tooling.length ? [{ id: 'tooling', label: 'Build and dev' }] : []),
  ]

  return (
    <WithToc toc={toc}>
      <PageHeader eyebrow={eyebrow} title="Dependencies">
        What <code className="font-mono text-[0.9em] text-foreground">{name}</code> depends on, read from its <code className="font-mono text-[0.9em]">Cargo.toml</code> with versions
        from the workspace's <code className="font-mono text-[0.9em]">Cargo.lock</code>, and what depends on it across the Utopia ecosystem.
      </PageHeader>

      {usedBy}

      {!d.rust.length ? <Empty>{name} has no dependencies: it builds on the Rust standard library alone.</Empty> : null}

      {utopia.length ? (
        <Section id="utopia" title="Utopia crates" description="Other libraries of this repository it builds on.">
          <Rows>
            {utopia.map((x) => (
              <RustRow key={x.name} d={x} libs={libs} />
            ))}
          </Rows>
        </Section>
      ) : null}

      {external.length ? (
        <Section id="third-party" title="Third-party crates" description="Crates from crates.io, each with the version it asks for and the features it turns on.">
          <Rows>
            {external.map((x) => (
              <RustRow key={x.name} d={x} libs={libs} />
            ))}
          </Rows>
        </Section>
      ) : null}

      {tooling.length ? (
        <section id="tooling" className="grid scroll-mt-20 gap-3">
          <details className="group grid gap-3">
            <summary className="flex cursor-pointer list-none items-center gap-1.5 [&::-webkit-details-marker]:hidden">
              <ChevronRight className="size-4 text-muted-foreground transition-transform group-open:rotate-90" />
              <h2 className="text-xl">Build and dev</h2>
              <span className="text-[13px] text-muted-foreground">
                {[d.build.length ? `${d.build.length} build` : '', d.dev.length ? `${d.dev.length} dev` : ''].filter(Boolean).join(', ')}
              </span>
            </summary>
            <p className="m-0 mt-1 max-w-[72ch] text-[13px] text-muted-foreground">Build dependencies compile the crate itself; dev dependencies only run its tests, examples and benchmarks. Neither is linked into a program that uses the crate.</p>
            <div className="mt-3">
              <Rows>
                {tooling.map((x) => (
                  <div key={`${x.kind}.${x.name}`} className="grid sm:grid-cols-[52px_minmax(0,1fr)]">
                    <span className="hidden px-4 pt-2.5 text-[11px] tracking-wider text-muted-foreground uppercase sm:block">{x.kind}</span>
                    <RustRow d={x} libs={libs} compact />
                  </div>
                ))}
              </Rows>
            </div>
          </details>
        </section>
      ) : null}

    </WithToc>
  )
}
