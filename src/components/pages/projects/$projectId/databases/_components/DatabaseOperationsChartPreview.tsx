import { useId, useMemo } from 'react'
import { Area, AreaChart, ResponsiveContainer } from 'recharts'
import { DATABASE_CLUSTER_PREVIEW_HEIGHT } from './DatabaseClusterPreview'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const READ_COLOR = 'var(--chart-brand)'
const WRITE_COLOR = 'var(--chart-2)'

type OperationsPoint = {
  index: number
  reads: number
  writes: number
}

function hashSeed(input: string): number {
  let hash = 0
  for (let i = 0; i < input.length; i++) {
    hash = (hash * 31 + input.charCodeAt(i)) >>> 0
  }
  return hash
}

/**
 * Deterministically pick a few databases to show mock traffic until
 * real read/write metrics are available for every serverless database.
 */
export function shouldMockServerlessOperations(databaseId: string): boolean {
  return hashSeed(databaseId) % 3 === 0
}

function buildMockOperationsSeries(databaseId: string): OperationsPoint[] {
  const seed = hashSeed(databaseId)
  return Array.from({ length: 16 }, (_, index) => {
    const wave = Math.sin((index + seed % 7) / 2.4)
    const wave2 = Math.cos((index + seed % 5) / 3.1)
    return {
      index,
      reads: Math.max(0, Math.round(42 + wave * 28 + (seed % 11))),
      writes: Math.max(0, Math.round(18 + wave2 * 12 + (seed % 5))),
    }
  })
}

function buildEmptyOperationsSeries(): OperationsPoint[] {
  return Array.from({ length: 16 }, (_, index) => ({
    index,
    reads: 0,
    writes: 0,
  }))
}

type DatabaseOperationsChartPreviewProps = {
  databaseId: string
  className?: string
}

/**
 * Card mid-section for serverless databases: read + write operations sparkline.
 * Uses mock series for a subset of databases until live metrics land.
 */
export function DatabaseOperationsChartPreview({
  databaseId,
  className,
}: DatabaseOperationsChartPreviewProps) {
  const t = useT()
  const gradientId = useId().replace(/:/g, '')
  const showMock = shouldMockServerlessOperations(databaseId)
  const chartData = useMemo(
    () =>
      showMock
        ? buildMockOperationsSeries(databaseId)
        : buildEmptyOperationsSeries(),
    [databaseId, showMock],
  )
  const totals = useMemo(() => {
    return chartData.reduce(
      (acc, point) => ({
        reads: acc.reads + point.reads,
        writes: acc.writes + point.writes,
      }),
      { reads: 0, writes: 0 },
    )
  }, [chartData])

  return (
    <div
      className={cn(
        '-mx-4 mt-2 min-w-0 shrink-0 border-t border-border',
        className,
      )}
    >
      <div
        className="flex min-h-0 min-w-0 flex-col"
        style={{ height: DATABASE_CLUSTER_PREVIEW_HEIGHT }}
      >
        <div className="shrink-0 px-4 pt-2.5 pb-3">
          <div className="flex h-5 min-w-0 items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
              <span className="inline-flex items-center gap-1.5 text-[12px] font-medium leading-none text-muted-foreground">
                <span
                  className="h-1.5 w-1.5 rounded-full"
                  style={{ backgroundColor: READ_COLOR }}
                  aria-hidden
                />
                {t('Reads')}
                <span className="font-mono tabular-nums text-foreground">
                  {totals.reads.toLocaleString()}
                </span>
              </span>
              <span className="inline-flex items-center gap-1.5 text-[12px] font-medium leading-none text-muted-foreground">
                <span
                  className="h-1.5 w-1.5 rounded-full"
                  style={{ backgroundColor: WRITE_COLOR }}
                  aria-hidden
                />
                {t('Writes')}
                <span className="font-mono tabular-nums text-foreground">
                  {totals.writes.toLocaleString()}
                </span>
              </span>
            </div>
            <span className="shrink-0 text-[11px] font-medium leading-none text-muted-foreground/80">
              {t('Last 24 hours')}
            </span>
          </div>
        </div>

        <div
          className="relative min-h-0 w-full min-w-0 flex-1 overflow-hidden"
          role="img"
          aria-label={`${t('Read and write operations')}. ${t('Last 24 hours')}`}
        >
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart
              data={chartData}
              margin={{ top: 0, right: 0, left: 0, bottom: 0 }}
            >
              <defs>
                <linearGradient
                  id={`reads-${gradientId}`}
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop
                    offset="0%"
                    stopColor={READ_COLOR}
                    stopOpacity={0.28}
                  />
                  <stop
                    offset="100%"
                    stopColor={READ_COLOR}
                    stopOpacity={0}
                  />
                </linearGradient>
                <linearGradient
                  id={`writes-${gradientId}`}
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop
                    offset="0%"
                    stopColor={WRITE_COLOR}
                    stopOpacity={0.18}
                  />
                  <stop
                    offset="100%"
                    stopColor={WRITE_COLOR}
                    stopOpacity={0}
                  />
                </linearGradient>
              </defs>
              <Area
                type="monotone"
                dataKey="reads"
                stroke={READ_COLOR}
                strokeWidth={1.5}
                fill={`url(#reads-${gradientId})`}
                isAnimationActive={!CHART_ANIMATION_DISABLED}
                dot={false}
                activeDot={false}
              />
              <Area
                type="monotone"
                dataKey="writes"
                stroke={WRITE_COLOR}
                strokeWidth={1.5}
                fill={`url(#writes-${gradientId})`}
                isAnimationActive={!CHART_ANIMATION_DISABLED}
                dot={false}
                activeDot={false}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  )
}
