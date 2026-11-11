import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { Fragment, useState } from 'react'
import { Bar, ServiceBadge, SourceLink } from '@/components/Appwrite'
import { Measured } from '@/components/Bench'
import { BenchEnvironment, BenchProblems, BenchSteps, BenchSummary } from '@/components/BenchAppwrite'
import { Expand, TabProgress, TestState, chip } from '@/components/Dashboard'
import { Section } from '@/components/Page'
import { Generated, Time } from '@/components/Time'
import { SERVICE_STATUS, loadMigration, percent, type Benchmark, type ServiceStatus, type ServiceSummary } from '@/lib/appwrite'
import * as progress from '@/lib/progress'

export const Route = createFileRoute('/migration/services/')({
  loader: async () => {
    const m = await loadMigration()
    if (!m) throw notFound()
    // Everything but the per-service routes, tests and runs.
    const { details: _, hooks: __, ...overview } = m
    return overview
  },
  head: () => ({ meta: [{ title: 'Services · Migration · Utopia for Rust' }] }),
  component: Services,
  notFoundComponent: () => (
    <div className="text-muted-foreground">
      No migration data yet. Generate it with <code className="font-mono">bin/compat appwrite</code>.
    </div>
  ),
})

const ORDER: ServiceStatus[] = ['rust', 'partial', 'php']

/** Every API service, how much of it Rust serves, its E2E tests and its benchmark. */
function Services() {
  const m = Route.useLoaderData()
  const s = m.summary
  const [q, setQ] = useState('')
  const [status, setStatus] = useState<ServiceStatus | null>(null)
  const ref = m.branch ?? m.commit
  const term = q.trim().toLowerCase()
  const services = m.services
    .filter((x) => (!status || x.status === status) && (!term || `${x.title} ${x.id} ${x.description}`.toLowerCase().includes(term)))
    .sort((a, b) => ORDER.indexOf(a.status) - ORDER.indexOf(b.status) || a.title.localeCompare(b.title))
  return (
    <div className="grid min-w-0 gap-6">
      <TabProgress title="API endpoints served by Rust" progress={progress.services(s)}>
        {s.rust} of {s.services} services fully on Rust; {percent(s.sdk_served, s.sdk)}% of the {s.sdk} SDK endpoints; E2E suites run against Rust for{' '}
        {s.rust_suites} of {s.suites} suites ({s.tests.toLocaleString('en-US')} test methods in all); {s.bench_covered} of {s.endpoints} endpoints benchmarked.{' '}
        <Generated at={m.generated_at} commit={m.commit} commitDate={m.commit_date} label="Generated" />.
      </TabProgress>

      <div className="flex flex-wrap items-center gap-2">
        <label className="flex h-8 min-w-0 flex-[1_1_260px] items-center gap-2 rounded-md border border-border bg-card px-3 text-muted-foreground focus-within:border-ring sm:max-w-[360px]">
          <Search className="size-3.5 flex-none" />
          <input
            type="search"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Filter services"
            aria-label="Filter services"
            className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
          />
        </label>
        <button type="button" aria-pressed={!status} onClick={() => setStatus(null)} className={chip}>
          All <span className="tabular-nums">{m.services.length}</span>
        </button>
        {ORDER.filter((x) => m.services.some((v) => v.status === x)).map((x) => (
          <button key={x} type="button" aria-pressed={status === x} onClick={() => setStatus(status === x ? null : x)} className={chip} title={SERVICE_STATUS[x].note}>
            {SERVICE_STATUS[x].label} <span className="tabular-nums">{m.services.filter((v) => v.status === x).length}</span>
          </button>
        ))}
      </div>

      <div className="min-w-0 overflow-x-auto rounded-lg border border-border bg-card">
        <table className="w-full min-w-[860px] border-collapse text-[13px]">
          <thead>
            <tr className="border-b border-border text-left text-[11px] tracking-wider text-muted-foreground uppercase">
              <th className="px-3 py-2 font-semibold">Service</th>
              <th className="px-3 py-2 font-semibold">Status</th>
              <th className="w-[22%] px-3 py-2 font-semibold">Endpoints in Rust</th>
              <th className="px-3 py-2 font-semibold">E2E tests</th>
              <th className="px-3 py-2 font-semibold">Rust vs PHP</th>
              <th className="w-10 px-2 py-2" />
            </tr>
          </thead>
          <tbody>
            {services.map((x) => (
              <ServiceRow key={x.id} service={x} bench={m.benchmarks.find((b) => b.service === x.id)} />
            ))}
          </tbody>
        </table>
        {!services.length ? <p className="m-0 px-3 py-4 text-[13px] text-muted-foreground">No service matches.</p> : null}
      </div>
      {m.unassigned_suites.length ? (
        <p className="m-0 text-[12px] text-muted-foreground">Suites without an API service: {m.unassigned_suites.map((u) => `${u.name} (${u.tests} tests)`).join(', ')}.</p>
      ) : null}

      <Section
        id="routing"
        title="Routing"
        description={
          <>
            What the repository sends to the Rust API, and what the Rust binary serves.
            {m.updated?.routing ? (
              <>
                {' '}
                Routers last changed <Time date={m.updated.routing} />
                {m.updated.server ? (
                  <>
                    , the Rust binary <Time date={m.updated.server} />
                  </>
                ) : null}
                .
              </>
            ) : null}
          </>
        }
      >
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
          <p className="m-0 text-[12.5px] text-muted-foreground">Rust routes without a PHP route: {m.rust.unmatched.map((u) => `${u.method} ${u.path}`).join(', ')}.</p>
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
    </div>
  )
}

