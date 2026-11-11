import { createFileRoute, getRouteApi } from '@tanstack/react-router'
import { Chip, Dot, Empty, Expand, List, More, Pill, Search, Segmented, Source, docblock } from '@/components/ui'
import { PAGE, sourceUrl, type DocEntry } from '@/lib/report'
import { listSearch, requireLib } from '@/lib/route'

const root = getRouteApi('__root__')

type State = 'both' | 'unlinked' | 'rust-undocumented' | 'php-undocumented'

function state(x: DocEntry): State {
  if (!x.rust.length) return 'unlinked'
  if (!x.rust.some((r) => r.doc || r.inherited_doc)) return 'rust-undocumented'
  if (!docblock(x.php?.doc)) return 'php-undocumented'
  return 'both'
}

export const Route = createFileRoute('/$lib/docs')({
  validateSearch: listSearch,
  loaderDeps: ({ search }) => search,
  loader: async ({ params, deps }) => {
    const d = await requireLib(params.lib)
    const counts: Record<string, number> = { all: d.docs.length, both: 0, unlinked: 0, 'rust-undocumented': 0, 'php-undocumented': 0 }
    for (const x of d.docs) counts[state(x)]++
    const term = (deps.q ?? '').toLowerCase()
    const rows = d.docs.filter((x) => {
      if (deps.filter && state(x) !== deps.filter) return false
      if (!term) return true
      return [x.symbol, x.php?.signature, x.php?.doc, ...x.rust.map((r) => `${r.path} ${r.signature} ${r.doc}`)].join(' ').toLowerCase().includes(term)
    })
    return { counts, total: rows.length, rows: rows.slice(0, deps.limit ?? PAGE) }
  },
  component: Docs,
})

const LABEL: Record<State, [string, 'ok' | 'bad' | 'warn' | 'info']> = {
  both: ['documented in both', 'ok'],
  unlinked: ['no Rust counterpart', 'bad'],
  'rust-undocumented': ['Rust undocumented', 'warn'],
  'php-undocumented': ['PHP undocumented', 'info'],
}

function Docs() {
  const { counts, total, rows } = Route.useLoaderData()
  const search = Route.useSearch()
  const navigate = Route.useNavigate()
  const set = (patch: Partial<typeof search>) =>
    navigate({ search: (s) => ({ ...s, ...patch, limit: undefined }), replace: true, resetScroll: false })
  return (
    <>
      <p className="m-0 max-w-[80ch] text-muted-foreground">
        Each public PHP symbol next to the Rust items that document it. Rust docs name the PHP API they port (<code className="font-mono">`getProperty($key)`</code>),
        which links them; undocumented trait implementations take the trait method's docs. A symbol without a Rust counterpart here may still be
        compared through an operation: the Cases tab is the behaviour contract, this tab the documentation one.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <Search value={search.q ?? ''} onChange={(q) => set({ q: q || undefined })} placeholder="Search symbols, signatures and docs" />
        <Segmented
          value={search.filter ?? 'all'}
          options={[
            ['all', 'All', counts.all],
            ['both', 'Documented in both', counts.both],
            ['unlinked', 'No Rust counterpart', counts.unlinked],
            ['rust-undocumented', 'Rust undocumented', counts['rust-undocumented']],
            ['php-undocumented', 'PHP undocumented', counts['php-undocumented']],
          ]}
          onChange={(f) => set({ filter: f === 'all' ? undefined : f })}
        />
      </div>
      <List>
        {rows.length ? rows.map((x) => <DocRow key={x.symbol} x={x} />) : <Empty>No symbols match.</Empty>}
        <More shown={rows.length} total={total} onMore={() => navigate({ search: (s) => ({ ...s, limit: rows.length + PAGE }), replace: true, resetScroll: false })} />
      </List>
    </>
  )
}

