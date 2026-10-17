import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { Fragment, useEffect, useState } from 'react'
import { Measured, SpeedupBadge } from '@/components/Bench'
import { Performance } from '@/components/BenchLibrary'
import { Expand, TabProgress, TestState, chip } from '@/components/Dashboard'
import { StatusBadge } from '@/components/Status'
import { Generated } from '@/components/Time'
import { STATUS, loadIndex, loadLibrary, type Bench, type LibrarySummary, type Status } from '@/lib/docs'
import * as progress from '@/lib/progress'

export const Route = createFileRoute('/migration/utopia')({
  loader: async () => {
    const index = await loadIndex()
    if (!index) throw notFound()
    return {
      libraries: index.libraries,
      categories: index.categories.map((c) => ({ id: c.id, title: c.title })),
      generated: { at: index.generated_at, commit: index.commit, commitDate: index.commit_date },
    }
  },
  head: () => ({ meta: [{ title: 'Utopia · Migration · Utopia for Rust' }] }),
  component: Utopia,
  notFoundComponent: () => (
    <div className="text-muted-foreground">
      No library data yet. Generate it with <code className="font-mono">bin/compat crates</code>.
    </div>
  ),
})

// Open work first, then what is done, then what is not converted by design.
const ORDER: Status[] = ['progress', 'started', 'planned', 'complete', 'retired']

/** Every Utopia library, its conversion, its proof against PHP and its benchmarks. */
function Utopia() {
  const { libraries, categories, generated } = Route.useLoaderData()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState<Status | null>(null)
  const term = q.trim().toLowerCase()
  const shown = libraries
    .filter((l) => (!status || l.status === status) && (!term || `${l.title} ${l.slug} ${l.rust?.name ?? ''} ${l.php?.package ?? ''} ${l.description}`.toLowerCase().includes(term)))
    .sort((a, b) => ORDER.indexOf(a.status) - ORDER.indexOf(b.status) || a.title.localeCompare(b.title))
  const proven = libraries.filter((l) => l.compat?.complete)
  const cases = libraries.reduce((a, l) => a + (l.compat?.cases ?? 0), 0)
  const benched = libraries.filter((l) => l.bench)
  const retired = libraries.filter((l) => l.status === 'retired').length
  return (
    <div className="grid min-w-0 gap-6">
      <TabProgress title="Utopia libraries converted" progress={progress.utopia(libraries)}>
        {proven.length} libraries proven against PHP by bin/compat over {cases.toLocaleString('en-US')} cases; {benched.length} benchmarked against PHP
        {retired ? `; ${retired} not converted by design` : ''}. <Generated at={generated.at} commit={generated.commit} commitDate={generated.commitDate} label="Generated" />.
      </TabProgress>

      <div className="flex flex-wrap items-center gap-2">
        <label className="flex h-8 min-w-0 flex-[1_1_260px] items-center gap-2 rounded-md border border-border bg-card px-3 text-muted-foreground focus-within:border-ring sm:max-w-[360px]">
          <Search className="size-3.5 flex-none" />
          <input
            type="search"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Filter libraries"
            aria-label="Filter libraries"
            className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
          />
        </label>
        <button type="button" aria-pressed={!status} onClick={() => setStatus(null)} className={chip}>
          All <span className="tabular-nums">{libraries.length}</span>
        </button>
        {ORDER.filter((x) => libraries.some((l) => l.status === x)).map((x) => (
          <button key={x} type="button" aria-pressed={status === x} onClick={() => setStatus(status === x ? null : x)} className={chip} title={STATUS[x].note}>
            {STATUS[x].label} <span className="tabular-nums">{libraries.filter((l) => l.status === x).length}</span>
          </button>
        ))}
      </div>

      <div className="min-w-0 overflow-x-auto rounded-lg border border-border bg-card">
        <table className="w-full min-w-[760px] border-collapse text-[13px]">
          <thead>
            <tr className="border-b border-border text-left text-[11px] tracking-wider text-muted-foreground uppercase">
              <th className="px-3 py-2 font-semibold">Library</th>
              <th className="px-3 py-2 font-semibold">Status</th>
              <th className="px-3 py-2 font-semibold">Tests against PHP</th>
              <th className="px-3 py-2 font-semibold">Rust vs PHP</th>
              <th className="w-10 px-2 py-2" />
            </tr>
          </thead>
          <tbody>
            {shown.map((l) => (
              <LibraryRow key={l.slug} lib={l} category={categories.find((c) => c.id === l.category)?.title} />
            ))}
          </tbody>
        </table>
        {!shown.length ? <p className="m-0 px-3 py-4 text-[13px] text-muted-foreground">No library matches.</p> : null}
      </div>
    </div>
  )
}

