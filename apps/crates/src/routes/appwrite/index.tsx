import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { ChevronRight, Search } from 'lucide-react'
import { useState } from 'react'
import { Bar, MigrationGate, ServiceBadge, ServiceSpeed, SourceLink, Stat } from '@/components/Appwrite'
import { MigrationCard } from '@/components/Migration'
import { PageHeader, Section } from '@/components/Page'
import { SERVICE_STATUS, loadMigration, percent, type ServiceStatus } from '@/lib/appwrite'

export const Route = createFileRoute('/appwrite/')({
  loader: async () => {
    const m = await loadMigration()
    if (!m) throw notFound()
    // Everything but the per-service routes, tests and runs.
    const { details: _, ...overview } = m
    return overview
  },
  head: () => ({ meta: [{ title: 'Appwrite migration · Utopia for Rust' }] }),
  component: Overview,
  notFoundComponent: () => (
    <div className="text-muted-foreground">
      No migration data yet. Generate it with <code className="font-mono">bin/compat appwrite</code>.
    </div>
  ),
})

const ORDER: ServiceStatus[] = ['rust', 'partial', 'php']

function Overview() {
  const m = Route.useLoaderData()
  const s = m.summary
  const [q, setQ] = useState('')
  const ref = m.branch ?? m.commit
  const term = q.trim().toLowerCase()
  const services = m.services.filter((x) => !term || `${x.title} ${x.id} ${x.description}`.toLowerCase().includes(term))
  return (
    <div className="mx-auto grid max-w-[1100px] grid-cols-[minmax(0,1fr)] gap-10">
      <PageHeader eyebrow="Migration" title="Appwrite API in Rust">
        The Appwrite API moves from PHP to Rust one service at a time. Traefik sends the paths of a migrated service to the Rust API; both runtimes share
        the same database, caches and queues, and the PHP E2E suite is the contract.
      </PageHeader>
      <MigrationGate>
        <MigrationCard title="Progress">
          <div className="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-5 rounded-lg border border-border bg-card p-4">
            <Stat label="Endpoints served by Rust" value={s.served} of={s.endpoints}>
              <Bar total={s.endpoints} parts={[{ value: s.served, className: 'bg-ok' }, { value: s.ready, className: 'bg-started', title: 'Implemented, not routed' }]} />
            </Stat>
            <Stat label="SDK endpoints" value={s.sdk_served} of={s.sdk}>
              <Bar total={s.sdk} parts={[{ value: s.sdk_served, className: 'bg-ok' }]} />
            </Stat>
            <Stat label="Endpoints benchmarked" value={s.bench_covered} of={s.endpoints}>
              <Bar
                total={s.endpoints}
                parts={[
                  { value: s.bench_covered, className: 'bg-ok' },
                  { value: s.bench_skipped, className: 'bg-started' },
                ]}
              />
            </Stat>
            <Stat label="Services" value={s.rust} of={s.services}>
              <Bar
                total={s.services}
                parts={[
                  { value: s.rust, className: 'bg-ok', title: SERVICE_STATUS.rust.label },
                  { value: s.partial, className: 'bg-progress', title: SERVICE_STATUS.partial.label },
                ]}
              />
            </Stat>
            <Stat label="E2E suites against Rust" value={s.rust_suites} of={s.suites}>
              <Bar total={s.suites} parts={[{ value: s.rust_suites, className: 'bg-ok' }]} />
            </Stat>
            <Stat label="Hooks done, needed now" value={s.hooks.done_needed} of={s.hooks.needed}>
              <Bar
                total={s.hooks.needed}
                parts={[
                  { value: s.hooks.done_needed, className: 'bg-ok' },
                  { value: m.hooks.filter((h) => h.needed && h.rust.status === 'partial').length, className: 'bg-progress', title: 'Partly' },
                ]}
              />
            </Stat>
          </div>
          <p className="m-0 text-[12.5px] text-muted-foreground">
            {percent(s.served, s.endpoints)}% of the API's {s.endpoints} routes and {percent(s.sdk_served, s.sdk)}% of its {s.sdk} SDK routes are served by Rust.
            {s.missing ? ` ${s.missing} routes are sent to Rust but missing there.` : ''} Generated from commit <code className="font-mono">{m.commit}</code>.
          </p>
        </MigrationCard>

        <Section
          id="services"
          title="Services"
          description="Every module in src/Appwrite/Platform/Modules and every service still in app/controllers, with its routes, its E2E suites and where Rust stands."
        >
          <label className="flex h-9 max-w-[360px] items-center gap-2 rounded-md border border-border bg-card px-3 text-muted-foreground focus-within:border-ring">
            <Search className="size-4 flex-none" />
            <input
              type="search"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Filter services"
              aria-label="Filter services"
              className="min-w-0 flex-1 bg-transparent text-[13.5px] text-foreground outline-none placeholder:text-muted-foreground"
            />
          </label>
          <div className="min-w-0 overflow-x-auto rounded-lg border border-border bg-card">
            <table className="w-full border-collapse text-[13px]">
              <thead>
                <tr className="border-b border-border text-left text-[11px] tracking-wider text-muted-foreground uppercase">
                  <th className="px-3 py-2 font-semibold">Service</th>
                  <th className="px-3 py-2 font-semibold">Status</th>
                  <th className="w-[26%] px-3 py-2 font-semibold">Endpoints in Rust</th>
                  <th className="px-3 py-2 text-right font-semibold">SDK</th>
                  <th className="px-3 py-2 text-right font-semibold">E2E test methods</th>
                  <th className="px-3 py-2 font-semibold">Rust vs PHP</th>
                  <th className="px-3 py-2" />
                </tr>
              </thead>
              <tbody>
                {[...services]
                  .sort((a, b) => ORDER.indexOf(a.status) - ORDER.indexOf(b.status) || a.title.localeCompare(b.title))
                  .map((x) => (
                    <tr key={x.id} className="border-b border-border align-top last:border-b-0 hover:bg-accent/40">
                      <td className="px-3 py-2.5">
                        <Link to="/appwrite/$service" params={{ service: x.id }} className="font-medium underline decoration-border underline-offset-2 hover:decoration-foreground">
                          {x.title}
                        </Link>
                        <div className="font-mono text-[11px] text-muted-foreground">{x.sources.map((p) => p.replace('src/Appwrite/Platform/Modules/', 'Modules/').replace('app/controllers/', 'controllers/')).join(' · ')}</div>
                      </td>
                      <td className="px-3 py-2.5">
                        <ServiceBadge status={x.status} />
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="flex items-center gap-2">
                          <Bar
                            className="h-1.5 flex-1"
                            total={x.counts.endpoints}
                            parts={[
                              { value: x.counts.served, className: 'bg-ok' },
                              { value: x.counts.ready, className: 'bg-started' },
                              { value: x.counts.missing, className: 'bg-red-500' },
                            ]}
                          />
                          <span className="w-16 text-right text-[12px] text-muted-foreground tabular-nums">
                            {x.counts.served}/{x.counts.endpoints}
                          </span>
                        </div>
                      </td>
                      <td className="px-3 py-2.5 text-right text-[12px] text-muted-foreground tabular-nums">
                        {x.counts.sdk_served}/{x.counts.sdk}
                      </td>
                      <td className="px-3 py-2.5 text-right text-[12px] tabular-nums">
                        {x.tests.tests ? (
                          <span className={x.tests.rust ? 'text-ok-ink' : 'text-muted-foreground'} title={x.tests.rust ? 'CI runs these suites against Rust' : 'Run against PHP'}>
                            {x.tests.tests}
                            {x.tests.rust ? ' · Rust' : ''}
                          </span>
                        ) : (
                          <span className="text-muted-foreground">–</span>
                        )}
                      </td>
                      <td className="px-3 py-2.5">
                        <ServiceSpeed bench={m.benchmarks.find((b) => b.service === x.id)} />
                      </td>
                      <td className="px-3 py-2.5 text-right">
                        <Link
                          to="/appwrite/$service"
                          params={{ service: x.id }}
                          className="inline-flex items-center gap-0.5 text-[12px] whitespace-nowrap text-muted-foreground hover:text-foreground"
                        >
                          Endpoints
                          <ChevronRight className="size-3.5" />
                        </Link>
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
          {m.unassigned_suites.length ? (
            <p className="m-0 text-[12px] text-muted-foreground">
              Suites without an API service: {m.unassigned_suites.map((u) => `${u.name} (${u.tests} tests)`).join(', ')}.
            </p>
          ) : null}
        </Section>

        <div className="grid gap-3 sm:grid-cols-2">
          <Link to="/appwrite/behaviour" className="group grid gap-1 rounded-lg border border-border bg-card p-4 transition-colors hover:border-ring">
            <span className="flex items-center justify-between text-[14px] font-medium">
              Cross-cutting behaviour
              <ChevronRight className="size-4 text-muted-foreground group-hover:text-foreground" />
            </span>
            <span className="text-[12.5px] text-muted-foreground">
              The PHP hooks and resources every migrated route must reproduce: {s.hooks.done_needed} of the {s.hooks.needed} needed now are done,{' '}
              {s.hooks.total} in all.
            </span>
          </Link>
          <Link to="/appwrite/benchmarks" className="group grid gap-1 rounded-lg border border-border bg-card p-4 transition-colors hover:border-ring">
            <span className="flex items-center justify-between text-[14px] font-medium">
              Benchmarks
              <ChevronRight className="size-4 text-muted-foreground group-hover:text-foreground" />
            </span>
            <span className="text-[12.5px] text-muted-foreground">
              {m.benchmarks.length
                ? `The same k6 workload on PHP and Rust, step by step: ${m.benchmarks.length} ${m.benchmarks.length === 1 ? 'service' : 'services'}, ${s.bench_covered} of ${s.endpoints} endpoints covered.`
                : 'No results yet.'}
            </span>
          </Link>
        </div>

        <Section id="routing" title="Routing" description="What the repository sends to the Rust API, and what the Rust binary serves.">
          <div className="grid min-w-0 gap-2">
            {m.routing.map((r) => (
              <div key={`${r.file}${r.name}`} className="grid gap-1 rounded-lg border border-border bg-card p-3">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                  <span className="font-mono text-[12.5px] font-medium">{r.name}</span>
                  {r.profile ? <span className="text-[12px] text-muted-foreground">compose profile {r.profile}</span> : null}
                  {r.priority ? <span className="text-[12px] text-muted-foreground">priority {r.priority}</span> : null}
                  <span className="ml-auto">
                    <SourceLink ref={ref} file={r.file} line={r.line} />
                  </span>
                </div>
                <code className="font-mono text-[11.5px] [overflow-wrap:anywhere] text-muted-foreground">{r.rule}</code>
              </div>
            ))}
            {!m.routing.length ? <p className="m-0 text-[13px] text-muted-foreground">No Traefik router sends requests to the Rust API.</p> : null}
          </div>
          <div className="flex flex-wrap gap-x-5 gap-y-1 text-[12.5px] text-muted-foreground">
            {m.rust.crates.map((c) => (
              <span key={c.dir}>
                <code className="font-mono">{c.dir}</code> {c.routes} routes{c.registered ? '' : ', not registered'}
              </span>
            ))}
          </div>
          {m.rust.unmatched.length ? (
            <p className="m-0 text-[12.5px] text-muted-foreground">
              Rust routes without a PHP route: {m.rust.unmatched.map((u) => `${u.method} ${u.path}`).join(', ')}.
            </p>
          ) : null}
          {m.warnings.length ? (
            <details className="text-[12.5px] text-muted-foreground">
              <summary className="cursor-pointer">{m.warnings.length} generation warnings</summary>
              <ul className="m-0 mt-1 grid gap-0.5 pl-4">
                {m.warnings.map((w) => (
                  <li key={w} className="font-mono text-[11.5px]">
                    {w}
                  </li>
                ))}
              </ul>
            </details>
          ) : null}
        </Section>
      </MigrationGate>
    </div>
  )
}
