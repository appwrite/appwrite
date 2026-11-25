import { ChangeBadge } from './ChangeBadge'

/** Compact stat tile for summary cards outside the analytics page (Sites, Auth users). */
export function MiniStat({
  label,
  value,
  change,
  invert,
}: {
  label: string
  value: string | null
  change?: number
  invert?: boolean
}) {
  return (
    <div className="flex h-[52px] min-w-0 items-center rounded-lg border border-border/60 bg-background/40 px-3">
      <div className="min-w-0">
        <p className="truncate text-[11px] text-muted-foreground">{label}</p>
        <p className="flex h-5 items-baseline gap-1.5">
          {value === null ? (
            <span className="mt-1 h-3 w-10 animate-pulse rounded bg-muted" />
          ) : (
            <>
              <span className="text-[13px] font-semibold tabular-nums text-foreground">
                {value}
              </span>
              <ChangeBadge change={change} invert={invert} />
            </>
          )}
        </p>
      </div>
    </div>
  )
}
