import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import {
  Bell,
  ChevronRight,
  Copy,
  FileJson,
  Gauge,
  Loader2,
  Mail,
  Pencil,
  Plus,
  Search,
  Trash2,
  Webhook,
  type LucideIcon,
} from 'lucide-react'
import { toast } from 'sonner'
import {
  bucketsQueryOptions,
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  fetchMonitor,
  useMonitors,
  useDeleteMonitor,
  useUpdateMonitor,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import type { MonitorChannel } from '@appwrite.io/console'
import type { ParsedMonitor } from '@/lib/appwrite/monitors'
import {
  COMPARATORS,
  METRICS,
  RESOURCE_TYPES,
  WINDOWS,
  formatMetricValue,
  thresholdRatio,
} from '@/lib/alerts/metrics'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { MonitorsEmptyState } from './MonitorsEmptyState'
import { MonitorDrawer } from './MonitorDrawer'
import { CreateMonitor } from './CreateMonitor'
import type { RuleForm } from './RuleFields'
import { RuleSparkline } from './RuleChart'
import { ruleState, type RuleState } from './StatusPill'

const STATE_ORDER: Record<RuleState, number> = {
  firing: 0,
  pending: 1,
  ok: 2,
  paused: 3,
}

const CHANNELS: Record<MonitorChannel, { label: string; icon: LucideIcon }> = {
  email: { label: 'Email', icon: Mail },
  console: { label: 'Console', icon: Bell },
  webhook: { label: 'Webhook', icon: Webhook },
}

const STATE_STYLES: Record<
  RuleState,
  { dot: string; text: string; label: string }
> = {
  firing: {
    dot: 'bg-red-500',
    text: 'text-red-600 dark:text-red-400',
    label: 'Firing',
  },
  ok: {
    dot: 'bg-emerald-500',
    text: 'text-foreground',
    label: 'OK',
  },
  pending: {
    dot: 'border-[1.5px] border-blue-600 dark:border-blue-400',
    text: 'text-muted-foreground',
    label: 'Pending',
  },
  paused: {
    dot: 'bg-muted-foreground/50',
    text: 'text-muted-foreground',
    label: 'Paused',
  },
}

type Filter = 'all' | RuleState

/** Resolves database, bucket and dedicated database names for the monitors that watch one. */
function useResourceNames(projectId: string, monitors: ParsedMonitor[]) {
  const types = new Set(monitors.map((monitor) => monitor.resourceType))
  const databases = useQuery({
    ...databasesQueryOptions(projectId, 0, 100),
    enabled: types.has('database'),
  })
  const buckets = useQuery({
    ...bucketsQueryOptions(projectId, 0, 100),
    enabled: types.has('bucket'),
  })
  const dedicated = useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    enabled: types.has('dedicatedDatabase'),
  })
  return useMemo(() => {
    const names = new Map<string, string>()
    for (const item of [
      ...(databases.data?.databases ?? []),
      ...(buckets.data?.buckets ?? []),
      ...(dedicated.data?.databases ?? []),
    ]) {
      names.set(item.$id, item.name)
    }
    return names
  }, [databases.data, buckets.data, dedicated.data])
}

interface MonitorsProps {
  projectId: string
  createDisabled?: boolean
  createDisabledTooltip?: string
  /** Opens the create form filled with these fields, e.g. from a usage page. */
  prefill?: Partial<RuleForm>
  onPrefillConsumed?: () => void
  /** Opens this monitor's drawer once the monitors have loaded, e.g. from an alert email. */
  openMonitorId?: string
  onOpenMonitorConsumed?: () => void
}

