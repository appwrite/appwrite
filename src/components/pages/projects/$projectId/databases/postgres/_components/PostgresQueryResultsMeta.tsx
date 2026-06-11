import { Badge } from '@/components/ui/badge'
import {
  formatPostgresQueryDurationMs,
  formatPostgresRowCount,
} from '@/lib/postgres-sql'

type PostgresQueryResultsMetaProps = {
  title: string
  rowCount: number
  durationMs?: number
  truncated?: boolean
}

export function PostgresQueryResultsMeta({
  title,
  rowCount,
  durationMs,
  truncated,
}: PostgresQueryResultsMetaProps) {
  return (
    <div className="flex min-w-0 items-center justify-between gap-3">
      <h3 className="min-w-0 truncate text-[13px] font-semibold text-foreground">
        {title}
      </h3>
      <div className="flex shrink-0 items-center gap-2">
        <p className="flex items-center gap-x-2 text-[12px] text-muted-foreground">
          <span className="tabular-nums text-foreground/90">
            {formatPostgresRowCount(rowCount)}
          </span>
          {durationMs != null ? (
            <>
              <span
                aria-hidden
                className="text-muted-foreground/40"
              >
                ·
              </span>
              <span className="tabular-nums">
                {formatPostgresQueryDurationMs(durationMs)}
              </span>
            </>
          ) : null}
        </p>
        {truncated ? (
          <Badge
            variant="warning"
            className="text-[10px]"
          >
            Truncated
          </Badge>
        ) : null}
      </div>
    </div>
  )
}
