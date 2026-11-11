import { Link, createFileRoute, getRouteApi, useNavigate } from '@tanstack/react-router'
import { Bar, LibStatus, Stat, Stats, VERDICTS, VERDICT_BG, VerdictStack, fmt } from '@/components/ui'
import { problemCount } from '@/lib/report'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/')({
  component: Overview,
})

function Overview() {
  const index = root.useLoaderData()
  const navigate = useNavigate()
  if (!index) return null
  const libs = index.libs
  const sum = (f: (l: (typeof libs)[number]) => number) => libs.reduce((s, l) => s + f(l), 0)
  const matching = sum((l) => (l.interface.verdicts.same ?? 0) + (l.interface.verdicts.compatible ?? 0))

  return (
    <>
      <section className="grid gap-3">
        <p className="m-0 max-w-[72ch] text-muted-foreground">
          Each Utopia library is converted from PHP to Rust and checked by <code className="font-mono">bin/compat</code>: every case runs on both
          runtimes and their results must match byte for byte, generated inputs are fuzzed through both, every public PHP method is compared
          with its Rust counterpart, and both APIs' docs are mapped to each other. Open a library for the detail.
        </p>
        <Stats>
          <Stat label="Libraries complete" value={`${libs.filter((l) => l.complete).length} / ${libs.length}`} note="every conversion rule holds" />
          <Stat label="Cases matching" value={`${fmt(sum((l) => l.run.passed))} / ${fmt(sum((l) => l.run.cases))}`} note="PHP and Rust, every step" />
          <Stat label="Fuzzed inputs" value={fmt(sum((l) => l.fuzz.inputs))} note={`${fmt(sum((l) => l.fuzz.profiles))} profiles; CI runs ${fmt(sum((l) => l.fuzz.configured))}`} />
          <Stat label="Interface matching" value={`${fmt(matching)} / ${fmt(sum((l) => l.interface.methods))}`} note="public methods whose Rust signature fits" />
          <Stat label="Docs linked" value={`${fmt(sum((l) => l.docs.linked))} / ${fmt(sum((l) => l.docs.symbols))}`} note="PHP symbols with a Rust counterpart" />
          <Stat label="Problems" value={fmt(sum(problemCount))} note="differences and faults in this report" />
        </Stats>
      </section>

      <section className="grid gap-3">
        <h2 className="text-lg">Libraries</h2>
        <div className="overflow-x-auto rounded-[10px] border border-border">
          <table className="w-full border-collapse text-[0.82rem]">
            <thead>
              <tr className="border-b border-border text-left text-[0.7rem] uppercase tracking-wider text-muted-foreground">
                {['Library', 'Status', 'API coverage', 'Cases', 'Fuzz', 'PHP tests ported', 'Interface', 'Docs linked'].map((h) => (
                  <th key={h} className="whitespace-nowrap px-3 py-2.5 font-medium">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {libs.map((l) => (
                <tr
                  key={l.lib}
                  className="cursor-pointer border-b border-border transition-colors last:border-b-0 hover:bg-accent/60"
                  onClick={() => navigate({ to: '/$lib', params: { lib: l.lib } })}
                >
                  <td className="px-3 py-2.5">
                    <Link to="/$lib" params={{ lib: l.lib }} className="font-display text-[0.95rem] font-medium">
                      {l.lib}
                    </Link>
                    <span className="block max-w-[34ch] truncate text-xs text-muted-foreground" title={l.description}>
                      {l.description}
                    </span>
                  </td>
                  <td className="px-3 py-2.5">
                    <LibStatus lib={l} />
                  </td>
                  <td className="px-3 py-2.5">
                    <Bar
                      value={l.coverage.covered}
                      total={l.coverage.symbols}
                      extra={l.coverage.waived}
                      label={`${l.coverage.percent.toFixed(1)}% of ${fmt(l.coverage.symbols)}${l.coverage.waived ? ` · ${l.coverage.waived} waived` : ''}`}
                    />
                  </td>
                  <td className="px-3 py-2.5 tabular-nums">
                    {fmt(l.run.passed)} / {fmt(l.run.cases)}
                    <div className="text-xs text-muted-foreground">{fmt(l.coverage.steps)} steps</div>
                  </td>
                  <td className="px-3 py-2.5 tabular-nums">
                    {fmt(l.fuzz.inputs)}
                    <div className="text-xs text-muted-foreground">{fmt(l.fuzz.profiles)} profiles</div>
                  </td>
                  <td className="px-3 py-2.5 tabular-nums">
                    {l.coverage.tests ? `${l.coverage.tests - l.coverage.tests_unported.length} / ${l.coverage.tests}` : <span className="text-muted-foreground">engine built-ins</span>}
                  </td>
                  <td className="px-3 py-2.5">
                    <VerdictStack iface={l.interface} />
                  </td>
                  <td className="px-3 py-2.5">
                    <Bar value={l.docs.linked} total={l.docs.symbols} label={`${fmt(l.docs.linked)} / ${fmt(l.docs.symbols)}`} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex flex-wrap gap-x-3.5 gap-y-1.5 text-xs text-muted-foreground">
          <span>Interface:</span>
          {VERDICTS.map(([k, n]) => (
            <span key={k} className="inline-flex items-center gap-1">
              <span className={`size-2 rounded-sm ${VERDICT_BG[k]}`} />
              {n}
            </span>
          ))}
        </div>
      </section>
    </>
  )
}
