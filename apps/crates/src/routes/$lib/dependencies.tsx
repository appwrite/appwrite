import { Link, createFileRoute, getRouteApi, notFound } from '@tanstack/react-router'
import { ChevronRight, ExternalLink } from 'lucide-react'
import type { ReactNode } from 'react'
import { PageHeader, Section, WithToc } from '@/components/Page'
import { MigrationCard } from '@/components/Migration'
import { loadLibrary, type Dependencies, type LibrarySummary, type PhpDependency, type RustDependency } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'

const root = getRouteApi('__root__')

const NONE: Dependencies = { rust: [], dev: [], build: [], used_by: [], php: [] }

export const Route = createFileRoute('/$lib/dependencies')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib) throw notFound()
    return { library: lib.library, dependencies: lib.dependencies ?? NONE }
  },
  head: ({ loaderData }) => ({ meta: [{ title: `Dependencies · ${loaderData?.library.title ?? 'Library'} · Utopia for Rust` }] }),
  component: DependenciesPage,
})

const byName = (a: { name: string }, b: { name: string }) => a.name.localeCompare(b.name)

/** A manifest comment: plain text with `code`. */
function Inline({ text }: { text: string }) {
  return (
    <>
      {text.split('`').map((part, i) =>
        i % 2 ? (
          <code key={i} className="rounded bg-muted px-1 font-mono text-[0.92em]">
            {part}
          </code>
        ) : (
          part
        ),
      )}
    </>
  )
}

function Tag({ children, title }: { children: ReactNode; title?: string }) {
  return (
    <span title={title} className="rounded bg-muted px-1.5 py-0.5 text-[11px] whitespace-nowrap text-muted-foreground">
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
  if (d.path) return <span className="font-mono text-[12px] text-muted-foreground">{d.path}</span>
  if (d.git) return <span className="font-mono text-[12px] text-muted-foreground">{d.git}</span>
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
        {d.note && !compact ? (
          <p className="m-0 max-w-[72ch] text-[12.5px] text-muted-foreground">
            <Inline text={d.note.replace(/\n/g, ' ')} />
          </p>
        ) : null}
        {utopia && !compact ? <p className="m-0 line-clamp-2 max-w-[72ch] text-[12.5px] text-muted-foreground">{utopia.description}</p> : null}
      </div>
    </div>
  )
}

function PhpRow({ d, libs }: { d: PhpDependency; libs: Map<string, LibrarySummary> }) {
  const utopia = d.utopia ? libs.get(d.utopia) : undefined
  return (
    <div className="grid gap-x-4 gap-y-1 px-4 py-2 sm:grid-cols-[minmax(150px,260px)_minmax(0,1fr)]">
      {utopia ? (
        <Link to="/$lib" params={{ lib: utopia.slug }} className="truncate font-mono text-[12.5px] font-medium hover:underline">
          {d.name}
        </Link>
      ) : (
        <a href={`https://packagist.org/packages/${d.name}`} target="_blank" rel="noopener" className="inline-flex min-w-0 items-center gap-1 font-mono text-[12.5px] font-medium hover:underline">
          <span className="truncate">{d.name}</span>
          <ExternalLink className="size-3 flex-none text-muted-foreground" />
        </a>
      )}
      <span className="inline-flex flex-wrap items-baseline gap-1.5 text-[12px] text-muted-foreground">
        <code className="font-mono text-foreground">{d.requirement}</code>
        {d.locked ? <span title="Resolved in composer.lock">locked at {d.locked}</span> : null}
      </span>
    </div>
  )
}

function PhpRequirements({ deps, libs, package: name }: { deps: PhpDependency[]; libs: Map<string, LibrarySummary>; package: string }) {
  const platform = deps.filter((d) => d.kind === 'normal' && d.platform)
  const packages = deps.filter((d) => d.kind === 'normal' && !d.platform).sort(byName)
  const dev = deps.filter((d) => d.kind === 'dev').sort(byName)
  return (
    <MigrationCard id="php" title="PHP requirements">
      <p className="m-0 text-[13px] text-muted-foreground">
        What <code className="font-mono">{name}</code> requires in its <code className="font-mono">composer.json</code>.
      </p>
      {platform.length ? (
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-[11px] tracking-wider text-muted-foreground uppercase">Runtime</span>
          {platform.map((d) => (
            <span key={d.name} className="rounded border border-border bg-card px-1.5 py-0.5 font-mono text-[11.5px]">
              {d.name} {d.requirement}
            </span>
          ))}
        </div>
      ) : null}
      {packages.length ? (
        <Rows>
          {packages.map((d) => (
            <PhpRow key={d.name} d={d} libs={libs} />
          ))}
        </Rows>
      ) : (
        <Empty>No package requirements besides the runtime.</Empty>
      )}
      {dev.length ? (
        <details className="group grid gap-2">
          <summary className="flex cursor-pointer list-none items-center gap-1 text-[13px] text-muted-foreground hover:text-foreground">
            <ChevronRight className="size-3.5 transition-transform group-open:rotate-90" />
            {dev.length} development {dev.length === 1 ? 'requirement' : 'requirements'} (<code className="font-mono">require-dev</code>)
          </summary>
          <div className="mt-2">
            <Rows>
              {dev.map((d) => (
                <PhpRow key={d.name} d={d} libs={libs} />
              ))}
            </Rows>
          </div>
        </details>
      ) : null}
    </MigrationCard>
  )
}

