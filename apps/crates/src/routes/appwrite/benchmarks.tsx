import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { BenchEnvironment, BenchRun, BenchSteps, MigrationGate } from '@/components/Appwrite'
import { Generated } from '@/components/Time'
import { PageHeader } from '@/components/Page'
import { loadMigration } from '@/lib/appwrite'

export const Route = createFileRoute('/appwrite/benchmarks')({
  loader: async () => {
    const m = await loadMigration()
    if (!m) throw notFound()
    return {
      benchmarks: m.benchmarks,
      services: m.services.map((s) => ({ id: s.id, title: s.title, stale: s.bench?.stale, rustChanged: s.rust_changed })),
      generated: { at: m.generated_at, commit: m.commit, commitDate: m.commit_date },
    }
  },
  head: () => ({ meta: [{ title: 'Benchmarks · Appwrite migration · Utopia for Rust' }] }),
  component: Benchmarks,
})

/** Each benchmarked service's k6 workload, run against the PHP and the Rust API on one stack. */
function Benchmarks() {
  const { benchmarks, services, generated } = Route.useLoaderData()
  return (
    <div className="mx-auto grid max-w-[1100px] grid-cols-[minmax(0,1fr)] gap-8">
      <PageHeader eyebrow="Appwrite migration" title="Benchmarks">
        The same k6 workload against the PHP and the Rust API on one stack. Each cell reads PHP / Rust; a service's page has its endpoints one by one.
        <div className="mt-2 text-[12px]">
          <Generated at={generated.at} commit={generated.commit} commitDate={generated.commitDate} label="Generated" />
        </div>
      </PageHeader>
      <MigrationGate>
        {benchmarks.length ? (
          benchmarks.map((b) => {
            const service = services.find((x) => x.id === b.service)
            return (
            <section key={b.file} id={b.service} className="grid min-w-0 scroll-mt-20 gap-2">
              <div className="flex flex-wrap items-baseline gap-2">
                <Link to="/appwrite/$service" params={{ service: b.service }} className="text-[15px] font-medium hover:underline">
                  {service?.title ?? b.service}
                </Link>
                <span className="font-mono text-[11.5px] text-muted-foreground">{b.workload.script}</span>
                <BenchRun date={b.environment.date} stale={service?.stale} rustChanged={service?.rustChanged} className="ml-auto" />
              </div>
              <BenchSteps bench={b} />
              <BenchEnvironment bench={b} />
            </section>
            )
          })
        ) : (
          <p className="m-0 text-[13px] text-muted-foreground">No benchmark results yet: tests/benchmarks/api/compare.sh writes them.</p>
        )}
      </MigrationGate>
    </div>
  )
}