export function Monitors({
  projectId,
  createDisabled = false,
  createDisabledTooltip,
  prefill,
  onPrefillConsumed,
  openMonitorId,
  onOpenMonitorConsumed,
}: MonitorsProps) {
  const t = useT()
  const { monitors, isLoading } = useMonitors(projectId)
  const updateMutation = useUpdateMonitor(projectId)
  const deleteMutation = useDeleteMonitor(projectId)
  const resourceNames = useResourceNames(projectId, monitors)

  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [createOpen, setCreateOpen] = useState(false)
  const [initial, setInitial] = useState<Partial<RuleForm> | undefined>()
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selected, setSelected] = useState<ParsedMonitor | null>(null)
  const [toDelete, setToDelete] = useState<ParsedMonitor | null>(null)

  const openCreate = (fields?: Partial<RuleForm>) => {
    if (createDisabled) return
    setInitial(fields)
    openDialogAfterOverlayCloses(() => setCreateOpen(true))
  }

  useEffect(() => {
    if (!prefill || createDisabled) return
    setInitial(prefill)
    setCreateOpen(true)
    onPrefillConsumed?.()
  }, [prefill, createDisabled, onPrefillConsumed])

  useEffect(() => {
    if (!openMonitorId || isLoading) return
    onOpenMonitorConsumed?.()
    const open = (monitor: ParsedMonitor) => {
      setSelected(monitor)
      setDrawerOpen(true)
    }
    const listed = monitors.find((candidate) => candidate.$id === openMonitorId)
    if (listed) {
      open(listed)
      return
    }
    // The list holds the first page only; a linked monitor can be beyond it.
    fetchMonitor(projectId, openMonitorId).then(open, (err: Error) =>
      toast.error(getErrorMessage(err, t('Monitor not found'))),
    )
  }, [openMonitorId, isLoading, monitors, onOpenMonitorConsumed, projectId, t])

  const counts = useMemo(() => {
    const result: Record<Filter, number> = {
      all: monitors.length,
      firing: 0,
      pending: 0,
      ok: 0,
      paused: 0,
    }
    for (const monitor of monitors) result[ruleState(monitor)]++
    return result
  }, [monitors])

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase()
    return monitors
      .filter((monitor) => filter === 'all' || ruleState(monitor) === filter)
      .filter(
        (monitor) =>
          !query ||
          monitor.name.toLowerCase().includes(query) ||
          monitor.metric.toLowerCase().includes(query) ||
          t(METRICS[monitor.metric]?.label ?? '')
            .toLowerCase()
            .includes(query) ||
          monitor.$id.toLowerCase().includes(query),
      )
      .sort(
        (a, b) =>
          STATE_ORDER[ruleState(a)] - STATE_ORDER[ruleState(b)] ||
          a.name.localeCompare(b.name),
      )
  }, [monitors, search, filter, t])

  // The listed copy follows updates and refetches; `selected` is a snapshot.
  const current =
    monitors.find((monitor) => monitor.$id === selected?.$id) ?? selected

  const handleOpen = (monitor: ParsedMonitor) => {
    openDialogAfterOverlayCloses(() => {
      setSelected(monitor)
      setDrawerOpen(true)
    })
  }

  const handleToggle = (monitor: ParsedMonitor) => {
    updateMutation.mutate(
      { monitorId: monitor.$id, changes: { enabled: !monitor.enabled } },
      {
        onSuccess: () =>
          toast.success(
            monitor.enabled
              ? t('Monitor has been paused')
              : t('Monitor has been resumed'),
          ),
        onError: (error: Error) =>
          toast.error(getErrorMessage(error, t('Failed to update monitor'))),
      },
    )
  }

  const requestDelete = (monitor: ParsedMonitor) =>
    openDialogAfterOverlayCloses(() => setToDelete(monitor))

  const handleDelete = () => {
    if (!toDelete) return
    const monitorId = toDelete.$id
    deleteMutation.mutate(monitorId, {
      onSuccess: () => {
        closeDialogBeforeOverlayUnmount(() => {
          setToDelete(null)
          setSelected(null)
        })
        toast.success(t('Monitor has been deleted'))
      },
      onError: (error: Error) =>
        toast.error(getErrorMessage(error, t('Failed to delete monitor'))),
    })
  }

  /** `Outbound bandwidth > 10 GB within 1 hour` */
  const sentence = (monitor: ParsedMonitor) => {
    const definition = METRICS[monitor.metric]
    const symbol =
      COMPARATORS.find((entry) => entry.value === monitor.comparator)?.symbol ??
      monitor.comparator
    const window = WINDOWS.find((entry) => entry.value === monitor.window)
    const base = `${t(definition?.label ?? monitor.metric)} ${symbol} ${formatMetricValue(monitor.metric, monitor.threshold)}`
    if (!definition?.counter) return base
    return `${base} ${t('within')} ${window ? t(window.label) : `${monitor.window}s`}`
  }

  const scope = (monitor: ParsedMonitor) => {
    if (monitor.resourceType === 'project') return t('Project')
    const type = RESOURCE_TYPES.find(
      (entry) => entry.value === monitor.resourceType,
    )
    return `${t(type?.label ?? monitor.resourceType)} · ${resourceNames.get(monitor.resourceId) ?? monitor.resourceId}`
  }

  const createButton = (
    <Button
      size="sm"
      className="h-9 text-[13px]"
      onClick={() => openCreate()}
      disabled={createDisabled}
    >
      <Plus className="me-1.5 h-4 w-4" />
      {t('Create monitor')}
    </Button>
  )

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div>
      {monitors.length === 0 ? (
        <MonitorsEmptyState
          projectId={projectId}
          onCreate={() => openCreate()}
          onPreset={(preset) => openCreate(preset.form)}
          createDisabled={createDisabled}
          createDisabledTooltip={createDisabledTooltip}
        />
      ) : (
        <>
          <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div
              role="tablist"
              aria-label={t('Filter monitors')}
              className="flex flex-wrap gap-1.5"
            >
              {(['all', 'firing', 'ok', 'pending', 'paused'] as Filter[]).map(
                (key) => (
                  <button
                    key={key}
                    type="button"
                    role="tab"
                    aria-selected={filter === key}
                    onClick={() => setFilter(key)}
                    className={cn(
                      'inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-[12px] font-medium transition-colors',
                      filter === key
                        ? 'border-foreground/20 bg-accent text-foreground'
                        : 'border-border text-muted-foreground hover:text-foreground',
                    )}
                  >
                    {key === 'firing' && counts.firing > 0 && (
                      <span className="h-1.5 w-1.5 rounded-full bg-red-500" />
                    )}
                    {key === 'all'
                      ? t('All')
                      : key === 'firing'
                        ? t('Firing')
                        : key === 'ok'
                          ? t('OK')
                          : key === 'pending'
                            ? t('Pending')
                            : t('Paused')}
                    <span className="tabular-nums text-muted-foreground">
                      {counts[key]}
                    </span>
                  </button>
                ),
              )}
            </div>
            <div className="flex items-center gap-2">
              <div className="relative flex-1 sm:w-64 sm:flex-none">
                <Search className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder={t('Search monitors...')}
                  className="h-9 ps-8 text-[13px]"
                />
              </div>
              {createDisabled && createDisabledTooltip ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">{createButton}</span>
                  </TooltipTrigger>
                  <TooltipContent>{createDisabledTooltip}</TooltipContent>
                </Tooltip>
              ) : (
                createButton
              )}
            </div>
          </div>

          {filtered.length === 0 ? (
            <EmptyState icon={Gauge} hasFilters variant="card" />
          ) : (
            <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
              {filtered.map((monitor) => {
                const state = ruleState(monitor)
                const named = !sentence(monitor).startsWith(monitor.name)
                return (
                  <li
                    key={monitor.$id}
                    className="group flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-accent/30 sm:gap-4"
                    onClick={() => handleOpen(monitor)}
                  >
                    <div
                      className="flex shrink-0"
                      onClick={(event) => event.stopPropagation()}
                    >
                      <Switch
                        checked={monitor.enabled}
                        onCheckedChange={() => handleToggle(monitor)}
                        disabled={createDisabled}
                        aria-label={
                          monitor.enabled
                            ? `${t('Pause')} ${monitor.name}`
                            : `${t('Resume')} ${monitor.name}`
                        }
                      />
                    </div>
                    <div
                      className={cn(
                        'min-w-0 flex-1',
                        state === 'paused' && 'opacity-60',
                      )}
                    >
                      {/* A name that only repeats the condition is shown once, as the condition. */}
                      <p className="truncate text-[13px] font-medium text-foreground">
                        {named ? monitor.name : sentence(monitor)}
                      </p>
                      <p className="truncate text-[12px] text-muted-foreground">
                        {named && (
                          <>
                            {sentence(monitor)}
                            <span className="mx-1.5">·</span>
                          </>
                        )}
                        {scope(monitor)}
                      </p>
                    </div>
                    <RuleSparkline
                      projectId={projectId}
                      rule={monitor}
                      className={cn(
                        'hidden md:block',
                        state === 'paused' && 'opacity-50',
                      )}
                    />
                    <ChannelIcons channels={monitor.channels} />
                    <MonitorState
                      projectId={projectId}
                      monitor={monitor}
                      state={state}
                    />
                    <div
                      className="flex shrink-0 justify-end"
                      onClick={(event) => event.stopPropagation()}
                    >
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            aria-label={`${t('Actions for')} ${monitor.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          <DropdownMenuItem
                            disabled={createDisabled}
                            onClick={() => handleOpen(monitor)}
                          >
                            <MenuItemContent icon={Pencil}>
                              {t('Update')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuSub>
                            <DropdownMenuSubTrigger>
                              <MenuItemIcon icon={Copy} />
                              {t('Copy')}
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard('ID', monitor.$id)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy ID')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard('Name', monitor.name)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy name')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  void copyResourceAsJson(() =>
                                    fetchMonitor(projectId, monitor.$id),
                                  )
                                }
                              >
                                <MenuItemContent icon={FileJson}>
                                  {t('Copy as JSON')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuSubContent>
                          </DropdownMenuSub>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            disabled={createDisabled}
                            onClick={() => requestDelete(monitor)}
                          >
                            <MenuItemContent icon={Trash2}>
                              {t('Delete')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
        </>
      )}

      <CreateMonitor
        open={createOpen}
        onOpenChange={setCreateOpen}
        projectId={projectId}
        initial={initial}
      />

      <MonitorDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        projectId={projectId}
        monitor={current}
        onDelete={requestDelete}
        readOnly={createDisabled}
      />
      <Dialog
        open={!!toDelete}
        onOpenChange={(open) => {
          if (!open && !deleteMutation.isPending) setToDelete(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete monitor')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}{' '}
              <strong>{toDelete?.name}</strong>?{' '}
              {t('This action cannot be undone.')}{' '}
              {t('Its alerts stay in the history.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setToDelete(null)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

/** The channels a monitor notifies, as icons named in their tooltips. */
function ChannelIcons({ channels }: { channels: MonitorChannel[] }) {
  const t = useT()
  return (
    <div
      className="hidden w-[68px] shrink-0 items-center gap-1.5 text-muted-foreground lg:flex"
      onClick={(event) => event.stopPropagation()}
    >
      {channels.map((channel) => {
        const { label, icon: Icon } = CHANNELS[channel]
        return (
          <Tooltip key={channel}>
            <TooltipTrigger asChild>
              <span
                tabIndex={0}
                aria-label={t(label)}
                className="flex h-6 w-6 items-center justify-center rounded-md border border-border bg-background"
              >
                <Icon className="h-3.5 w-3.5" />
              </span>
            </TooltipTrigger>
            <TooltipContent>{t(label)}</TooltipContent>
          </Tooltip>
        )
      })}
    </div>
  )
}

/**
 * Where the monitor stands: a small dot and label, with the last reading under
 * it. A firing monitor links to the alert it holds open.
 */
function MonitorState({
  projectId,
  monitor,
  state,
}: {
  projectId: string
  monitor: ParsedMonitor
  state: RuleState
}) {
  const t = useT()
  const style = STATE_STYLES[state]
  const ratio =
    state === 'firing' && monitor.value != null ? thresholdRatio(monitor) : null
  const label = (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 text-[12px] font-medium',
        style.text,
      )}
    >
      <span className={cn('h-2 w-2 shrink-0 rounded-full', style.dot)} />
      {t(style.label)}
    </span>
  )
  return (
    <div className="w-[72px] shrink-0 text-end sm:w-32">
      {state === 'firing' && monitor.alertId ? (
        <Link
          to="/projects/$projectId/usage/alerts"
          params={{ projectId }}
          search={{ alert: monitor.alertId }}
          onClick={(event) => event.stopPropagation()}
          className="inline-flex items-center gap-0.5 rounded-sm hover:underline hover:underline-offset-4"
        >
          {label}
          <ChevronRight className="h-3.5 w-3.5 text-red-600 rtl:rotate-180 dark:text-red-400" />
        </Link>
      ) : (
        label
      )}
      <p className="mt-0.5 hidden truncate text-[11px] tabular-nums text-muted-foreground sm:block">
        {state === 'pending' ? (
          t('Waiting for the first check')
        ) : (
          <>
            {monitor.value != null &&
              formatMetricValue(monitor.metric, monitor.value)}
            {ratio && (
              <span className="text-red-600 dark:text-red-400"> ({ratio})</span>
            )}
            {monitor.evaluatedAt && (
              <>
                <span className="mx-1">·</span>
                <DateTooltip date={monitor.evaluatedAt} />
              </>
            )}
          </>
        )}
      </p>
    </div>
  )
}
