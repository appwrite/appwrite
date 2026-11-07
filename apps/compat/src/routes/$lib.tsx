import { Link, Outlet, createFileRoute } from '@tanstack/react-router'
import { Chip, LibStatus, fmt } from '@/components/ui'
import { requireLib } from '@/lib/route'

export const Route = createFileRoute('/$lib')({
  loader: async ({ params }) => {
    const d = await requireLib(params.lib)
    return {
      summary: d.summary,
      description: d.spec.description,
      counts: {
        interface: d.interface.length,
        cases: d.cases.length,
        failing: d.cases.filter((c) => !c.ok).length,
        fuzz: d.fuzz.length,
        docs: `${d.docs.filter((x) => x.rust.length).length}/${d.docs.length}`,
        rust: d.rust_only.length,
      },
    }
  },
  head: ({ params }) => ({ meta: [{ title: `${params.lib} · Utopia Rust Parity` }] }),
  component: LibLayout,
  notFoundComponent: () => <div className="text-muted-foreground">This library is not in the report.</div>,
})

const TABS = [
  ['/$lib', 'Summary'],
  ['/$lib/interface', 'Interface'],
  ['/$lib/cases', 'Cases'],
  ['/$lib/fuzz', 'Fuzz'],
  ['/$lib/docs', 'Docs'],
  ['/$lib/rust', 'Rust-only API'],
] as const

function LibLayout() {
  const { summary, description, counts } = Route.useLoaderData()
  const { lib } = Route.useParams()
  const count: Record<string, string> = {
    '/$lib/interface': fmt(counts.interface),
    '/$lib/cases': `${fmt(counts.cases)}${counts.failing ? ` · ${counts.failing} failing` : ''}`,
    '/$lib/fuzz': fmt(counts.fuzz),
    '/$lib/docs': counts.docs,
    '/$lib/rust': fmt(counts.rust),
  }
  return (
    <>
      <section className="grid gap-2">
        <div className="flex items-center gap-1.5 text-[0.8rem] text-muted-foreground">
          <Link to="/" className="hover:text-foreground">
            Libraries
          </Link>
          <span>/</span>
          <span>{lib}</span>
        </div>
        <div className="flex flex-wrap items-center gap-2.5">
          <h2 className="text-2xl">{lib}</h2>
          <LibStatus lib={summary} />
          <Chip mono>{summary.crate}</Chip>
          {summary.php.map((p) => (
            <Chip key={p} mono>
              {p}
            </Chip>
          ))}
        </div>
        <p className="m-0 max-w-[72ch] text-muted-foreground">{description}</p>
        <nav className="flex gap-0.5 overflow-x-auto border-b border-border">
          {TABS.map(([to, name]) => (
            <Link
              key={to}
              to={to}
              params={{ lib }}
              activeOptions={{ exact: true, includeSearch: false }}
              className="whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-muted-foreground transition-colors hover:text-foreground data-[status=active]:border-foreground data-[status=active]:font-medium data-[status=active]:text-foreground"
            >
              {name}
              {count[to] ? <span className="ml-1 text-[0.7rem] text-muted-foreground">{count[to]}</span> : null}
            </Link>
          ))}
        </nav>
      </section>
      <section className="grid min-w-0 gap-3">
        <Outlet />
      </section>
    </>
  )
}
