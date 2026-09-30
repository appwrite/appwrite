import type { ReactNode } from 'react'
import { Globe } from 'lucide-react'
import { cn } from '@/lib/utils'
import { formatNumber } from './format'

/**
 * One ranked row in a breakdown panel: a proportional background bar, an
 * optional leading badge (flag, colour dot, rank), the dimension value, its
 * share of the total and the raw count.
 *
 * Shared by every breakdown panel so the panels cannot drift apart visually.
 */
export function BreakdownRow({
  label,
  value,
  share,
  barPercent,
  leading,
  color,
  mono = false,
}: {
  label: string
  value: number
  /** Share of the column total, 0-100. */
  share: number
  /** Width of the background bar relative to the top row, 0-100. */
  barPercent: number
  leading?: ReactNode
  /** Bar colour; falls back to a neutral accent bar. */
  color?: string
  /** Render the label in a monospace face (paths, hostnames). */
  mono?: boolean
}) {
  return (
    <div className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50">
      <div
        className={cn(
          'absolute inset-y-0 start-0 rounded-md transition-all',
          color
            ? 'group-hover:opacity-80'
            : 'bg-accent/30 group-hover:bg-accent/50',
        )}
        style={{
          width: `${Math.max(0, Math.min(100, barPercent))}%`,
          ...(color ? { backgroundColor: color, opacity: 0.15 } : {}),
        }}
      />
      <div className="relative flex min-w-0 flex-1 items-center gap-2">
        {leading}
        <span
          className={cn(
            'min-w-0 flex-1 truncate text-[12px] font-medium text-foreground',
            mono && 'font-mono',
          )}
          title={label}
        >
          {label}
        </span>
        <div className="flex shrink-0 items-center gap-3">
          <span className="text-[11px] font-medium tabular-nums text-muted-foreground">
            {Math.round(share)}%
          </span>
          <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
            {formatNumber(value)}
          </span>
        </div>
      </div>
    </div>
  )
}

/** Coloured dot used by the categorical panels (channels, composition). */
export function RowDot({ color }: { color: string }) {
  return (
    <span
      className="h-2.5 w-2.5 shrink-0 rounded-full"
      style={{ backgroundColor: color }}
      aria-hidden
    />
  )
}

/** Monospace rank badge used by the page panels. */
export function RowRank({ index }: { index: number }) {
  return (
    <span className="shrink-0 font-mono text-[11px] text-muted-foreground">
      {index + 1}
    </span>
  )
}

/**
 * Flag for an ISO-3166-1 alpha-2 country code, derived as a regional-indicator
 * emoji pair. The `country` dimension is the only ISO-2 one, so only that panel
 * passes codes here.
 *
 * This deliberately does not fetch `/avatars/flags/...`. That endpoint needs a
 * project in the query string plus `avatars.read` on it, which on a
 * project-scoped page means either the wrong project or a permission the viewer
 * may not have, so the images silently failed and left empty boxes. Deriving
 * the glyph needs no request, no auth and no project coupling.
 *
 * Caveat: regional-indicator pairs do not render as flags on most Windows
 * builds, which show two letter boxes instead. That is a platform font
 * limitation rather than a bug, and the country code is printed beside the
 * glyph anyway. Do not "fix" it by reintroducing an image fetch.
 */
export function CountryFlag({ code }: { code: string | null | undefined }) {
  const trimmed = code?.trim() ?? ''
  // The geo stack stores codes lowercased and uses `--` for unknown, which the
  // enrichment maps to an empty string. Anything that is not exactly two ASCII
  // letters falls back to the globe rather than emitting garbage glyphs.
  const isIso2 = /^[a-zA-Z]{2}$/.test(trimmed)

  if (!isIso2) {
    return (
      <span
        className="flex h-4 w-4 shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-muted/30"
        aria-hidden
      >
        <Globe className="h-2.5 w-2.5 text-muted-foreground" />
      </span>
    )
  }

  // Offset each letter from ASCII 'A' into the regional-indicator block.
  const flag = String.fromCodePoint(
    ...[...trimmed.toUpperCase()].map(
      (letter) => 0x1f1e6 + letter.charCodeAt(0) - 65,
    ),
  )

  return (
    // Decorative: the row label prints the same country code beside it.
    <span
      className="w-4 shrink-0 text-center text-[13px] leading-none"
      aria-hidden
    >
      {flag}
    </span>
  )
}
