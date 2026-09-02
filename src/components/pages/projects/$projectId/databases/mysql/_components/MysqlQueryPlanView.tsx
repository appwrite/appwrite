import { useMemo, useState } from 'react'
import type { DedicatedDatabaseQueryExplanation } from '@/lib/databases/dedicated-engine'
import { Loader2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { ConnectCodePanel } from '@/components/global/shared/ConnectCodeExample'
import {
  countMysqlQueryPlanNodes,
  formatMysqlQueryPlanRaw,
  normalizeMysqlQueryPlan,
  type MysqlQueryPlanNode,
  type MysqlQueryPlanNodeVariant,
} from '@/lib/mysql-query-plan'
import { cn } from '@/lib/utils'
import { SqlWorkbenchPanelEmptyState } from './SqlWorkbenchPanelEmptyState'
import {
  SqlQueryPlanModeBadge,
  SqlQueryPlanModeDescription,
} from '@/components/pages/projects/$projectId/databases/_components/SqlQueryPlanModeBadge'
import type { SqlQueryPlanMode } from '@/components/pages/projects/$projectId/databases/_components/sql-query-plan-mode'
import { useT } from '@/lib/i18n/translate'

type MysqlQueryPlanViewProps = {
  explanation: DedicatedDatabaseQueryExplanation | null
  planMode: SqlQueryPlanMode
  isLoading?: boolean
  loadingLabel?: string
  className?: string
}

type PlanViewMode = 'visual' | 'raw'

const VARIANT_BADGE: Record<
  MysqlQueryPlanNodeVariant,
  'success' | 'warning' | 'info' | 'secondary'
> = {
  index: 'success',
  scan: 'warning',
  join: 'info',
  sort: 'info',
  aggregate: 'info',
  detail: 'secondary',
  other: 'secondary',
}

const VARIANT_GUIDE: Record<MysqlQueryPlanNodeVariant, string> = {
  index: 'bg-emerald-500/30',
  scan: 'bg-amber-500/30',
  join: 'bg-slate-500/30',
  sort: 'bg-blue-500/30',
  aggregate: 'bg-violet-500/30',
  detail: 'bg-border/70',
  other: 'bg-border/70',
}

function PlanNodeRow({
  node,
  depth = 0,
}: {
  node: MysqlQueryPlanNode
  depth?: number
}) {
  const isDetail = node.variant === 'detail'

  if (isDetail) {
    return (
      <p className="min-w-0 break-all font-mono text-[11px] leading-relaxed text-muted-foreground">
        {node.label}
      </p>
    )
  }

  return (
    <div className="min-w-0 max-w-full">
      <div className="min-w-0 overflow-hidden rounded-xl border border-border/60 bg-card/40 px-3.5 py-3 shadow-sm">
        <div className="flex min-w-0 flex-col gap-2.5 lg:flex-row lg:items-start lg:justify-between">
          <div className="min-w-0 flex-1 space-y-2 overflow-hidden">
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <Badge
                variant={VARIANT_BADGE[node.variant]}
                className="text-[10px] shrink-0"
              >
                {node.nodeType}
              </Badge>
              <p className="min-w-0 break-all text-[13px] font-medium leading-snug text-foreground">
                {node.label}
              </p>
            </div>

            {node.details.length > 0 ? (
              <div className="min-w-0 space-y-1 overflow-hidden rounded-lg bg-muted/25 px-2.5 py-2">
                {node.details.map((detail) => (
                  <p
                    key={detail}
                    className="min-w-0 break-all font-mono text-[11px] leading-relaxed text-muted-foreground"
                  >
                    {detail}
                  </p>
                ))}
              </div>
            ) : null}
          </div>

          {node.metrics.length > 0 ? (
            <div className="flex min-w-0 flex-wrap gap-1.5 lg:max-w-[min(100%,22rem)] lg:justify-end">
              {node.metrics.map((metric) => (
                <span
                  key={`${metric.label}-${metric.value}`}
                  className="inline-flex items-center gap-1.5 rounded-md bg-muted/40 px-2 py-1 text-[11px] text-muted-foreground"
                >
                  <span className="font-medium uppercase tracking-wide">
                    {metric.label}
                  </span>
                  <span className="tabular-nums text-foreground/90">
                    {metric.value}
                  </span>
                </span>
              ))}
            </div>
          ) : null}
        </div>
      </div>

      {node.children.length > 0 ? (
        <div
          className={cn(
            'relative mt-2.5 min-w-0 space-y-2.5',
            depth > 0 ? 'ms-3 ps-4' : 'ms-2 ps-4',
          )}
        >
          <div
            className={cn(
              'absolute bottom-2 start-0 top-2 w-px',
              VARIANT_GUIDE[node.variant],
            )}
            aria-hidden
          />
          {node.children.map((child) => (
            <PlanNodeRow key={child.id} node={child} depth={depth + 1} />
          ))}
        </div>
      ) : null}
    </div>
  )
}

export function MysqlQueryPlanView({
  explanation,
  planMode,
  isLoading = false,
  loadingLabel = 'Explaining query…',
  className,
}: MysqlQueryPlanViewProps) {
  const t = useT()
  const [viewMode, setViewMode] = useState<PlanViewMode>('visual')

  const planNodes = useMemo(
    () => (explanation ? normalizeMysqlQueryPlan(explanation) : []),
    [explanation],
  )
  const nodeCount = countMysqlQueryPlanNodes(planNodes)
  const rawPlan = useMemo(
    () => (explanation ? formatMysqlQueryPlanRaw(explanation) : ''),
    [explanation],
  )
  const rawLanguage =
    rawPlan.trimStart().startsWith('{') || rawPlan.trimStart().startsWith('[')
      ? 'json'
      : 'sql'

  return (
    <div className={cn('flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden', className)}>
      <div className="shrink-0 border-b border-border px-4 py-3 sm:px-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0">
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <h3 className="text-[13px] font-semibold text-foreground">
                {t('Query plan')}
              </h3>
              <SqlQueryPlanModeBadge mode={planMode} />
            </div>
            <SqlQueryPlanModeDescription mode={planMode} />
            <p className="mt-2 text-[12px] text-muted-foreground">
              <span className="tabular-nums text-foreground/90">
                {nodeCount.toLocaleString()}
              </span>{' '}
              step{nodeCount === 1 ? '' : 's'}
            </p>
          </div>
          <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={viewMode}
            onValueChange={(value) => {
              if (value === 'visual' || value === 'raw') {
                setViewMode(value)
              }
            }}
            className="shrink-0"
          >
            <ToggleGroupItem value="visual" className="h-8 px-3 text-[12px]">
              {t('Visual')}
            </ToggleGroupItem>
            <ToggleGroupItem value="raw" className="h-8 px-3 text-[12px]">
              {t('Raw')}
            </ToggleGroupItem>
          </ToggleGroup>
        </div>
      </div>

      <div className="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-4 py-4 sm:px-6">
        {isLoading && !explanation ? (
          <div className="flex min-h-[8rem] items-center justify-center gap-2 text-[13px] text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            {loadingLabel}
          </div>
        ) : viewMode === 'raw' ? (
          rawPlan ? (
            <ConnectCodePanel
              code={rawPlan}
              language={rawLanguage}
              headless
              wrapLines
              className="min-h-[8rem] rounded-lg border border-border"
            />
          ) : (
            <SqlWorkbenchPanelEmptyState variant="query-no-rows" />
          )
        ) : planNodes.length > 0 ? (
          <div className="min-w-0 max-w-full space-y-3 pb-1">
            {planNodes.map((node) => (
              <PlanNodeRow key={node.id} node={node} />
            ))}
          </div>
        ) : rawPlan ? (
          <ConnectCodePanel
            code={rawPlan}
            language={rawLanguage}
            headless
            wrapLines
            className="min-h-[8rem] rounded-lg border border-border"
          />
        ) : (
          <SqlWorkbenchPanelEmptyState variant="query-no-rows" />
        )}
      </div>
    </div>
  )
}
