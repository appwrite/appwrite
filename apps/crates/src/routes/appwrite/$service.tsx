import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { useState } from 'react'
import {
  Bar,
  BenchEnvironment,
  BenchSteps,
  DuelLegend,
  EndpointBench,
  HookRow,
  MethodBadge,
  MigrationGate,
  RouteStatusLabel,
  RunCard,
  ServiceBadge,
  SourceLink,
  Stat,
  StepPicker,
  useBenchStep,
} from '@/components/Appwrite'
import { MigrationCard, MigrationOnly } from '@/components/Migration'
import { PageHeader, Section } from '@/components/Page'
import { ROUTE_STATUS, SERVICE_STATUS, loadMigration, type RouteStatus } from '@/lib/appwrite'
import { sourceUrl } from '@/lib/docs'

export const Route = createFileRoute('/appwrite/$service')({
  loader: async ({ params }) => {
    const m = await loadMigration()
    const service = m?.services.find((s) => s.id === params.service)
    if (!m || !service) throw notFound()
    const detail = m.details[service.id]
    const groups = new Set(detail.endpoints.flatMap((e) => e.groups))
    return {
      service,
      ...detail,
      ref: m.branch ?? m.commit,
      // The hooks this service's routes run: by group, plus the ones every request runs.
      hooks: m.hooks.filter((h) => (h.kind === 'init' || h.kind === 'shutdown' ? h.groups.some((g) => groups.has(g)) : true)),
    }
  },
  head: ({ loaderData }) => ({ meta: [{ title: `${loaderData?.service.title ?? 'Service'} · Appwrite migration · Utopia for Rust` }] }),
  component: ServicePage,
  notFoundComponent: () => <div className="text-muted-foreground">No service by that name.</div>,
})

const chip =
  'inline-flex h-7 items-center gap-1.5 rounded-md border border-border px-2.5 text-[12.5px] text-muted-foreground transition-colors aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'
const STATUSES: RouteStatus[] = ['served', 'ready', 'missing', 'php']

