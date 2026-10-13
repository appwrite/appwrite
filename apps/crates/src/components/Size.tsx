import { FileCode } from 'lucide-react'
import { formatCount, type Lines, type LibrarySummary } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'

const split = (l: Lines) => `${formatCount(l.code)} code, ${formatCount(l.comments)} comments, ${formatCount(l.blank)} blank, in ${formatCount(l.files)} ${l.files === 1 ? 'file' : 'files'}`

/** `12.3k lines`, for tight places. */
export function shortLines(n: number) {
  return n >= 10_000 ? `${Math.round(n / 1000)}k` : n >= 1000 ? `${(n / 1000).toFixed(1)}k` : String(n)
}

/** A library's lines of Rust, for cards and rows. */
export function LinesBadge({ lib }: { lib: LibrarySummary }) {
  const rust = lib.size?.rust
  if (!rust) return null
  return (
    <span className="inline-flex items-center gap-1" title={`Rust: ${formatCount(rust.total)} lines (${split(rust)}); tests ${formatCount(rust.tests.total)} more`}>
      <FileCode className="size-3" />
      {shortLines(rust.total)} lines
    </span>
  )
}

/** The size table: one row per part, code, comments and blank lines. */
export function SizeTable({ lib }: { lib: LibrarySummary }) {
  const migration = useMigration()
  const s = lib.size
  if (!s) return null
  const rows: { label: string; lines: Lines }[] = [
    ...(s.rust ? [{ label: 'Rust source', lines: s.rust }, { label: 'Rust tests', lines: s.rust.tests }] : []),
    ...(s.php && (migration || !s.rust) ? [{ label: 'PHP source', lines: s.php }, { label: 'PHP tests', lines: s.php.tests }] : []),
  ].filter((r) => r.lines.total > 0 || r.label.endsWith('source'))
  if (!rows.length) return null
  const cell = 'px-3 py-1.5 text-right tabular-nums'
  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <table className="w-full min-w-[460px] border-collapse text-[12.5px]">
        <thead className="bg-muted/50 text-[11px] tracking-wider text-muted-foreground uppercase">
          <tr>
            <th className="px-3 py-1.5 text-left font-medium">Part</th>
            <th className={`${cell} font-medium`}>Code</th>
            <th className={`${cell} font-medium`}>Comments</th>
            <th className={`${cell} font-medium`}>Blank</th>
            <th className={`${cell} font-medium`}>Total</th>
            <th className={`${cell} font-medium`}>Files</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.label} className="border-t border-border">
              <td className="px-3 py-1.5">{r.label}</td>
              <td className={cell}>{formatCount(r.lines.code)}</td>
              <td className={cell}>{formatCount(r.lines.comments)}</td>
              <td className={cell}>{formatCount(r.lines.blank)}</td>
              <td className={`${cell} font-medium`}>{formatCount(r.lines.total)}</td>
              <td className={`${cell} text-muted-foreground`}>{formatCount(r.lines.files)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * The compact size line under a library's title: lines of Rust (and of PHP in the migration view), the split on
 * hover, and the table on demand.
 */
export function SizeLine({ lib }: { lib: LibrarySummary }) {
  const migration = useMigration()
  const s = lib.size
  if (!s || (!s.rust && !(migration && s.php))) return null
  const parts = [
    s.rust ? { key: 'rust', text: `Rust ${formatCount(s.rust.total)} lines (${formatCount(s.rust.code)} code)`, title: `Rust: ${split(s.rust)}` } : null,
    s.php && migration ? { key: 'php', text: `PHP ${formatCount(s.php.total)} lines (${formatCount(s.php.code)} code)`, title: `PHP: ${split(s.php)}` } : null,
    migration && lib.compat ? { key: 'compat', text: `${formatCount(lib.compat.cases)} cases over ${formatCount(lib.compat.operations)} operations`, title: 'The compat suite' } : null,
  ].filter((p): p is { key: string; text: string; title: string } => !!p)
  return (
    <details className="group mt-1.5 text-[13px] text-muted-foreground">
      <summary className="inline-flex cursor-pointer list-none flex-wrap items-center gap-x-1.5 hover:text-foreground [&::-webkit-details-marker]:hidden">
        <FileCode className="size-3.5" />
        {parts.map((p, i) => (
          <span key={p.key} title={p.title}>
            {i ? '· ' : ''}
            {p.text}
          </span>
        ))}
        <span className="text-[11.5px] underline underline-offset-2 group-open:hidden">split</span>
      </summary>
      <div className="mt-2 max-w-[640px]">
        <SizeTable lib={lib} />
        <p className="m-0 mt-1.5 text-[11.5px]">
          Comments include doc comments. Rust tests are <code className="font-mono">tests/</code> and <code className="font-mono">#[cfg(test)]</code> modules; PHP tests are the
          package's <code className="font-mono">tests/</code>.
</p>
      </div>
    </details>
  )
}
