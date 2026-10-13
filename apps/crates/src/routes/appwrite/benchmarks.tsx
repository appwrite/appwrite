import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { BenchEnvironment, BenchSteps, MigrationGate } from '@/components/Appwrite'
import { PageHeader } from '@/components/Page'
import { loadMigration } from '@/lib/appwrite'

export const Route = createFileRoute('/appwrite/benchmarks')({
  loader: async () => {
    const m = await loadMigration()
    if (!m) throw notFound()
    return { benchmarks: m.benchmarks, services: m.services.map((s) => ({ id: s.id, title: s.title })) }
  },
  head: () => ({ meta: [{ title: 'Benchmarks · Appwrite migration · Utopia for Rust' }] }),
  component: Benchmarks,
})

/** Each benchmarked service's k6 workload, run against the PHP and the Rust API on one stack. */
function Benchmarks() {
  const { benchmarks, services } = Route.useLoaderData()
  return (
    <div className="mx-auto grid max-w-[1100px] grid-cols-[minmax(0,1fr)] gap-8">
      <PageHeader eyebrow="Appwrite migration" title="Benchmarks">
        The same k6 workload against the PHP and the Rust API on one stack. Each cell reads PHP / Rust; a service's page has its endpoints one by one.
      </PageHeader>
      <MigrationGate>
        {benchmarks.length ? (
          benchmarks.map((b) => (
            <section key={b.file} id={b.service} className="grid min-w-0 scroll-mt-20 gap-2">
              <div className="flex flex-wrap items-baseline gap-2">
                <Link to="/appwrite/$service" params={{ service: b.service }} className="text-[15px] font-medium hover:underline">
                  {services.find((x) => x.id === b.service)?.title ?? b.service}
                </Link>
                <span className="font-mono text-[11.5px] text-muted-foreground">{b.workload.script}</span>
              </div>
              <BenchSteps bench={b} />
              <BenchEnvironment bench={b} />
            </section>
          ))
        ) : (
          <p className="m-0 text-[13px] text-muted-foreground">No benchmark results yet: tests/benchmarks/api/compare.sh writes them.</p>
        )}
      </MigrationGate>
    </div>
  )
}
