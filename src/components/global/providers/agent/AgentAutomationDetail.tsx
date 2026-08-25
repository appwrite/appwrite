import { useEffect, useState } from 'react'
import {
  Clock,
  History,
  Loader2,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs'
import { AgentModelPicker } from '@/components/global/providers/agent/AgentModelPicker'
import { AgentProjectPicker } from '@/components/global/providers/agent/AgentProjectPicker'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { ConversationResourceSummary } from '@/components/global/providers/agent/ConversationResourceSummary'
import { CronScheduleEditor, formatCronExpression } from '@/components/pages/projects/$projectId/functions/CronScheduleEditor'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import {
  getAssistantConversationStatusLabel,
  getAssistantConversationStatusTone,
  type AssistantConversationStatusTone,
} from '@/lib/assistant/turn-view'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  useAssistantAutomationRuns,
  useDeleteAssistantAutomation,
  useUpdateAssistantAutomation,
  type AssistantAutomation,
  type AssistantConversation,
} from '@/lib/react-query/hooks'

const STATS_FETCH_LIMIT = 100

type DetailTab = 'settings' | 'runs'

type SettingsFormState = {
  name: string
  prompt: string
  schedule: string
  titlePrefix: string
  modelId: string
  contextProjectId: string
}

type AgentAutomationDetailProps = {
  automation: AssistantAutomation | null | undefined
  disabled?: boolean
  selectedRunId?: string | null
  defaultTab?: DetailTab
  onAddModel?: () => void
  onDeleted?: () => void
  onSelectRun?: (conversation: AssistantConversation) => void
  /** Org-scoped project picker (no org switching). */
  organizationId?: string | null
  className?: string
}

function formFromAutomation(automation: AssistantAutomation): SettingsFormState {
  return {
    name: automation.name || '',
    prompt: automation.prompt || '',
    schedule: automation.schedule || '',
    titlePrefix: automation.titlePrefix || '',
    modelId: automation.modelId || '',
    contextProjectId: automation.contextProjectId || '',
  }
}

function statusBadgeVariant(
  tone: AssistantConversationStatusTone,
): 'success' | 'processing' | 'warning' | 'error' | 'inactive' {
  switch (tone) {
    case 'running':
      return 'processing'
    case 'queued':
      return 'warning'
    case 'failed':
      return 'error'
    case 'stopped':
      return 'inactive'
    case 'ready':
    default:
      return 'success'
  }
}

function runStatusLabel(tone: AssistantConversationStatusTone): string {
  if (tone === 'ready') return 'Succeeded'
  return getAssistantConversationStatusLabel(tone)
}

function formatRunDuration(
  createdAt?: string | null,
  updatedAt?: string | null,
): string {
  if (!createdAt || !updatedAt) return '-'
  const ms = new Date(updatedAt).getTime() - new Date(createdAt).getTime()
  if (!Number.isFinite(ms) || ms < 0) return '-'
  const totalSeconds = Math.floor(ms / 1000)
  if (totalSeconds < 1) return '<1s'
  if (totalSeconds < 60) return `${totalSeconds}s`
  const minutes = Math.floor(totalSeconds / 60)
  if (minutes < 60) return `${minutes}m`
  const hours = Math.floor(minutes / 60)
  const remMinutes = minutes % 60
  return remMinutes > 0 ? `${hours}h ${remMinutes}m` : `${hours}h`
}

function countRunsInWindow(
  runs: AssistantConversation[],
  hours: number,
  predicate: (run: AssistantConversation) => boolean,
): number {
  const cutoff = Date.now() - hours * 60 * 60 * 1000
  return runs.filter((run) => {
    const created = run.$createdAt ? new Date(run.$createdAt).getTime() : 0
    return created >= cutoff && predicate(run)
  }).length
}

