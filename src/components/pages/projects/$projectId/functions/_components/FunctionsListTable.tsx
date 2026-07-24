import { Link, useNavigate } from '@tanstack/react-router'
import { Bell, Clock } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { cn } from '@/lib/utils'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  DeploymentResourceStatusBadges,
  getActiveDeploymentCreatedAt,
  resourceHasInProgressDeployment,
} from '../../shared/DeploymentResourceStatusBadges'
import { formatCronExpression } from '../CronScheduleEditor'
import { FunctionContextMenu } from './FunctionContextMenu'
import { useT } from '@/lib/i18n/translate'

function formatRuntimeLabel(runtime: string) {
  if (!runtime) return 'Unknown runtime'
  return runtime.split('-').join(' ')
}

export function FunctionCronBadge({ schedule }: { schedule: string }) {
  const t = useT()

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/40 px-1.5 py-0.5 text-[11px] text-muted-foreground">
          <Clock className="h-3 w-3 shrink-0" />
          {t('Cron')}
        </span>
      </TooltipTrigger>
      <TooltipContent>
        <p>{t(formatCronExpression(schedule))}</p>
      </TooltipContent>
    </Tooltip>
  )
}

function FunctionListTriggers({
  schedule,
  eventCount,
}: {
  schedule?: string
  eventCount: number
}) {
  const t = useT()
  if (!schedule && eventCount === 0) {
    return <span className="text-[12px] text-muted-foreground/50">-</span>
  }

  return (
    <TooltipProvider delayDuration={0}>
      <div className="flex items-center gap-1.5">
        {schedule ? <FunctionCronBadge schedule={schedule} /> : null}
        {eventCount > 0 ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/40 px-1.5 py-0.5 text-[11px] text-muted-foreground">
                <Bell className="h-3 w-3 shrink-0" />
                {eventCount}
              </span>
            </TooltipTrigger>
            <TooltipContent>
              <p>
                {eventCount === 1
                  ? t('1 event trigger')
                  : `${eventCount} ${t('event triggers')}`}
              </p>
            </TooltipContent>
          </Tooltip>
        ) : null}
      </div>
    </TooltipProvider>
  )
}

export {
  getActiveDeploymentCreatedAt,
  resourceHasInProgressDeployment as functionHasInProgressDeployment,
}

type FunctionsListTableProps = {
  projectId: string
  functions: Models.Function[]
  selectedFunctionIds: Set<string>
  onToggleFunction: (functionId: string) => void
  onToggleAll: () => void
}

export function FunctionsListTable({
  projectId,
  functions,
  selectedFunctionIds,
  onToggleFunction,
  onToggleAll,
}: FunctionsListTableProps) {
  const t = useT()
  const navigate = useNavigate()

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            <TableHead className="w-[40px] px-4">
              <Checkbox
                checked={
                  functions.length > 0 &&
                  selectedFunctionIds.size === functions.length
                }
                onCheckedChange={onToggleAll}
              />
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Function')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Runtime')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Status')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Last deployed')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Triggers')}
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Created')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {functions.map((func) => {
            const activeDeploymentCreatedAt = getActiveDeploymentCreatedAt(func)

            return (
              <FunctionContextMenu
                key={func.$id}
                projectId={projectId}
                func={{ $id: func.$id, name: func.name }}
              >
                <TableRow
                  className={cn(
                    'cursor-pointer border-b border-border/50 transition-colors',
                    selectedFunctionIds.has(func.$id)
                      ? 'bg-muted'
                      : 'hover:bg-muted/30',
                  )}
                  onClick={(event) => {
                    const target = event.target as HTMLElement
                    if (
                      target.closest('button') ||
                      target.closest('[role="checkbox"]') ||
                      target.closest('a')
                    ) {
                      return
                    }
                    navigate({
                      to: '/projects/$projectId/functions/$functionId',
                      params: { projectId, functionId: func.$id },
                    })
                  }}
                >
                  <TableCell
                    onClick={(event) => event.stopPropagation()}
                    className="px-4 py-3"
                  >
                    <Checkbox
                      checked={selectedFunctionIds.has(func.$id)}
                      onCheckedChange={() => onToggleFunction(func.$id)}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Link
                      to="/projects/$projectId/functions/$functionId"
                      params={{ projectId, functionId: func.$id }}
                      className="group block min-w-0"
                    >
                      <div className="flex min-w-0 items-center gap-3">
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                          <RuntimeIcon runtime={func.runtime || ''} size="sm" />
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-[13px] font-medium text-foreground">
                            {func.name || t('Unnamed Function')}
                          </p>
                          <div className="mt-0.5">
                            <CopyableId id={func.$id} size="xs" />
                          </div>
                        </div>
                      </div>
                    </Link>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="text-[12px] text-muted-foreground">
                      {t(formatRuntimeLabel(func.runtime || ''))}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DeploymentResourceStatusBadges resource={func} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {activeDeploymentCreatedAt ? (
                      <DateTooltip
                        date={activeDeploymentCreatedAt}
                        live
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground/50">
                        {t('Never')}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <FunctionListTriggers
                      schedule={func.schedule || undefined}
                      eventCount={func.events?.length ?? 0}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    {func.$createdAt ? (
                      <DateTooltip
                        date={func.$createdAt}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground/50">
                        -
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              </FunctionContextMenu>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}
