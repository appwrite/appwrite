import { Gauge } from 'lucide-react'
import { duration, times, type Bench } from '@/lib/docs'
import { MigrationCard } from './Migration'

/** The library's benchmarks against PHP (`compat bench`), with where they ran. */
export function Performance({ bench, id }: { bench: Bench | null; id?: string }) {
  if (!bench?.results.length) return null
  const env = bench.environment
  // Where each runtime ran: both in containers on one kernel since `compat bench`
  // runs the Rust driver there too; older files timed Rust on the host.
  const where = env.rust.image
    ? env.php.kernel && env.php.kernel === env.rust.kernel
      ? `both in Linux containers on one Docker network (kernel ${env.php.kernel})`
      : 'both in Linux containers'
    : 'PHP in a container, Rust on the host'
  // Bars share one scale: the largest speedup on the page fills its track.
  const top = Math.max(...bench.results.map((r) => r.speedup), 1)
  return (
    <MigrationCard id={id} title="Performance against PHP">
      <p className="m-0 text-[13px] text-muted-foreground">
        The same work timed in each runtime, in-process: the median of {bench.results[0].rust.samples} samples per benchmark. Lower is faster.
      </p>
      <div className="overflow-x-auto rounded-lg border border-border bg-card">
        <table className="w-full border-collapse text-[13px]">
          <thead>
            <tr className="border-b border-border text-left text-[11px] tracking-wider text-muted-foreground uppercase">
              <th className="px-3 py-2 font-semibold">Benchmark</th>
              <th className="px-3 py-2 text-right font-semibold">PHP</th>
              <th className="px-3 py-2 text-right font-semibold">Rust</th>
              <th className="w-[28%] px-3 py-2 font-semibold">Speedup</th>
              <th className="px-3 py-2 text-right font-semibold" title="Heap allocations per call in Rust">Allocs</th>
            </tr>
          </thead>
          <tbody>
            {bench.results.map((r) => (
              <tr key={r.name} className="border-b border-border align-top last:border-b-0">
                <td className="px-3 py-2.5">
                  <div className="font-mono text-[12.5px] font-medium">{r.name}</div>
                  {r.description ? <div className="text-[12px] text-muted-foreground">{r.description}</div> : null}
                  {r.bound ? (
                    <div className="mt-0.5 text-[11.5px] text-muted-foreground" title="Bound benchmarks must reach parity with PHP rather than beat it">
                      <span className="rounded bg-muted px-1 py-px text-[10.5px] font-medium">bound</span> {r.bound}
                    </div>
                  ) : null}
                </td>
                <td className="px-3 py-2.5 text-right font-mono text-[12.5px] whitespace-nowrap tabular-nums" title={`${duration(r.php.min)} – ${duration(r.php.max)}`}>
                  {duration(r.php.ns)}
                </td>
                <td className="px-3 py-2.5 text-right font-mono text-[12.5px] whitespace-nowrap tabular-nums" title={`${duration(r.rust.min)} – ${duration(r.rust.max)}`}>
                  {duration(r.rust.ns)}
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex items-center gap-2">
                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                      <div className={`h-full rounded-full ${r.speedup >= (r.bound ? 0.95 : 1) ? 'bg-ok' : 'bg-red-500'}`} style={{ width: `${Math.max(4, (Math.log1p(r.speedup) / Math.log1p(top)) * 100)}%` }} />
                    </div>
                    <span className={`w-12 text-right font-mono text-[12.5px] tabular-nums ${r.speedup >= (r.bound ? 0.95 : 1) ? '' : 'text-red-600 dark:text-red-400'}`}>{times(r.speedup)}</span>
                  </div>
                </td>
                <td className="px-3 py-2.5 text-right font-mono text-[12.5px] tabular-nums text-muted-foreground">
                  {r.rust.allocations === undefined ? '–' : Math.round(r.rust.allocations * 10) / 10}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="m-0 text-[11.5px] text-muted-foreground">
        {env.machine.cpu || env.machine.arch} ({env.machine.os} host) · PHP {env.php.version}
        {env.php.opcache ? `, opcache, JIT ${env.php.jit || 'off'}` : ', no opcache'} · {env.rust.version.split(' (')[0]}, {env.rust.profile} build · {where} · commit{' '}
        <code className="font-mono">{env.commit}</code> · {env.date.slice(0, 10)}. Timings depend on the machine; compare the runtimes, not the numbers across machines.
      </p>
    </MigrationCard>
  )
}

/** A library's overall speedup over PHP, for cards and headers. */
export function SpeedupBadge({ bench }: { bench: { speedup: number; benchmarks: number } | null }) {
  if (!bench) return null
  return (
    <span
      className="inline-flex items-center gap-1 rounded-full bg-ok-soft px-2 py-0.5 text-[11px] font-medium text-ok-ink"
      title={`Geometric mean of ${bench.benchmarks} benchmark${bench.benchmarks === 1 ? '' : 's'} against PHP`}
    >
      <Gauge className="size-3" />
      {times(bench.speedup)} faster
    </span>
  )
}