function DocRow({ x }: { x: DocEntry }) {
  const index = root.useLoaderData()
  const s = state(x)
  const short = x.symbol.split('\\').pop() ?? x.symbol
  const [label, tone] = LABEL[s]
  return (
    <Expand
      summary={
        <>
          <Dot tone={s === 'both' ? 'ok' : s === 'unlinked' ? 'bad' : 'warn'} />
          <span className="font-mono text-xs font-medium">{short}</span>
          <span className="font-mono text-xs text-muted-foreground">{x.symbol.slice(0, x.symbol.length - short.length)}</span>
          <span className="flex-auto" />
          <Pill tone={tone}>{label}</Pill>
          {x.waiver ? <Chip>waived</Chip> : null}
        </>
      }
    >
      {() => {
        const phpDoc = docblock(x.php?.doc)
        const classDoc = docblock(x.php?.classDoc)
        return (
          <>
            <div className="grid gap-2.5 md:grid-cols-2">
              <div className="grid min-w-0 content-start gap-1.5 rounded-[10px] border border-border px-3 py-2.5">
                <div className="text-[0.7rem] uppercase tracking-wider text-muted-foreground">PHP</div>
                <div className="font-mono text-xs font-semibold [overflow-wrap:anywhere]">{x.symbol}</div>
                <div className="overflow-x-auto whitespace-pre rounded-md bg-muted px-2 py-1.5 font-mono text-[0.76rem]">{x.php?.signature ?? 'not resolvable by reflection'}</div>
                {phpDoc ? <div className="whitespace-pre-wrap text-[0.82rem]">{phpDoc}</div> : <div className="italic text-muted-foreground">No docblock.</div>}
                {classDoc ? (
                  <details>
                    <summary className="cursor-pointer text-xs text-muted-foreground">Class docblock</summary>
                    <div className="whitespace-pre-wrap text-xs">{classDoc}</div>
                  </details>
                ) : null}
                {x.php?.file ? <Source href={sourceUrl(index, x.php.file, x.php.line)} label={`${x.php.file}:${x.php.line}`} /> : null}
              </div>
              <div className="grid min-w-0 content-start gap-2.5">
                {x.rust.length ? (
                  x.rust.map((r) => (
                    <div key={r.path} className="grid min-w-0 content-start gap-1.5 rounded-[10px] border border-border px-3 py-2.5">
                      <div className="flex flex-wrap justify-between gap-2 text-[0.7rem] uppercase tracking-wider text-muted-foreground">
                        <span>Rust · {r.kind}</span>
                        <Chip>{r.via}</Chip>
                      </div>
                      <div className="font-mono text-xs font-semibold">{r.path}</div>
                      <div className="overflow-x-auto whitespace-pre rounded-md bg-muted px-2 py-1.5 font-mono text-[0.76rem]">{r.signature}</div>
                      {r.doc ? (
                        <div className="whitespace-pre-wrap text-[0.82rem]">{r.doc}</div>
                      ) : r.inherited_doc ? (
                        <>
                          <div className="whitespace-pre-wrap text-[0.82rem]">{r.inherited_doc}</div>
                          <div className="text-xs text-muted-foreground">
                            Inherited from <code className="font-mono">{r.inherits}</code>
                          </div>
                        </>
                      ) : (
                        <div className="italic text-muted-foreground">No doc comment.</div>
                      )}
                      <Source href={sourceUrl(index, r.file, r.line)} label={`${r.file}:${r.line}`} />
                    </div>
                  ))
                ) : (
                  <div className="rounded-[10px] border border-border px-3 py-2.5 italic text-muted-foreground">
                    No Rust item names this symbol in its docs.{x.ops.length ? ' Its behaviour is still compared through the operations below.' : ''}
                  </div>
                )}
              </div>
            </div>
            <div className="text-xs text-muted-foreground">
              {x.ops.length ? <>Compared by {x.ops.map((o) => <code key={o} className="font-mono">{o} </code>)}</> : 'Not covered by an operation.'}
              {x.waiver ? ` · Waived: ${x.waiver}` : ''}
            </div>
          </>
        )
      }}
    </Expand>
  )
}