export function AgentAutomationDetail({
  automation,
  disabled = false,
  selectedRunId = null,
  defaultTab = 'settings',
  onAddModel,
  onDeleted,
  onSelectRun,
  organizationId,
  className,
}: AgentAutomationDetailProps) {
  const t = useT()
  const { account } = useAuth()
  const [tab, setTab] = useState<DetailTab>(defaultTab)
  const [form, setForm] = useState<SettingsFormState | null>(null)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

  const updateMutation = useUpdateAssistantAutomation()
  const deleteMutation = useDeleteAssistantAutomation()

  useEffect(() => {
    setTab(defaultTab)
  }, [automation?.$id, defaultTab])

  useEffect(() => {
    if (!automation) {
      setForm(null)
      return
    }
    setForm(formFromAutomation(automation))
  }, [automation])

  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [automation?.$id])

  const {
    data: requestedData,
    isLoading: requestedLoading,
    isFetching: requestedFetching,
  } = useAssistantAutomationRuns(
    automation?.$id,
    requestedPage - 1,
    pageSize,
    {
      enabled: !disabled && !!automation?.$id && tab === 'runs',
    },
  )
  const { data: displayedData, isLoading: displayedLoading } =
    useAssistantAutomationRuns(
      automation?.$id,
      displayedPage - 1,
      pageSize,
      {
        enabled: !disabled && !!automation?.$id && tab === 'runs',
      },
    )
  const { data: statsData } = useAssistantAutomationRuns(
    automation?.$id,
    0,
    STATS_FETCH_LIMIT,
    {
      enabled: !disabled && !!automation?.$id && tab === 'runs',
    },
  )

  useEffect(() => {
    if (
      !requestedFetching &&
      requestedPage !== displayedPage &&
      requestedData
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [displayedPage, requestedData, requestedFetching, requestedPage])

  const baseline = automation ? formFromAutomation(automation) : null
  const isDirty =
    !!form &&
    !!baseline &&
    (form.name !== baseline.name ||
      form.prompt !== baseline.prompt ||
      form.schedule !== baseline.schedule ||
      form.titlePrefix !== baseline.titlePrefix ||
      form.modelId !== baseline.modelId ||
      form.contextProjectId !== baseline.contextProjectId)

  const canSave =
    !!form &&
    form.name.trim().length > 0 &&
    form.prompt.trim().length > 0 &&
    form.schedule.trim().length > 0

  const runs = displayedData?.runs ?? []
  const total = displayedData?.total ?? 0
  const isInitialLoading =
    tab === 'runs' && (displayedLoading || requestedLoading) && runs.length === 0
  const statsRuns = statsData?.runs ?? []
  const isSuccessful = (run: AssistantConversation) =>
    getAssistantConversationStatusTone(run) === 'ready'
  const isFailed = (run: AssistantConversation) =>
    getAssistantConversationStatusTone(run) === 'failed'
  const stats = {
    success24h: countRunsInWindow(statsRuns, 24, isSuccessful),
    failed24h: countRunsInWindow(statsRuns, 24, isFailed),
    success7d: countRunsInWindow(statsRuns, 24 * 7, isSuccessful),
    failed7d: countRunsInWindow(statsRuns, 24 * 7, isFailed),
  }

  const scheduleLabel = automation?.schedule
    ? t(formatCronExpression(automation.schedule))
    : t('Schedule')
  const ownerLabel =
    account && automation && account.$id === automation.userId
      ? account.name?.trim() || account.email
      : null

  const closeDeleteDialog = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
  }

  const handleToggleEnabled = async (enabled: boolean) => {
    if (!automation || disabled) return
    try {
      await updateMutation.mutateAsync({
        automationId: automation.$id,
        enabled,
      })
      toast.success(enabled ? t('Automation enabled') : t('Automation paused'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to update automation')))
    }
  }

  const handleSave = async () => {
    if (!automation || !form || !canSave || disabled) return
    try {
      await updateMutation.mutateAsync({
        automationId: automation.$id,
        name: form.name.trim(),
        prompt: form.prompt.trim(),
        schedule: form.schedule.trim(),
        titlePrefix: form.titlePrefix.trim() || undefined,
        modelId: form.modelId.trim() || undefined,
        contextProjectId: form.contextProjectId.trim() || undefined,
      })
      toast.success(t('Automation updated'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to update automation')))
    }
  }

  const handleDelete = async () => {
    if (!automation || disabled) return
    try {
      await deleteMutation.mutateAsync(automation.$id)
      toast.success(t('Automation deleted'))
      closeDeleteDialog()
      onDeleted?.()
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete automation')))
    }
  }

  if (!automation || !form) {
    return (
      <div
        className={cn(
          'flex h-full min-h-0 flex-1 flex-col items-center justify-center bg-background px-6',
          className,
        )}
      >
        <p className="text-center text-[13px] text-muted-foreground">
          {t('Select an automation')}
        </p>
      </div>
    )
  }

  const isEnabled = automation.enabled !== false
  const isSaving = updateMutation.isPending
  const isDeleting = deleteMutation.isPending

  return (
    <div
      className={cn(
        'flex h-full min-h-0 min-w-0 flex-1 flex-col bg-background',
        className,
      )}
    >
      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
        <div className="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6">
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex items-center gap-2">
              <Switch
                checked={isEnabled}
                onCheckedChange={(checked) => void handleToggleEnabled(checked)}
                disabled={disabled || isSaving || isDeleting}
                aria-label={isEnabled ? t('Active') : t('Paused')}
              />
              <span
                className={cn(
                  'text-[12px] font-medium',
                  isEnabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground',
                )}
              >
                {isEnabled ? t('Active') : t('Paused')}
              </span>
            </div>
            <AgentProjectPicker
              organizationId={organizationId}
              value={form.contextProjectId || ''}
              onChange={(projectId) =>
                setForm((current) =>
                  current
                    ? { ...current, contextProjectId: projectId || '' }
                    : current,
                )
              }
              size="compact"
              disabled={disabled || isSaving || isDeleting}
            />
            {ownerLabel ? (
              <span className="text-[12px] text-muted-foreground">
                {t('By')} {ownerLabel}
              </span>
            ) : null}
          </div>

          <Tabs
            value={tab}
            onValueChange={(value) => {
              if (value === 'settings' || value === 'runs') setTab(value)
            }}
            className="mt-5 gap-0"
          >
            <TabsList className="h-9">
              <TabsTrigger value="settings" className="px-3 text-[12px]">
                {t('Settings')}
              </TabsTrigger>
              <TabsTrigger value="runs" className="px-3 text-[12px]">
                {t('Run history')}
              </TabsTrigger>
            </TabsList>

            <TabsContent value="settings" className="mt-6 space-y-6">
              <section className="space-y-2">
                <h2 className="text-[12px] font-medium text-muted-foreground">
                  {t('Triggers')}
                </h2>
                <div className="overflow-hidden rounded-xl border border-border bg-card/50">
                  <div className="space-y-3 px-4 py-3">
                    <p className="text-[13px] font-medium text-foreground">
                      {t('Schedule')}
                    </p>
                    <CronScheduleEditor
                      value={form.schedule}
                      onChange={(schedule) =>
                        setForm((current) =>
                          current ? { ...current, schedule } : current,
                        )
                      }
                      disabled={disabled || isSaving || isDeleting}
                      allowDisabled={false}
                    />
                  </div>
                </div>
              </section>

              <section className="space-y-2">
                <h2 className="text-[12px] font-medium text-muted-foreground">
                  {t('Agent instructions')}
                </h2>
                <div className="overflow-hidden rounded-xl border border-border bg-card/50">
                  <div className="space-y-4 px-4 py-4">
                    <div className="space-y-2">
                      <Label htmlFor="automation-detail-name">{t('Name')}</Label>
                      <Input
                        id="automation-detail-name"
                        value={form.name}
                        onChange={(event) =>
                          setForm((current) =>
                            current
                              ? { ...current, name: event.target.value }
                              : current,
                          )
                        }
                        className="h-9 text-[13px]"
                        disabled={disabled || isSaving || isDeleting}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="automation-detail-prompt">
                        {t('Prompt')}
                      </Label>
                      <Textarea
                        id="automation-detail-prompt"
                        value={form.prompt}
                        onChange={(event) =>
                          setForm((current) =>
                            current
                              ? { ...current, prompt: event.target.value }
                              : current,
                          )
                        }
                        placeholder={t(
                          'Summarize project activity and suggest next steps.',
                        )}
                        className="min-h-28 text-[13px]"
                        disabled={disabled || isSaving || isDeleting}
                      />
                    </div>
                    <div className="space-y-2 border-t border-border pt-4">
                      <Label htmlFor="automation-detail-title-prefix">
                        {t('Title prefix (optional)')}
                      </Label>
                      <Input
                        id="automation-detail-title-prefix"
                        value={form.titlePrefix}
                        onChange={(event) =>
                          setForm((current) =>
                            current
                              ? {
                                  ...current,
                                  titlePrefix: event.target.value,
                                }
                              : current,
                          )
                        }
                        placeholder={t('Weekly review')}
                        className="h-9 text-[13px]"
                        disabled={disabled || isSaving || isDeleting}
                      />
                    </div>
                  </div>
                  <div className="space-y-2 border-t border-border px-4 py-4">
                    <Label>{t('Model')}</Label>
                    <AgentModelPicker
                      value={form.modelId}
                      onChange={(modelId) =>
                        setForm((current) =>
                          current ? { ...current, modelId } : current,
                        )
                      }
                      size="form"
                      disabled={disabled || isSaving || isDeleting}
                      onManageModels={onAddModel}
                    />
                  </div>
                </div>
              </section>

              <div className="flex items-center gap-2">
                <Button
                  type="button"
                  disabled={
                    disabled ||
                    !canSave ||
                    !isDirty ||
                    isSaving ||
                    isDeleting
                  }
                  onClick={() => void handleSave()}
                >
                  {t('Update')}
                </Button>
              </div>

              <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
                <div className="px-4 py-3">
                  <h3 className="text-[13px] font-semibold text-foreground">
                    {t('Delete automation')}
                  </h3>
                  <p className="mt-1 text-[12px] text-muted-foreground">
                    {t('This permanently deletes the automation.')}{' '}
                    {t('This action cannot be undone.')}
                  </p>
                </div>
                <div className="border-t border-destructive/20 px-4 py-3 bg-destructive/5">
                  <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={disabled || isDeleting || isSaving}
                    onClick={() => setDeleteOpen(true)}
                  >
                    {t('Delete')}
                  </Button>
                </div>
              </div>
            </TabsContent>

            <TabsContent value="runs" className="mt-6 space-y-4">
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {[
                  {
                    label: t('Successful · 24h'),
                    value: stats.success24h,
                  },
                  {
                    label: t('Failed · 24h'),
                    value: stats.failed24h,
                  },
                  {
                    label: t('Successful · 7d'),
                    value: stats.success7d,
                  },
                  {
                    label: t('Failed · 7d'),
                    value: stats.failed7d,
                  },
                ].map((item) => (
                  <div
                    key={item.label}
                    className="rounded-xl border border-border bg-card/50 px-3 py-3"
                  >
                    <p className="text-[11px] text-muted-foreground">
                      {item.label}
                    </p>
                    <p className="mt-1 text-[20px] font-semibold tabular-nums text-foreground">
                      {item.value}
                    </p>
                  </div>
                ))}
              </div>

              {isInitialLoading ? (
                <div className="flex h-64 items-center justify-center">
                  <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                </div>
              ) : runs.length === 0 ? (
                <EmptyState
                  icon={History}
                  iconSize="md"
                  title="No runs yet."
                  description="Runs appear here after this automation executes."
                  isEmpty
                  variant="card"
                  className="py-10"
                />
              ) : (
                <>
                  <div className="rounded-lg border border-border bg-card">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent border-b border-border">
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Trigger')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Triggered')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Resources')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Status')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                            {t('Duration')}
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {runs.map((conversation) => {
                          const isSelected = selectedRunId === conversation.$id
                          const statusTone =
                            getAssistantConversationStatusTone(conversation)
                          const statusLabel = t(runStatusLabel(statusTone))

                          return (
                            <TableRow
                              key={conversation.$id}
                              className={cn(
                                disabled ? 'opacity-60' : 'cursor-pointer',
                                isSelected && 'bg-accent/60',
                              )}
                              onClick={() => {
                                if (disabled) return
                                onSelectRun?.(conversation)
                              }}
                            >
                              <TableCell className="px-4 py-3">
                                <span className="inline-flex min-w-0 items-center gap-1.5 text-[13px] text-foreground">
                                  <Clock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                  <span className="truncate">
                                    {t('Scheduled')}
                                    {' · '}
                                    {scheduleLabel}
                                  </span>
                                </span>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                {conversation.$createdAt ? (
                                  <DateTooltip
                                    date={conversation.$createdAt}
                                    disableTooltip
                                  />
                                ) : (
                                  <span className="text-[13px] text-muted-foreground">
                                    -
                                  </span>
                                )}
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <ConversationResourceSummary
                                  conversationId={conversation.$id}
                                  className="text-[12px]"
                                />
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Badge
                                  variant={statusBadgeVariant(statusTone)}
                                  className="text-[10px] shrink-0"
                                >
                                  {statusLabel}
                                </Badge>
                              </TableCell>
                              <TableCell className="px-4 py-3 text-end text-[13px] text-muted-foreground tabular-nums">
                                {formatRunDuration(
                                  conversation.$createdAt,
                                  conversation.$updatedAt,
                                )}
                              </TableCell>
                            </TableRow>
                          )
                        })}
                      </TableBody>
                    </Table>
                  </div>

                  {total > 0 ? (
                    <Pagination
                      currentPage={displayedPage}
                      totalItems={total}
                      pageSize={pageSize}
                      pageSizeOptions={[10, 25, 50, 100]}
                      onPageChange={setRequestedPage}
                      onPageSizeChange={(size) => {
                        setPageSize(size)
                        setRequestedPage(1)
                        setDisplayedPage(1)
                      }}
                      itemLabel="runs"
                      scrollToTopOnPageChange={false}
                    />
                  ) : null}
                </>
              )}
            </TabsContent>
          </Tabs>
        </div>
      </div>

      <Dialog
        open={deleteOpen}
        onOpenChange={(open) => {
          if (!open) {
            closeDeleteDialog()
            return
          }
          setDeleteOpen(true)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete automation')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('This permanently deletes the automation.')}{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              disabled={isDeleting}
              onClick={closeDeleteDialog}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={isDeleting}
              onClick={() => void handleDelete()}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