function ServicePage() {
  const d = Route.useLoaderData()
  const s = d.service
  const [q, setQ] = useState('')
  const [status, setStatus] = useState<RouteStatus | null>(null)
  const term = q.trim().toLowerCase()
  const endpoints = d.endpoints.filter(
    (e) =>
      (!status || e.rust === status) &&
      (!term || [e.method, e.path, e.desc, ...e.sdk.map((m) => m.method), ...e.scopes, ...e.aliases.map((a) => a.path)].join(' ').toLowerCase().includes(term)),
  )
  const crates = [...new Set(d.endpoints.map((e) => e.rust_file?.split('/src/')[0]).filter(Boolean))] as string[]
  const bench = useBenchStep(d.benchmarks[0])
  const runs = [...d.results].sort((a, b) => (a.runtime === b.runtime ? b.date.localeCompare(a.date) : a.runtime === 'rust' ? -1 : 1))
  return (
    <div className="mx-auto grid max-w-[1100px] grid-cols-[minmax(0,1fr)] gap-10">
      <PageHeader
        eyebrow={
          <Link to="/appwrite" className="hover:text-foreground">
            Appwrite migration
          </Link>
        }
        title={s.title}
        aside={
          <MigrationOnly>
            <ServiceBadge status={s.status} />
          </MigrationOnly>
        }
      >
        {s.description || SERVICE_STATUS[s.status].note}
      </PageHeader>
      <MigrationGate>
        <MigrationCard title="Status">
          <div className="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-5 rounded-lg border border-border bg-card p-4">
            <Stat label="Endpoints served by Rust" value={s.counts.served} of={s.counts.endpoints}>
              <Bar
                total={s.counts.endpoints}
                parts={[
                  { value: s.counts.served, className: 'bg-ok' },
                  { value: s.counts.ready, className: 'bg-started' },
                  { value: s.counts.missing, className: 'bg-red-500' },
                ]}
              />
            </Stat>
            <Stat label="SDK endpoints" value={s.counts.sdk_served} of={s.counts.sdk}>
              <Bar total={s.counts.sdk} parts={[{ value: s.counts.sdk_served, className: 'bg-ok' }]} />
            </Stat>
            <Stat label="Endpoints benchmarked" value={s.counts.bench_covered} of={s.counts.endpoints}>
              <Bar
                total={s.counts.endpoints}
                parts={[
                  { value: s.counts.bench_covered, className: 'bg-ok' },
                  { value: s.counts.bench_skipped, className: 'bg-started' },
                ]}
              />
            </Stat>
            <Stat label="E2E test methods" value={s.tests.tests}>
              <span className="text-[12px] text-muted-foreground">
                {s.tests.suites.length ? `${s.tests.classes} classes in ${s.tests.suites.join(', ')}` : 'No suite'}
                {s.tests.rust ? ', run against Rust in CI' : ''}
              </span>
            </Stat>
          </div>
          <div className="grid min-w-0 gap-1 text-[12.5px]">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <span className="w-12 text-muted-foreground">PHP</span>
              {s.sources.map((p) => (
                <SourceLink key={p} ref={d.ref} file={p} />
              ))}
              {!s.registered ? <span className="text-muted-foreground">(module not registered in Appwrite.php)</span> : null}
            </div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <span className="w-12 text-muted-foreground">Rust</span>
              {crates.length ? crates.map((c) => <SourceLink key={c} ref={d.ref} file={c} />) : <span className="text-muted-foreground">No routes yet</span>}
            </div>
          </div>
        </MigrationCard>

        <Section id="endpoints" title="Endpoints" description="Every route the PHP code declares, whether the Rust API serves it, and how the two compare under the same load.">
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex h-8 min-w-0 flex-[1_1_260px] items-center gap-2 rounded-md border border-border bg-card px-3 text-muted-foreground focus-within:border-ring">
              <Search className="size-3.5 flex-none" />
              <input
                type="search"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Filter by path, SDK method or scope"
                aria-label="Filter endpoints"
                className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
              />
            </label>
            <button type="button" aria-pressed={!status} onClick={() => setStatus(null)} className={chip}>
              All <span className="tabular-nums">{d.endpoints.length}</span>
            </button>
            {STATUSES.filter((x) => d.endpoints.some((e) => e.rust === x)).map((x) => (
              <button key={x} type="button" aria-pressed={status === x} onClick={() => setStatus(status === x ? null : x)} className={chip} title={ROUTE_STATUS[x].note}>
                {ROUTE_STATUS[x].label} <span className="tabular-nums">{d.endpoints.filter((e) => e.rust === x).length}</span>
              </button>
            ))}
          </div>
          {bench.step ? (
            <div className="flex flex-wrap items-center justify-between gap-2">
              <StepPicker rows={bench.rows} at={bench.at} setAt={bench.setAt} />
              <DuelLegend />
            </div>
          ) : null}
          <div className="min-w-0 overflow-x-auto rounded-lg border border-border bg-card">
            <table className="w-full border-collapse text-[13px]">
              <thead>
                <tr className="border-b border-border text-left text-[11px] tracking-wider text-muted-foreground uppercase">
                  <th className="px-3 py-2 font-semibold">Endpoint</th>
                  <th className="px-3 py-2 font-semibold">Status</th>
                  <th className="px-3 py-2 font-semibold">{bench.step?.rust ? 'PHP vs Rust' : 'Benchmark'}</th>
                </tr>
              </thead>
              <tbody>
                {endpoints.map((e) => (
                  <tr key={`${e.method} ${e.path}`} className="border-b border-border align-top last:border-b-0">
                    <td className="px-3 py-2.5">
                      <div className="flex items-start gap-2">
                        <MethodBadge method={e.method} />
                        <div className="grid min-w-0 gap-0.5">
                          <a
                            href={sourceUrl(d.ref, e.file, e.line)}
                            target="_blank"
                            rel="noopener"
                            className="font-mono text-[12.5px] [overflow-wrap:anywhere] hover:underline"
                            title="The PHP action"
                          >
                            {e.path}
                          </a>
                          {e.desc ? <span className="text-[12px]">{e.desc}</span> : null}
                          <span className="flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-[11px] text-muted-foreground">
                            {e.sdk.map((m) => (
                              <span key={m.method} title={m.visibility === 'visible' ? 'SDK method' : `SDK visibility: ${m.visibility}`}>
                                {m.method}
                                {m.deprecated ? ' (deprecated)' : ''}
                              </span>
                            ))}
                            {e.scopes.length ? <span title="Scope">{e.scopes.join(', ')}</span> : null}
                          </span>
                          {e.aliases.map((a) => (
                            <span key={a.path} className="font-mono text-[11px] [overflow-wrap:anywhere] text-muted-foreground" title="Alias">
                              alias {a.path}
                            </span>
                          ))}
                        </div>
                      </div>
                    </td>
                    <td className="px-3 py-2.5">
                      <RouteStatusLabel status={e.rust} />
                      {e.rust_file ? (
                        <div>
                          <SourceLink ref={d.ref} file={e.rust_file} line={e.rust_line}>
                            {e.rust_file.replace('crates/appwrite/', '')}:{e.rust_line}
                          </SourceLink>
                        </div>
                      ) : null}
                    </td>
                    <td className="px-3 py-2.5">
                      <EndpointBench step={bench.step} route={`${e.method} ${e.path}`} bench={e.bench} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!endpoints.length ? <p className="m-0 px-3 py-4 text-[13px] text-muted-foreground">No endpoint matches.</p> : null}
          </div>
        </Section>

        <Section id="tests" title="Tests" description="The PHP E2E suites are the contract: a service counts as migrated when they pass against Rust.">
          {d.suites.length ? (
            <div className="grid min-w-0 gap-3">
              {d.suites.map((suite) => (
                <div key={suite.name} className="grid gap-2 rounded-lg border border-border bg-card p-3">
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="text-[13.5px] font-medium">{suite.name}</span>
                    <span className="text-[12px] text-muted-foreground tabular-nums">
                      {suite.tests} test methods in {suite.classes.length} classes
                    </span>
                    {suite.rust.length ? (
                      <span className="inline-flex items-center gap-1.5 rounded-full bg-ok-soft px-2 py-0.5 text-[11px] font-medium text-ok-ink" title={`${suite.rust[0].workflow}, job ${suite.rust[0].job}`}>
                        CI runs it against Rust
                      </span>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">CI runs it against PHP</span>
                    )}
                    <span className="ml-auto">
                      <SourceLink ref={d.ref} file={suite.dir} />
                    </span>
                  </div>
                  <div className="flex flex-wrap gap-x-4 gap-y-0.5 text-[12px] text-muted-foreground">
                    {suite.classes.map((c) => (
                      <span key={c.name} className="tabular-nums">
                        <span className="font-mono">{c.name}</span> {c.tests}
                      </span>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="m-0 text-[13px] text-muted-foreground">No E2E suite in tests/e2e/Services covers this service.</p>
          )}
          {runs.length ? (
            <div className="grid min-w-0 gap-2">
              <h3 className="text-[14px]">Recorded runs</h3>
              {runs.map((r) => (
                <RunCard key={`${r.suite}${r.runtime}`} run={r} />
              ))}
              <p className="m-0 text-[11.5px] text-muted-foreground">
                Recorded with <code className="font-mono">bin/compat appwrite e2e {runs[0].suite} --write</code> on the dev stack (add <code className="font-mono">--side php</code> for PHP).
              </p>
            </div>
          ) : d.suites.length ? (
            <p className="m-0 text-[12.5px] text-muted-foreground">
              No recorded run yet: <code className="font-mono">bin/compat appwrite e2e {d.suites[0].name} --write</code> records one from the dev stack.
            </p>
          ) : null}
        </Section>

        {d.benchmarks.length ? (
          <Section id="benchmarks" title="Benchmarks" description="The same k6 workload against PHP and Rust on one stack: at fixed request rates (equal load: compare latency, CPU and memory) and closed loop (as fast as each can: compare throughput). Per endpoint results are in the Endpoints table.">
            {d.benchmarks.map((b) => (
              <div key={b.file} className="grid min-w-0 gap-4">
                <BenchSteps bench={b} />
                <BenchEnvironment bench={b} />
              </div>
            ))}
          </Section>
        ) : s.counts.served ? (
          <Section id="benchmarks" title="Benchmarks">
            <p className="m-0 text-[12.5px] text-muted-foreground">
              No benchmark recorded yet. On a dev stack with the Rust API,{' '}
              <code className="font-mono">RESULTS=tests/benchmarks/results/{s.id}.json tests/benchmarks/{s.id}-compare.sh</code> records one.
            </p>
          </Section>
        ) : null}

        <Section
          id="hooks"
          title="Cross-cutting behaviour"
          description="The hooks this service's routes run (by group, and the ones every request runs) and the request resources, with where Rust stands."
        >
          <ul className="m-0 min-w-0 list-none rounded-lg border border-border bg-card p-0">
            {d.hooks.map((h) => (
              <HookRow key={h.id} hook={h} gitRef={d.ref} />
            ))}
          </ul>
        </Section>
      </MigrationGate>
    </div>
  )
}
