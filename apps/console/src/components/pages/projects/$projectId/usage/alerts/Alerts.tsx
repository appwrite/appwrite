import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Bell,
  Copy,
  FileJson,
  Loader2,
  Mail,
  Pencil,
  Plus,
  Power,
  PowerOff,
  Search,
  Trash2,
  Webhook,
} from 'lucide-react'
import { toast } from 'sonner'
import {
  bucketsQueryOptions,
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  fetchAlertRule,
  useAlertRules,
  useDeleteAlertRule,
  useUpdateAlertRule,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
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
import type { AlertChannel, AlertRule } from '@/lib/appwrite/alerts'
import {
  COMPARATORS,
  METRICS,
  RESOURCE_TYPES,
  WINDOWS,
  formatMetricValue,
} from '@/lib/alerts/metrics'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { AlertsEmptyState } from './AlertsEmptyState'
import { AlertDrawer } from './AlertDrawer'
import { CreateAlert } from './CreateAlert'
import type { RuleForm } from './RuleFields'
import { RuleSparkline } from './RuleChart'
import { StatusPill, type RuleState } from './StatusPill'

const STATE_ORDER: Record<RuleState, number> = { firing: 0, ok: 1, paused: 2 }

const CHANNEL_ICONS: Record<AlertChannel, typeof Mail> = {
  email: Mail,
  console: Bell,
  webhook: Webhook,
}

function ruleState(rule: AlertRule): RuleState {
  if (!rule.enabled) return 'paused'
  return rule.status === 'firing' ? 'firing' : 'ok'
}

type Filter = 'all' | RuleState

/** Resolves database, bucket and dedicated database names for the rules that watch one. */
function useResourceNames(projectId: string, rules: AlertRule[]) {
  const types = new Set(rules.map((rule) => rule.resourceType))
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

interface AlertsProps {
  projectId: string
  createDisabled?: boolean
  createDisabledTooltip?: string
  /** Opens the create form filled with these fields, e.g. from a usage page. */
  prefill?: Partial<RuleForm>
  onPrefillConsumed?: () => void
  /** Opens this rule's drawer once the rules have loaded, e.g. from an alert email. */
  openRuleId?: string
  onOpenRuleConsumed?: () => void
}

export function Alerts({
  projectId,
  createDisabled = false,
  createDisabledTooltip,
  prefill,
  onPrefillConsumed,
  openRuleId,
  onOpenRuleConsumed,
}: AlertsProps) {
  const t = useT()
  const { rules, isLoading } = useAlertRules(projectId)
  const updateMutation = useUpdateAlertRule(projectId)
  const deleteMutation = useDeleteAlertRule(projectId)
  const resourceNames = useResourceNames(projectId, rules)

  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [createOpen, setCreateOpen] = useState(false)
  const [initial, setInitial] = useState<Partial<RuleForm> | undefined>()
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selected, setSelected] = useState<AlertRule | null>(null)
  const [toDelete, setToDelete] = useState<AlertRule | null>(null)

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
    if (!openRuleId || isLoading) return
    const rule = rules.find((candidate) => candidate.$id === openRuleId)
    if (rule) {
      setSelected(rule)
      setDrawerOpen(true)
    }
    onOpenRuleConsumed?.()
  }, [openRuleId, isLoading, rules, onOpenRuleConsumed])

  const counts = useMemo(() => {
    const result: Record<Filter, number> = {
      all: rules.length,
      firing: 0,
      ok: 0,
      paused: 0,
    }
    for (const rule of rules) result[ruleState(rule)]++
    return result
  }, [rules])

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase()
    return rules
      .filter((rule) => filter === 'all' || ruleState(rule) === filter)
      .filter(
        (rule) =>
          !query ||
          rule.name.toLowerCase().includes(query) ||
          rule.metric.toLowerCase().includes(query) ||
          t(METRICS[rule.metric]?.label ?? '')
            .toLowerCase()
            .includes(query) ||
          rule.$id.toLowerCase().includes(query),
      )
      .sort(
        (a, b) =>
          STATE_ORDER[ruleState(a)] - STATE_ORDER[ruleState(b)] ||
          a.name.localeCompare(b.name),
      )
  }, [rules, search, filter, t])

  const handleOpen = (rule: AlertRule) => {
    openDialogAfterOverlayCloses(() => {
      setSelected(rule)
      setDrawerOpen(true)
    })
  }

  const handleToggle = (rule: AlertRule) => {
    updateMutation.mutate(
      { ruleId: rule.$id, changes: { enabled: !rule.enabled } },
      {
        onSuccess: () =>
          toast.success(
            rule.enabled
              ? t('Alert rule has been paused')
              : t('Alert rule has been resumed'),
          ),
        onError: (error: Error) =>
          toast.error(getErrorMessage(error, t('Failed to update alert rule'))),
      },
    )
  }

  const requestDelete = (rule: AlertRule) =>
    openDialogAfterOverlayCloses(() => setToDelete(rule))

  const handleDelete = () => {
    if (!toDelete) return
    const ruleId = toDelete.$id
    deleteMutation.mutate(ruleId, {
      onSuccess: () => {
        closeDialogBeforeOverlayUnmount(() => {
          setToDelete(null)
          setSelected(null)
        })
        toast.success(t('Alert rule has been deleted'))
      },
      onError: (error: Error) =>
        toast.error(getErrorMessage(error, t('Failed to delete alert rule'))),
    })
  }

  /** `Outbound bandwidth > 10 GB within 1 hour` */
  const sentence = (rule: AlertRule) => {
    const definition = METRICS[rule.metric]
    const symbol =
      COMPARATORS.find((entry) => entry.value === rule.comparator)?.symbol ??
      rule.comparator
    const window = WINDOWS.find((entry) => entry.value === rule.window)
    const base = `${t(definition?.label ?? rule.metric)} ${symbol} ${formatMetricValue(rule.metric, rule.threshold)}`
    if (!definition?.counter) return base
    return `${base} ${t('within')} ${window ? t(window.label) : `${rule.window}s`}`
  }

  const scope = (rule: AlertRule) => {
    if (rule.resourceType === 'project') return t('Project')
    const type = RESOURCE_TYPES.find(
      (entry) => entry.value === rule.resourceType,
    )
    return `${t(type?.label ?? rule.resourceType)} · ${resourceNames.get(rule.resourceId) ?? rule.resourceId}`
  }

  const createButton = (
    <Button
      size="sm"
      className="h-9 text-[13px]"
      onClick={() => openCreate()}
      disabled={createDisabled}
    >
      <Plus className="me-1.5 h-4 w-4" />
      {t('Create alert')}
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
      {rules.length === 0 ? (
        <AlertsEmptyState
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
              aria-label={t('Filter alert rules')}
              className="flex flex-wrap gap-1.5"
            >
              {(['all', 'firing', 'ok', 'paused'] as Filter[]).map((key) => (
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
                        : t('Paused')}
                  <span className="tabular-nums text-muted-foreground">
                    {counts[key]}
                  </span>
                </button>
              ))}
            </div>
            <div className="flex items-center gap-2">
              <div className="relative flex-1 sm:w-64 sm:flex-none">
                <Search className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder={t('Search alert rules...')}
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
            <EmptyState icon={Bell} hasFilters variant="card" />
          ) : (
            <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
              {filtered.map((rule) => {
                const state = ruleState(rule)
                return (
                  <li
                    key={rule.$id}
                    className={cn(
                      'group relative flex cursor-pointer items-center gap-4 px-4 py-3 transition-colors hover:bg-accent/30',
                      state === 'firing' &&
                        'before:absolute before:inset-y-0 before:start-0 before:w-0.5 before:bg-red-500',
                    )}
                    onClick={() => handleOpen(rule)}
                  >
                    <StatusPill status={state} className="w-[72px] shrink-0" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[13px] font-medium text-foreground">
                        {rule.name}
                      </p>
                      <p className="truncate text-[12px] text-muted-foreground">
                        {sentence(rule)}
                        <span className="mx-1.5">·</span>
                        {scope(rule)}
                      </p>
                    </div>
                    <RuleSparkline
                      projectId={projectId}
                      rule={rule}
                      className={cn(
                        'hidden md:block',
                        state === 'paused' && 'opacity-50',
                      )}
                    />
                    <div className="hidden w-28 shrink-0 text-end sm:block">
                      <p
                        className={cn(
                          'text-[13px] font-medium tabular-nums',
                          state === 'firing'
                            ? 'text-red-500'
                            : 'text-foreground',
                        )}
                      >
                        {rule.value === null
                          ? '-'
                          : formatMetricValue(rule.metric, rule.value)}
                      </p>
                      <p className="text-[11px] text-muted-foreground">
                        {rule.evaluatedAt ? (
                          <DateTooltip date={rule.evaluatedAt} />
                        ) : (
                          t('Not checked yet')
                        )}
                      </p>
                    </div>
                    <div className="hidden w-16 shrink-0 items-center justify-end gap-1.5 text-muted-foreground lg:flex">
                      {rule.channels.map((channel) => {
                        const Icon = CHANNEL_ICONS[channel]
                        return (
                          <Icon
                            key={channel}
                            className="h-3.5 w-3.5"
                            aria-label={channel}
                          />
                        )
                      })}
                    </div>
                    <div
                      className="flex shrink-0 justify-end"
                      onClick={(event) => event.stopPropagation()}
                    >
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            aria-label={`${t('Actions for')} ${rule.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          <DropdownMenuItem
                            disabled={createDisabled}
                            onClick={() => handleOpen(rule)}
                          >
                            <MenuItemContent icon={Pencil}>
                              {t('Update')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            disabled={
                              createDisabled || updateMutation.isPending
                            }
                            onClick={() => handleToggle(rule)}
                          >
                            <MenuItemContent
                              icon={rule.enabled ? PowerOff : Power}
                            >
                              {rule.enabled ? t('Pause') : t('Resume')}
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
                                onClick={() => copyToClipboard('ID', rule.$id)}
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy ID')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard('Name', rule.name)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy name')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  void copyResourceAsJson(() =>
                                    fetchAlertRule(projectId, rule.$id),
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
                            onClick={() => requestDelete(rule)}
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

      <CreateAlert
        open={createOpen}
        onOpenChange={setCreateOpen}
        projectId={projectId}
        initial={initial}
      />

      <AlertDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        projectId={projectId}
        rule={selected}
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
            <DialogTitle>{t('Delete alert rule')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}{' '}
              <strong>{toDelete?.name}</strong>?{' '}
              {t('This action cannot be undone.')}
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
