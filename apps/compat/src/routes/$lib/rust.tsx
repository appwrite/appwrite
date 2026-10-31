import { createFileRoute, getRouteApi } from '@tanstack/react-router'
import { Chip, Empty, Expand, List, Pill, Source } from '@/components/ui'
import { sourceUrl } from '@/lib/report'
import { requireLib } from '@/lib/route'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/$lib/rust')({
  loader: async ({ params }) => (await requireLib(params.lib)).rust_only,
  component: RustOnly,
})

function RustOnly() {
  const items = Route.useLoaderData()
  const index = root.useLoaderData()
  return (
    <>
      <p className="m-0 max-w-[80ch] text-muted-foreground">
        Public Rust items no PHP symbol links to: types that model PHP values, helpers PHP keeps private, and API Rust adds. Review them for anything
        that ports a PHP symbol without saying so.
      </p>
      <List>
        {items.length ? (
          items.map((r) => (
            <Expand
              key={r.path + r.line}
              summary={
                <>
                  <Chip>{r.kind}</Chip>
                  <span className="font-mono text-xs font-medium">{r.path}</span>
                  <span className="flex-auto" />
                  {r.doc ? null : <Pill tone="warn">undocumented</Pill>}
                </>
              }
            >
              {() => (
                <div className="grid min-w-0 gap-1.5 rounded-[10px] border border-border px-3 py-2.5">
                  <div className="overflow-x-auto whitespace-pre rounded-md bg-muted px-2 py-1.5 font-mono text-[0.76rem]">{r.signature}</div>
                  {r.doc ? <div className="whitespace-pre-wrap text-[0.82rem]">{r.doc}</div> : <div className="italic text-muted-foreground">No doc comment.</div>}
                  <Source href={sourceUrl(index, r.file, r.line)} label={`${r.file}:${r.line}`} />
                </div>
              )}
            </Expand>
          ))
        ) : (
          <Empty>Every public Rust item links to a PHP symbol.</Empty>
        )}
      </List>
    </>
  )
}