/** The service's E2E suites: how many test methods, whether CI runs them against Rust, and the latest recorded runs. */
function Tests({ tests }: { tests: ServiceSummary['tests'] }) {
  if (!tests.tests) return <TestState state="none">No suite</TestState>
  const rustFirst = (r: { runtime: string }) => (r.runtime === 'rust' ? 0 : 1)
  const runs = [...tests.latest].sort((a, b) => rustFirst(a) - rustFirst(b))
  return (
    <div className="grid gap-0.5">
      <span className="text-[12px] tabular-nums">
        {tests.tests.toLocaleString('en-US')} methods
        {tests.rust ? <span className="text-ok-ink" title="CI runs these suites against Rust"> · CI on Rust</span> : null}
      </span>
      {runs.map((r) => {
        const failed = r.failures + r.errors
        return (
          <TestState key={r.runtime} state={failed ? 'fail' : 'pass'} title={`${r.suite} against ${r.runtime === 'rust' ? 'Rust' : 'PHP'}${r.commit ? ` at ${r.commit}` : ''}`}>
            {r.runtime === 'rust' ? 'Rust' : 'PHP'} {r.passed}/{r.tests}
            <span className="text-muted-foreground">
              {' '}
              · <Time date={r.date} />
            </span>
          </TestState>
        )
      })}
      {!runs.length ? <span className="text-[11.5px] text-muted-foreground">No recorded run</span> : null}
    </div>
  )
}

function ServiceRow({ service: x, bench }: { service: ServiceSummary; bench: Benchmark | undefined }) {
  const [open, setOpen] = useState(false)
  return (
    <Fragment>
      <tr className={`border-b border-border align-top hover:bg-accent/40 ${open ? 'bg-accent/30' : ''}`}>
        <td className="px-3 py-2.5">
          <Link to="/migration/services/$service" params={{ service: x.id }} className="font-medium underline decoration-border underline-offset-2 hover:decoration-foreground">
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
            <span className="w-14 text-right text-[12px] text-muted-foreground tabular-nums">
              {x.counts.served}/{x.counts.endpoints}
            </span>
          </div>
          <div className="mt-0.5 text-[11.5px] text-muted-foreground tabular-nums">
            SDK {x.counts.sdk_served}/{x.counts.sdk}
            {x.counts.ready ? ` · ${x.counts.ready} not routed` : ''}
          </div>
        </td>
        <td className="px-3 py-2.5">
          <Tests tests={x.tests} />
        </td>
        <td className="px-3 py-2.5">
          <BenchSummary bench={bench} />
          <Measured brief date={x.bench?.date} stale={x.bench?.stale} changed={x.rust_changed} className="mt-0.5 block text-[11.5px]" />
        </td>
        <td className="px-2 py-2 text-right">{bench ? <Expand open={open} onClick={() => setOpen((o) => !o)} label={`${x.title} benchmark`} /> : null}</td>
      </tr>
      {open && bench ? (
        <tr className="border-b border-border bg-background/40">
          <td colSpan={6} className="px-3 py-4">
            <div className="grid min-w-0 gap-3">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="font-mono text-[11.5px] text-muted-foreground">{bench.workload.script}</span>
                <Measured date={bench.environment.date} commit={bench.environment.commit} machine={bench.environment.machine.cpu ?? bench.environment.machine.arch} stale={x.bench?.stale} changed={x.rust_changed} />
              </div>
              <BenchProblems bench={bench} />
              <BenchSteps bench={bench} />
              <BenchEnvironment bench={bench} />
              <Link to="/migration/services/$service" params={{ service: x.id }} hash="endpoints" className="justify-self-start text-[12px] text-muted-foreground hover:text-foreground hover:underline">
                Each endpoint's latency on the service's page
              </Link>
            </div>
          </td>
        </tr>
      ) : null}
    </Fragment>
  )
}
