import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { Performance } from '@/components/BenchLibrary'
import { PageHeader } from '@/components/Page'
import { loadLibrary } from '@/lib/docs'

export const Route = createFileRoute('/$lib/benchmarks')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib?.bench?.results.length) throw notFound()
    return { library: lib.library, bench: lib.bench }
  },
  head: ({ loaderData }) => ({ meta: [{ title: `Benchmarks vs PHP · ${loaderData?.library.title ?? 'Library'} · Utopia for Rust` }] }),
  component: BenchmarksPage,
  notFoundComponent: () => <div className="text-muted-foreground">This library has no benchmarks against PHP.</div>,
})

/** The library's benchmarks against its PHP package. Linkable in every mode; the menu shows it in migration mode. */
function BenchmarksPage() {
  const { library: l, bench } = Route.useLoaderData()
  return (
    <div className="grid min-w-0 gap-9">
      <PageHeader
        eyebrow={
          <Link to="/$lib" params={{ lib: l.slug }} className="hover:text-foreground">
            {l.title}
          </Link>
        }
        title="Benchmarks vs PHP"
      >
        The same work timed in each runtime, in-process: the median of {bench.results[0].rust.samples} samples per benchmark, with the CPU time and heap the driver
        spent on them. Lower is better.
      </PageHeader>
      <Performance bench={bench} stale={l.updated?.bench_stale} />
    </div>
  )
}
