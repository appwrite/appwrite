import type { ReactNode } from 'react'
import { Globe } from 'lucide-react'
import { cn } from '@/lib/utils'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
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
 * Flag for an ISO-3166-1 alpha-2 country code. The breakdown API returns
 * ISO-2 for `country`, so this can be rendered directly; anything that is not
 * a two-letter code falls back to a globe.
 */
export function CountryFlag({ code }: { code: string }) {
  const normalized = code?.trim().toLowerCase()
  const isIso2 = /^[a-z]{2}$/.test(normalized ?? '')

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

  return (
    <span className="flex h-4 w-4 shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-background">
      <img
        src={`${getBaseEndpoint()}/avatars/flags/${normalized}?width=20&height=20&quality=100&project=console`}
        alt=""
        className="h-full w-full object-cover"
        loading="lazy"
      />
    </span>
  )
}
