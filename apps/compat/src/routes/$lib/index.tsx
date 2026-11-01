import { createFileRoute } from '@tanstack/react-router'
import type { ReactNode } from 'react'
import { Callout, Stat, Stats, VerdictStack, fmt } from '@/components/ui'
import { requireLib } from '@/lib/route'

export const Route = createFileRoute('/$lib/')({
  loader: async ({ params }) => {
    const d = await requireLib(params.lib)
    return { summary: d.summary, spec: d.spec, problems: d.problems }
  },
  component: Summary,
})

function Box<T>({ title, items, render, empty }: { title: string; items: T[]; render: (item: T) => ReactNode; empty: string }) {
  return (
    <div className="grid min-w-0 content-start gap-2 rounded-[10px] border border-border px-3.5 py-3">
      <h3 className="text-[0.95rem]">
        {title} <span className="text-xs tabular-nums text-muted-foreground">{items.length}</span>
      </h3>
      {items.length ? (
        <ul className="m-0 grid gap-1 pl-4 text-[0.82rem]">{items.map(render)}</ul>
      ) : (
        <span className="text-xs text-muted-foreground">{empty}</span>
      )}
    </div>
  )
}

const Code = ({ children }: { children: ReactNode }) => <code className="font-mono text-[0.76rem] [overflow-wrap:anywhere]">{children}</code>

function Summary() {
  const { summary: s, spec, problems } = Route.useLoaderData()
  const c = s.coverage
  return (
    <>
      <Stats>
        <Stat label="API coverage" value={`${c.percent.toFixed(1)}%`} note={`${fmt(c.covered)} covered · ${fmt(c.waived)} waived · ${fmt(c.uncovered.length)} uncovered`} />
        <Stat label="Cases" value={`${fmt(s.run.passed)} / ${fmt(s.run.cases)}`} note={`${fmt(c.steps)} steps · ${fmt(c.steps_without_expect)} without a recorded result`} />
        <Stat label="Fuzz" value={fmt(s.fuzz.inputs)} note={`${fmt(s.fuzz.profiles)} profiles · CI runs ${fmt(s.fuzz.configured)}`} />
        <Stat label="Operations" value={fmt(c.ops)} note={c.missing_php.length + c.missing_rust.length ? `${c.missing_php.length} missing in PHP, ${c.missing_rust.length} in Rust` : 'implemented by both adapters'} />
        <Stat label="PHP tests ported" value={c.tests ? `${c.tests - c.tests_unported.length} / ${c.tests}` : '—'} note={c.tests ? 'test classes with a case file' : 'engine built-ins have no PHP tests'} />
        <Stat label="Interface" value={<div className="mt-1.5 text-sm font-normal"><VerdictStack iface={s.interface} /></div>} note={s.interface.untested ? `${s.interface.untested} methods no operation exercises` : 'every method is exercised'} />
        <Stat label="Docs linked" value={`${fmt(s.docs.linked)} / ${fmt(s.docs.symbols)}`} note={`${fmt(s.docs.rust_items)} Rust items · ${fmt(s.docs.rust_only)} without a PHP link`} />
      </Stats>
      {problems.length ? (
        <Callout tone="bad">
          <b>
            {problems.length} problem{problems.length > 1 ? 's' : ''} in this run
          </b>
          {problems.map((p, i) => (
            <pre key={i} className="mt-1.5 whitespace-pre-wrap font-mono text-[11px]">
              {p}
            </pre>
          ))}
        </Callout>
      ) : (
        <Callout tone="ok">
          <b>No differences.</b> Every case and fuzzed input produced the same result on PHP and Rust in this run.
        </Callout>
      )}
      {!s.complete ? (
        <Callout tone="utopia">
          <b>Not complete yet.</b> {spec.description}
        </Callout>
      ) : null}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(320px,1fr))] gap-3.5">
        <Box title="Uncovered PHP symbols" items={c.uncovered} render={(x) => <li key={x}><Code>{x}</Code></li>} empty="Every public symbol is covered by an operation or waived." />
        <Box
          title="Waived symbols"
          items={Object.entries(spec.waivers)}
          render={([p, r]) => (
            <li key={p}>
              <Code>{p}</Code>
              <br />
              <span className="text-muted-foreground">{r}</span>
            </li>
          )}
          empty="No waivers."
        />
        <Box
          title="Quirks kept on purpose"
          items={spec.quirks}
          render={(q) => (
            <li key={q.symbol + q.case}>
              <Code>{q.symbol}</Code>
              <br />
              {q.description} <span className="text-muted-foreground">({q.case})</span>
            </li>
          )}
          empty="No recorded PHP quirks."
        />
        <Box
          title="Deviations"
          items={spec.deviations}
          render={(q) => (
            <li key={q.symbol}>
              <Code>{q.symbol}</Code>
              <br />
              <b>PHP:</b> {q.php}
              <br />
              <span className="text-muted-foreground">{q.reason}</span>
            </li>
          )}
          empty="No deviations: Rust accepts everything PHP does."
        />
        <Box
          title="PHP tests not ported"
          items={[...c.tests_unported.map((t) => [t, ''] as const), ...Object.entries(spec.tests_waived)]}
          render={([t, r]) => (
            <li key={t}>
              <Code>{t}</Code>
              {r ? (
                <>
                  <br />
                  <span className="text-muted-foreground">waived: {r}</span>
                </>
              ) : null}
            </li>
          )}
          empty="Every PHP test class has a case file."
        />
        <Box title="Operations without cases" items={c.ops_without_cases} render={(x) => <li key={x}><Code>{x}</Code></li>} empty="Every operation has cases." />
      </div>
    </>
  )
}
