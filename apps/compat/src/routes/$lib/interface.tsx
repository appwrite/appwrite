import { createFileRoute, getRouteApi } from '@tanstack/react-router'
import {
  Callout,
  Dot,
  Empty,
  Expand,
  FIT_TONE,
  List,
  More,
  Pill,
  Search,
  Segmented,
  Source,
  VERDICTS,
  VERDICT_TONE,
  fmt,
  verdictName,
} from '@/components/ui'
import { PAGE, sourceUrl, type Method, type PhpParam, type Verdict } from '@/lib/report'
import { listSearch, requireLib } from '@/lib/route'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/$lib/interface')({
  validateSearch: listSearch,
  loaderDeps: ({ search }) => search,
  loader: async ({ params, deps }) => {
    const d = await requireLib(params.lib)
    const counts: Record<string, number> = { all: d.interface.length }
    for (const [k] of VERDICTS) counts[k] = d.interface.filter((m) => m.verdict === k).length
    counts.untested = d.interface.filter((m) => !m.evidence.steps && !m.evidence.fuzz).length
    const term = (deps.q ?? '').toLowerCase()
    const rows = d.interface.filter((m) => {
      if (deps.filter === 'untested') {
        if (m.evidence.steps || m.evidence.fuzz) return false
      } else if (deps.filter && m.verdict !== deps.filter) return false
      return !term || `${m.symbol} ${m.best?.rust ?? ''} ${m.php.signature ?? ''}`.toLowerCase().includes(term)
    })
    return { counts, total: rows.length, rows: rows.slice(0, deps.limit ?? PAGE) }
  },
  component: Interface,
})

function phpParam(p: PhpParam | null) {
  if (!p) return <span className="text-muted-foreground">—</span>
  return `${p.type ? p.type + ' ' : ''}${p.reference ? '&' : ''}${p.variadic ? '...' : ''}$${p.name}${p.default != null ? ' = ' + p.default : ''}`
}

function Interface() {
  const { counts, total, rows } = Route.useLoaderData()
  const search = Route.useSearch()
  const navigate = Route.useNavigate()
  const set = (patch: Partial<typeof search>) =>
    navigate({ search: (s) => ({ ...s, ...patch, limit: undefined }), replace: true, resetScroll: false })
  const options: [string, string, number][] = [['all', 'All', counts.all] as [string, string, number]]
    .concat(VERDICTS.map(([k, n]) => [k, n, counts[k]]))
    .concat([['untested', 'Not exercised', counts.untested]])
    .filter(([k, , n]) => k === 'all' || n > 0)

  return (
    <>
      <p className="m-0 max-w-[80ch] text-muted-foreground">
        Every public PHP method next to its closest Rust counterpart: how it is called, each parameter (matched by name, then position) with its
        type and default, and the return type. PHP <code className="font-mono">?T</code> and <code className="font-mono">null</code> defaults map to{' '}
        <code className="font-mono">Option</code>, <code className="font-mono">T|false</code> and exceptions to <code className="font-mono">Option</code> or{' '}
        <code className="font-mono">Result</code>, a class to the Rust type of the same name. <b>Review</b> marks a mapping the checker cannot decide,
        such as a Rust enum for PHP's string constants; what each method does is proven by the cases and fuzzing counted on the right.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <Search value={search.q ?? ''} onChange={(q) => set({ q: q || undefined })} placeholder="Search methods and Rust items" />
        <Segmented value={search.filter ?? 'all'} options={options} onChange={(f) => set({ filter: f === 'all' ? undefined : f })} />
      </div>
      <List>
        {rows.length ? rows.map((m) => <MethodRow key={m.symbol} m={m} />) : <Empty>No methods match.</Empty>}
        <More shown={rows.length} total={total} onMore={() => navigate({ search: (s) => ({ ...s, limit: rows.length + PAGE }), replace: true, resetScroll: false })} />
      </List>
    </>
  )
}