/** The proof against PHP: the compat cases, complete or with gaps left. */
function Tests({ lib }: { lib: LibrarySummary }) {
  const c = lib.compat
  if (lib.status === 'retired') return <TestState state="none">Not converted</TestState>
  if (!c || !c.cases) return <TestState state="none">No compat cases yet</TestState>
  return (
    <div className="grid gap-0.5">
      <TestState state={c.complete ? 'pass' : 'partial'} title={c.summary}>
        {c.cases.toLocaleString('en-US')} cases{c.complete ? ' identical' : ', gaps left'}
      </TestState>
      <span className="text-[11.5px] text-muted-foreground tabular-nums">{c.operations} operations</span>
    </div>
  )
}

function LibraryRow({ lib, category }: { lib: LibrarySummary; category?: string }) {
  const [open, setOpen] = useState(false)
  const [bench, setBench] = useState<Bench | null | undefined>(undefined)
  // The full results live in the library's own data: load them when the row opens.
  useEffect(() => {
    if (!open || bench !== undefined) return
    let live = true
    loadLibrary(lib.slug).then((l) => live && setBench(l?.bench ?? null))
    return () => {
      live = false
    }
  }, [open, bench, lib.slug])
  return (
    <Fragment>
      <tr className={`border-b border-border align-top hover:bg-accent/40 ${open ? 'bg-accent/30' : ''}`}>
        <td className="px-3 py-2.5">
          <Link to="/$lib" params={{ lib: lib.slug }} className="font-medium underline decoration-border underline-offset-2 hover:decoration-foreground">
            {lib.title}
          </Link>
          <div className="font-mono text-[11px] text-muted-foreground">
            {lib.rust?.name ?? lib.php?.package ?? lib.slug}
            {category ? <span className="font-sans"> · {category}</span> : null}
          </div>
        </td>
        <td className="px-3 py-2.5">
          <StatusBadge status={lib.status} />
        </td>
        <td className="px-3 py-2.5">
          <Tests lib={lib} />
        </td>
        <td className="px-3 py-2.5">
          {lib.bench ? (
            <div className="grid justify-items-start gap-0.5">
              <SpeedupBadge bench={lib.bench} />
              <Measured brief date={lib.updated?.bench} stale={lib.updated?.bench_stale} changed={lib.updated?.crate} className="text-[11.5px]" />
            </div>
          ) : (
            <span className="text-[12px] text-muted-foreground">–</span>
          )}
        </td>
        <td className="px-2 py-2 text-right">{lib.bench ? <Expand open={open} onClick={() => setOpen((o) => !o)} label={`${lib.title} benchmarks`} /> : null}</td>
      </tr>
      {open ? (
        <tr className="border-b border-border bg-background/40">
          <td colSpan={5} className="px-3 py-4">
            {bench === undefined ? (
              <p className="m-0 text-[12.5px] text-muted-foreground">Loading the results…</p>
            ) : bench ? (
              <div className="grid min-w-0 gap-2">
                <Performance bench={bench} stale={lib.updated?.bench_stale} />
                <Link to="/$lib/benchmarks" params={{ lib: lib.slug }} className="justify-self-start text-[12px] text-muted-foreground hover:text-foreground hover:underline">
                  Open the benchmarks page
                </Link>
              </div>
            ) : (
              <p className="m-0 text-[12.5px] text-muted-foreground">No results recorded.</p>
            )}
          </td>
        </tr>
      ) : null}
    </Fragment>
  )
}
