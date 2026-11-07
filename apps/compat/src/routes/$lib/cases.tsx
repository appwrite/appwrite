import { createFileRoute } from '@tanstack/react-router'
import { Callout, Chip, Dot, Empty, Expand, List, More, Panel, Pill, ResultPair, Search, Segmented, fmt, json } from '@/components/ui'
import { PAGE, type Case } from '@/lib/report'
import { listSearch, requireLib } from '@/lib/route'

export const Route = createFileRoute('/$lib/cases')({
  validateSearch: listSearch,
  loaderDeps: ({ search }) => search,
  loader: async ({ params, deps }) => {
    const d = await requireLib(params.lib)
    const masked = (c: Case) => c.steps.some((s) => s.masks?.some((m) => m.paths.length))
    const counts = {
      all: d.cases.length,
      failing: d.cases.filter((c) => !c.ok).length,
      interop: d.cases.filter((c) => c.interop).length,
      masked: d.cases.filter(masked).length,
    }
    const term = (deps.q ?? '').toLowerCase()
    const rows = d.cases.filter((c) => {
      if (deps.filter === 'failing' && c.ok) return false
      if (deps.filter === 'interop' && !c.interop) return false
      if (deps.filter === 'masked' && !masked(c)) return false
      return !term || `${c.name} ${c.file} ${c.steps.map((s) => s.op).join(' ')}`.toLowerCase().includes(term)
    })
    return { counts, files: new Set(d.cases.map((c) => c.file)).size, total: rows.length, rows: rows.slice(0, deps.limit ?? PAGE) }
  },
  component: Cases,
})

function Cases() {
  const { counts, files, total, rows } = Route.useLoaderData()
  const search = Route.useSearch()
  const navigate = Route.useNavigate()
  const set = (patch: Partial<typeof search>) =>
    navigate({ search: (s) => ({ ...s, ...patch, limit: undefined }), replace: true, resetScroll: false })
  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <Search value={search.q ?? ''} onChange={(q) => set({ q: q || undefined })} placeholder="Search cases, files and operations" />
        <Segmented
          value={search.filter ?? 'all'}
          options={[
            ['all', 'All', counts.all],
            ['failing', 'Failing', counts.failing],
            ['interop', 'Interop', counts.interop],
            ['masked', 'With masks', counts.masked],
          ]}
          onChange={(f) => set({ filter: f === 'all' ? undefined : f })}
        />
        <span className="text-xs tabular-nums text-muted-foreground">
          {fmt(total)} of {fmt(counts.all)} cases in {files} files
        </span>
      </div>
      <div className="flex flex-wrap gap-x-3.5 gap-y-1.5 text-xs text-muted-foreground">
        <span>
          <Pill tone="ok">match</Pill> PHP and Rust agree, and match the recorded PHP result
        </span>
        <span>
          <Pill tone="bad">differ</Pill> PHP and Rust disagree
        </span>
        <span>
          <Pill tone="warn">differs from recorded</Pill> both agree on a result PHP no longer gives
        </span>
        <span>
          <Chip>interop</Chip> one runtime writes, the other reads, both ways
        </span>
      </div>
      <List>
        {rows.length ? rows.map((c) => <CaseRow key={`${c.file}#${c.name}`} c={c} />) : <Empty>No cases match.</Empty>}
        <More shown={rows.length} total={total} onMore={() => navigate({ search: (s) => ({ ...s, limit: rows.length + PAGE }), replace: true, resetScroll: false })} />
      </List>
    </>
  )
}

const STATUS = {
  match: <Pill tone="ok">match</Pill>,
  differ: <Pill tone="bad">differ</Pill>,
  expect: <Pill tone="warn">differs from recorded</Pill>,
  fault: <Pill tone="bad">fault</Pill>,
}

function CaseRow({ c }: { c: Case }) {
  return (
    <Expand
      summary={
        <>
          <Dot tone={c.ok ? 'ok' : 'bad'} />
          <span className="min-w-0 font-medium [overflow-wrap:anywhere]">{c.name}</span>
          {c.interop ? <Chip>interop</Chip> : null}
          <span className="flex-auto" />
          <span className="font-mono text-xs text-muted-foreground">{c.file}</span>
          <span className="text-xs tabular-nums text-muted-foreground">
            {c.steps.length} step{c.steps.length === 1 ? '' : 's'}
          </span>
        </>
      }
    >
      {() => (
        <>
          {c.differences.length + c.faults.length ? (
            <Callout tone="bad">
              {[...c.differences, ...c.faults].map((p, i) => (
                <pre key={i} className="whitespace-pre-wrap font-mono text-[11px]">
                  {p}
                </pre>
              ))}
            </Callout>
          ) : null}
          {c.steps.map((s, i) => (
            <div key={i} className="grid min-w-0 gap-2 border-dashed border-border [&+&]:border-t [&+&]:pt-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-xs text-muted-foreground">#{i + 1}</span>
                <span className="font-mono text-[0.8rem] font-semibold">{s.op}</span>
                {STATUS[s.status]}
                {s.side ? <Chip>side {s.side}</Chip> : null}
                {s.bind ? <Chip>binds {s.bind}</Chip> : null}
                {s.at ? (
                  <span className="text-xs text-muted-foreground">
                    at <code className="font-mono">{s.at}</code>
                  </span>
                ) : null}
              </div>
              {s.status === 'fault' ? (
                <Callout tone="bad">{(s.faults ?? []).join('\n')}</Callout>
              ) : (
                <>
                  <Panel title="Arguments" extra="as written in the case" short>
                    {json(s.args)}
                  </Panel>
                  <ResultPair
                    php={s.php}
                    rust={s.rust}
                    phpExtra={s.expect == null ? undefined : JSON.stringify(s.expect) === JSON.stringify(s.php) ? 'same as recorded' : 'differs from recorded'}
                  />
                  {s.status === 'expect' ? <Panel title="Recorded PHP result">{json(s.expect)}</Panel> : null}
                  {s.masks?.some((m) => m.paths.length) ? (
                    <div className="text-xs text-muted-foreground">
                      Masked before comparing:{' '}
                      {s.masks
                        .filter((m) => m.paths.length)
                        .map((m) => `${m.paths.map((p) => p || '/').join(', ')} (${m.reason})`)
                        .join('; ')}
                    </div>
                  ) : null}
                </>
              )}
            </div>
          ))}
        </>
      )}
    </Expand>
  )
}
