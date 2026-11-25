import type { ReactNode } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { ChevronRight } from 'lucide-react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { firewallTabPath } from '@/lib/firewall/conditions'
import { firewallRulesQueryOptions } from '@/lib/react-query/hooks/waf'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type FirewallResourceType = 'functions' | 'sites'

export interface FirewallResourceRow {
  id: string
  name: string
  icon: ReactNode
  updatedAt: string
}

interface FirewallResourceTableProps {
  projectId: string
  resourceType: FirewallResourceType
  /** Column heading for the resource, e.g. "Function". */
  label: string
  rows: FirewallResourceRow[]
}

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'

/** Functions or sites with their rule counts, each opening its own Firewall tab. */
export function FirewallResourceTable({
  projectId,
  resourceType,
  label,
  rows,
}: FirewallResourceTableProps) {
  const t = useT()
  const navigate = useNavigate()

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            <TableHead className={HEAD_CLASS}>{label}</TableHead>
            <TableHead className={cn(HEAD_CLASS, 'w-[96px] text-end')}>
              {t('Rules')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-end')}>
              {t('Updated')}
            </TableHead>
            <TableHead className="w-[48px] px-4 py-3" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => {
            const to = firewallTabPath(projectId, {
              resourceType,
              resourceId: row.id,
            })
            return (
              <TableRow
                key={row.id}
                className="cursor-pointer border-b border-border/50 transition-colors hover:bg-muted/30"
                onClick={(event) => {
                  const target = event.target as HTMLElement
                  if (target.closest('button') || target.closest('a')) return
                  void navigate({ to })
                }}
              >
                <TableCell className="px-4 py-3">
                  <Link to={to} className="group block min-w-0">
                    <div className="flex min-w-0 items-center gap-3">
                      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                        {row.icon}
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-medium text-foreground">
                          {row.name}
                        </p>
                        <div className="mt-0.5">
                          <CopyableId id={row.id} size="xs" />
                        </div>
                      </div>
                    </div>
                  </Link>
                </TableCell>
                <TableCell className="px-4 py-3 text-end">
                  <RuleCount
                    projectId={projectId}
                    resourceType={resourceType}
                    resourceId={row.id}
                  />
                </TableCell>
                <TableCell className="px-4 py-3 text-end">
                  <DateTooltip
                    date={row.updatedAt}
                    className="text-[12px] text-muted-foreground"
                  />
                </TableCell>
                <TableCell className="px-4 py-3 text-end">
                  <ChevronRight
                    className="ms-auto h-4 w-4 text-muted-foreground rtl:-scale-x-100"
                    aria-hidden
                  />
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}

/**
 * Loads after the table, one count per row, so a slow count never holds the
 * list back. Every state keeps the same box, so the row never moves.
 */
function RuleCount({
  projectId,
  resourceType,
  resourceId,
}: {
  projectId: string
  resourceType: FirewallResourceType
  resourceId: string
}) {
  // Only the total is read, so ask for one rule: the same query as the
  // function and site Overview card.
  const { data, isError } = useQuery({
    ...firewallRulesQueryOptions(
      projectId,
      0,
      1,
      undefined,
      resourceType,
      resourceId,
    ),
    // A fresh page load must not wait on the counts behind the fullscreen loader.
    meta: { skipInitialLoader: true },
  })

  return (
    <span
      data-testid="firewall-rule-count"
      className="inline-flex h-5 min-w-6 items-center justify-end text-[13px] tabular-nums"
    >
      {data ? (
        <span
          className={
            data.total > 0 ? 'text-foreground' : 'text-muted-foreground'
          }
        >
          {data.total.toLocaleString()}
        </span>
      ) : isError ? (
        <span className="text-muted-foreground">—</span>
      ) : (
        <Skeleton className="h-3.5 w-6 rounded" />
      )}
    </span>
  )
}
