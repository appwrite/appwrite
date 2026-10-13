import type { Bench, Timing } from '@/lib/docs'
import { Duel, DuelLegend, Footnote, GoalNote, Measured, PARITY, Problems, Summary, goalOf, meets, unit } from './Bench'
import { MigrationCard } from './Migration'

/** The geometric mean of PHP's value over Rust's, across the benchmarks that have both (compat ones left out). */
function mean(results: Bench['results'], pick: (t: Timing) => number | undefined) {
  const ratios = results
    .filter((r) => !r.compat)
    .map((r) => [pick(r.php), pick(r.rust)])
    .filter((p): p is [number, number] => !!p[0] && !!p[1])
    .map(([p, r]) => p / r)
  return ratios.length ? Math.exp(ratios.reduce((a, x) => a + Math.log(x), 0) / ratios.length) : null
}

/** The heaviest a runtime's driver process got over the run (`rss` is a running maximum). */
const rss = (results: Bench['results'], side: 'php' | 'rust') => Math.max(0, ...results.map((r) => r[side].rss ?? 0)) || null

/** Rust's allocations per call, which only its counting allocator sees. */
function allocs(t: Timing) {
  if (t.allocations === undefined) return null
  const n = t.allocations
  return `${n < 0.05 ? 0 : n < 10 ? n.toFixed(1) : Math.round(n)} allocs, ${unit.bytes(t.bytes ?? 0)} per call`
}

/**
 * The library's benchmarks against PHP (`compat bench`, tests/compat/README.md#benchmarks): time per call, CPU
 * per call and peak heap of each runtime, the same comparisons the Appwrite API benchmarks show.
 */
export function Performance({ bench, id, stale }: { bench: Bench | null; id?: string; stale?: boolean | null }) {
  if (!bench?.results.length) return null
  const env = bench.environment
  const results = bench.results
  // Where each runtime ran: both in containers on one kernel since `compat bench`
  // runs the Rust driver there too; older files timed Rust on the host.
  const where = env.rust.image
    ? env.php.kernel && env.php.kernel === env.rust.kernel
      ? `both in Linux containers on one Docker network (kernel ${env.php.kernel})`
      : 'both in Linux containers'
    : 'PHP in a container, Rust on the host'
  const measured = results.some((r) => r.php.cpu !== undefined || r.rust.cpu !== undefined)
  const missed = results.filter((r) => !meets(r.speedup, goalOf(r)))
  const gated = results.filter((r) => !r.compat).length
  const [phpRss, rustRss] = [rss(results, 'php'), rss(results, 'rust')]
  const speed = mean(results, (t) => t.ns)
  const cpu = mean(results, (t) => t.cpu)
  return (
    <MigrationCard id={id} title="Performance against PHP">
      <p className="m-0 text-[13px] text-muted-foreground">
        The same work timed in each runtime, in-process: the median of {results[0].rust.samples} samples per benchmark, with the CPU time and heap the
        driver spent on them. Lower is better.
      </p>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <Measured date={env.date} commit={env.commit} machine={env.machine.cpu || env.machine.arch} stale={stale} />
        <Summary
          className="ml-auto"
          items={[
            { metric: 'time', x: speed, title: `Time per call: geometric mean of ${gated} benchmarks` },
            { metric: 'cpu', x: cpu, title: `CPU time per call: geometric mean of ${gated} benchmarks` },
          ]}
        />
      </div>
      <Problems
        ok={`Every gated benchmark meets its gate: faster than PHP, or within ${Math.round((1 - PARITY) * 100)}% of it where bound.`}
        notes={missed.map((r) => (
          <span key={r.name}>
            <code className="font-mono">{r.name}</code> {r.bound ? `is under parity (${r.speedup.toFixed(2)}×)` : `is slower in Rust (${r.speedup.toFixed(2)}×)`}
          </span>
        ))}
      />
      <DuelLegend />
      <div className="min-w-0 overflow-x-auto rounded-lg border border-border bg-card">
        <table className="w-full min-w-[600px] border-collapse text-[13px]">
          <thead>
            <tr className="border-b border-border text-left text-[11px] tracking-wider text-muted-foreground uppercase">
              <th className="px-3 py-2 font-semibold">Benchmark</th>
              <th className="w-[22%] px-3 py-2 font-semibold">Time per call</th>
              <th className="w-[22%] px-3 py-2 font-semibold">CPU per call</th>
              <th className="w-[22%] px-3 py-2 font-semibold">Peak heap</th>
            </tr>
          </thead>
          <tbody>
            {results.map((r) => {
              const goal = goalOf(r)
              return (
                <tr key={r.name} className="border-b border-border align-top last:border-b-0">
                  <td className="px-3 py-2.5">
                    <div className="font-mono text-[12.5px] font-medium">{r.name}</div>
                    {r.description ? <div className="text-[12px] text-muted-foreground">{r.description}</div> : null}
                    {r.bound ? <GoalNote kind="bound" reason={r.bound} /> : null}
                    {r.compat ? <GoalNote kind="compat" reason={r.compat} /> : null}
                  </td>
                  <td className="px-3 py-2.5">
                    <Duel
                      compact
                      php={r.php.ns}
                      rust={r.rust.ns}
                      format={unit.ns}
                      goal={goal}
                      title={{ php: `Samples from ${unit.ns(r.php.min)} to ${unit.ns(r.php.max)}`, rust: `Samples from ${unit.ns(r.rust.min)} to ${unit.ns(r.rust.max)}` }}
                    />
                  </td>
                  <td className="px-3 py-2.5">
                    <Duel compact php={r.php.cpu} rust={r.rust.cpu} metric="cpu" format={unit.ns} goal={goal} />
                  </td>
                  <td className="px-3 py-2.5">
                    <Duel
                      compact
                      php={r.php.peak}
                      rust={r.rust.peak}
                      metric="memory"
                      format={unit.bytes}
                      goal={goal}
                      title={{
                        php: r.php.retained !== undefined ? `Retained ${unit.bytes(r.php.retained)} per call` : undefined,
                        rust: r.rust.retained !== undefined ? `Retained ${unit.bytes(r.rust.retained)} per call` : undefined,
                      }}
                    />
                    {allocs(r.rust) ? (
                      <div className="mt-1 text-[11px] text-muted-foreground" title="Heap allocations and bytes allocated per call, counted by the Rust driver's allocator; PHP has no counter">
                        Rust: {allocs(r.rust)}
                      </div>
                    ) : null}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <Footnote
        metrics={
          measured ? (
            <>
              CPU per call: the driver process's CPU time (user and system) over the timed samples, per call; it leaves out services the call waits on.
              Peak heap: the most heap the samples held above what was live when they started, from PHP's Zend allocator (request memory only,
              rounded to its size classes) and from every allocation through Rust's global allocator; they count differently, so read it as an
              order of magnitude, and a call that frees what it allocates before the next one peaks at a single call's working set.
              {phpRss && rustRss ? ` Driver peak resident memory, runtime baseline included: PHP ${unit.bytes(phpRss)}, Rust ${unit.bytes(rustRss)}.` : ''}
            </>
          ) : (
            <>CPU and memory were not measured in this run: it predates them. The next `bin/compat bench --write` records them.</>
          )
        }
      >
        {env.machine.cpu || env.machine.arch} ({env.machine.os} host) · PHP {env.php.version}
        {env.php.opcache ? `, opcache, JIT ${env.php.jit || 'off'}` : ', no opcache'} · {env.rust.version.split(' (')[0]}, {env.rust.profile} build · {where}.
      </Footnote>
    </MigrationCard>
  )
}