function MethodRow({ m }: { m: Method }) {
  const index = root.useLoaderData()
  const short = m.symbol.split('\\').pop() ?? m.symbol
  const ev = m.evidence
  const tested = ev.steps || ev.fuzz
  const tone = VERDICT_TONE[m.verdict as Verdict]
  return (
    <Expand
      summary={
        <>
          <Dot tone={tone === 'plain' ? 'warn' : tone} />
          <span className="font-mono text-xs font-medium">{short}</span>
          {m.best ? <span className="font-mono text-xs text-muted-foreground">→ {m.best.rust}</span> : null}
          <span className="flex-auto" />
          <span className={`text-xs tabular-nums ${tested ? 'text-muted-foreground' : ''}`}>
            {tested ? `${fmt(ev.steps)} steps${ev.fuzz ? ` · ${fmt(ev.fuzz)} fuzzed` : ''}` : 'not exercised'}
          </span>
          {ev.failing ? <Pill tone="bad">{ev.failing} failing</Pill> : null}
          <Pill tone={tone}>{verdictName(m.verdict)}</Pill>
        </>
      }
    >
      {() => (
        <>
          <div className="grid gap-2.5 md:grid-cols-2">
            <div className="grid min-w-0 content-start gap-1.5 rounded-[10px] border border-border px-3 py-2.5">
              <div className="text-[0.7rem] uppercase tracking-wider text-muted-foreground">PHP</div>
              <div className="overflow-x-auto whitespace-pre rounded-md bg-muted px-2 py-1.5 font-mono text-[0.76rem]">{m.php.signature || m.symbol}</div>
              {m.php.file ? <Source href={sourceUrl(index, m.php.file, m.php.line)} label={`${m.php.file}:${m.php.line}`} /> : null}
            </div>
            <div className="grid min-w-0 content-start gap-1.5 rounded-[10px] border border-border px-3 py-2.5">
              <div className="flex justify-between text-[0.7rem] uppercase tracking-wider text-muted-foreground">
                <span>Rust</span>
              </div>
              {m.best ? <div className="font-mono text-xs font-semibold">{m.best.rust}</div> : <div className="italic text-muted-foreground">No counterpart.</div>}
              {m.others.length ? (
                <div className="text-xs text-muted-foreground">
                  Also linked:{' '}
                  {m.others.map((o) => (
                    <span key={o.rust}>
                      <code className="font-mono">{o.rust}</code> ({o.verdict}){' '}
                    </span>
                  ))}
                </div>
              ) : null}
            </div>
          </div>
          {m.best ? (
            <div className="overflow-x-auto rounded-[10px] border border-border">
              <table className="w-full border-collapse text-[0.78rem]">
                <thead>
                  <tr className="border-b border-border text-left text-[0.68rem] uppercase tracking-wider text-muted-foreground">
                    {['', 'PHP', 'Rust', 'Fit', 'Note'].map((h) => (
                      <th key={h} className="px-2 py-1.5 font-medium">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  <tr className="border-b border-border align-top">
                    <td className="px-2 py-1.5">Call</td>
                    <td className="px-2 py-1.5 font-mono">{m.symbol.endsWith('::__construct') ? 'new (constructor)' : m.php.static ? 'static' : '$this->'}</td>
                    <td className="px-2 py-1.5 font-mono">
                      {m.best.receiver ?? 'associated / free function'}
                      {m.best.async ? ' · async' : ''}
                    </td>
                    <td className="px-2 py-1.5">{m.best.notes.length ? <Pill tone="warn">review</Pill> : <Pill tone="ok">same</Pill>}</td>
                    <td className="px-2 py-1.5 text-xs">{m.best.notes.join('; ')}</td>
                  </tr>
                  {m.best.params.length ? (
                    m.best.params.map((r, i) => (
                      <tr key={i} className="border-b border-border align-top">
                        <td className="px-2 py-1.5">Parameter</td>
                        <td className="px-2 py-1.5 font-mono [overflow-wrap:anywhere]">{phpParam(r.php)}</td>
                        <td className="px-2 py-1.5 font-mono [overflow-wrap:anywhere]">{r.rust ? `${r.rust.name}: ${r.rust.type}` : <span className="text-muted-foreground">—</span>}</td>
                        <td className="px-2 py-1.5">
                          <Pill tone={FIT_TONE[r.fit]}>{r.fit}</Pill>
                        </td>
                        <td className="px-2 py-1.5 text-xs">{r.note}</td>
                      </tr>
                    ))
                  ) : (
                    <tr className="border-b border-border">
                      <td className="px-2 py-1.5">Parameters</td>
                      <td colSpan={4} className="px-2 py-1.5 text-muted-foreground">
                        none on either side
                      </td>
                    </tr>
                  )}
                  <tr className="align-top">
                    <td className="px-2 py-1.5">Returns</td>
                    <td className="px-2 py-1.5 font-mono">{m.best.returns.php || '—'}</td>
                    <td className="px-2 py-1.5 font-mono [overflow-wrap:anywhere]">{m.best.returns.rust}</td>
                    <td className="px-2 py-1.5">
                      <Pill tone={FIT_TONE[m.best.returns.fit]}>{m.best.returns.fit}</Pill>
                    </td>
                    <td className="px-2 py-1.5 text-xs">
                      {m.best.returns.note}
                      {m.best.returns.throws ? `${m.best.returns.note ? '; ' : ''}errors as Result (PHP throws)` : ''}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          ) : m.types.length ? (
            <Callout tone="utopia">No Rust function stands for this method; it is modelled by {m.types.join(', ')}.</Callout>
          ) : (
            <Callout tone="bad">
              No Rust item names this method.
              {m.waiver ? ` Waived: ${m.waiver}` : tested ? ' Its behaviour is still compared through the operations below.' : ''}
            </Callout>
          )}
          <div className="text-xs text-muted-foreground">
            {ev.ops.length ? (
              <>
                Exercised by {ev.ops.map((o) => <code key={o} className="font-mono">{o} </code>)}: {fmt(ev.steps)} case steps
                {ev.failing ? <b> ({ev.failing} not matching)</b> : ', all matching'}
                {ev.fuzz ? `, ${fmt(ev.fuzz)} fuzzed inputs ${ev.fuzz_ok ? 'with no differences' : 'with a difference'}` : ''}.
              </>
            ) : (
              'No operation exercises this method.'
            )}
            {m.waiver ? ` Waived: ${m.waiver}` : ''}
          </div>
        </>
      )}
    </Expand>
  )
}