function DependenciesPage() {
  const { library: l, dependencies: d } = Route.useLoaderData()
  const index = root.useLoaderData()
  const migration = useMigration()
  const libs = new Map((index?.libraries ?? []).map((x) => [x.slug, x]))
  const name = l.rust?.crate ?? l.php?.package ?? l.slug
  const eyebrow = (
    <Link to="/$lib" params={{ lib: l.slug }} className="hover:text-foreground">
      {l.title}
    </Link>
  )
  const php = migration && l.php ? <PhpRequirements deps={d.php} libs={libs} package={l.php.package} /> : null

  // Without a Rust crate, the only dependencies are the PHP package's: part of the migration view.
  if (!l.rust) {
    if (!migration)
      return (
        <div className="grid max-w-[72ch] gap-6">
          <PageHeader eyebrow={eyebrow} title="Dependencies" />
          <div className="rounded-lg border border-border bg-card p-4 text-[13.5px] text-muted-foreground">
            {l.title} is not available in Rust yet. Turn on <b className="font-medium text-foreground">Migration</b> to read the PHP package's requirements.
          </div>
        </div>
      )
    return (
      <WithToc>
        <PageHeader eyebrow={eyebrow} title="Dependencies">
          {l.title} is not converted to Rust yet; these are its PHP package's requirements.
        </PageHeader>
        {php ?? <Empty>No PHP package.</Empty>}
      </WithToc>
    )
  }

  const utopia = d.rust.filter((x) => x.utopia).sort(byName)
  const external = d.rust.filter((x) => !x.utopia).sort(byName)
  const tooling = [...d.build, ...d.dev].sort((a, b) => (a.kind === b.kind ? byName(a, b) : a.kind === 'build' ? -1 : 1))
  const users = d.used_by.map((u) => ({ ...u, lib: libs.get(u.slug) })).sort((a, b) => (a.lib?.title ?? a.slug).localeCompare(b.lib?.title ?? b.slug))
  const toc = [
    ...(utopia.length ? [{ id: 'utopia', label: 'Utopia crates' }] : []),
    ...(external.length ? [{ id: 'third-party', label: 'Third-party crates' }] : []),
    ...(tooling.length ? [{ id: 'tooling', label: 'Build and dev' }] : []),
    { id: 'used-by', label: 'Used by' },
  ]

  return (
    <WithToc toc={toc}>
      <PageHeader eyebrow={eyebrow} title="Dependencies">
        What <code className="font-mono text-[0.9em] text-foreground">{name}</code> depends on, read from its <code className="font-mono text-[0.9em]">Cargo.toml</code> with versions
        from the workspace's <code className="font-mono text-[0.9em]">Cargo.lock</code>, and the Utopia crates that depend on it.
      </PageHeader>

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

      <Section id="used-by" title="Used by" description="The Utopia crates that depend on this one.">
        {users.length ? (
          <div className="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-2">
            {users.map((u) => (
              <Link
                key={u.slug}
                to="/$lib"
                params={{ lib: u.slug }}
                className="grid gap-0.5 rounded-lg border border-border bg-card px-3 py-2 transition-colors hover:border-ring hover:bg-accent/40"
              >
                <span className="flex items-center justify-between gap-2">
                  <span className="truncate text-[13.5px] font-medium">{u.lib?.title ?? u.slug}</span>
                  {u.kinds.includes('normal') ? null : <Tag>{u.kinds.join(', ')}</Tag>}
                </span>
                <span className="truncate font-mono text-[11px] text-muted-foreground">{u.lib?.rust?.name ?? u.slug}</span>
              </Link>
            ))}
          </div>
        ) : (
          <Empty>No other Utopia crate depends on {name}.</Empty>
        )}
      </Section>

      {php}
    </WithToc>
  )
}
