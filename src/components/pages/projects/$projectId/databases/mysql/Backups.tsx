import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ID } from '@appwrite.io/console' // pragma: allowlist secret
import { sdk } from '@/lib/appwrite/sdk' // pragma: allowlist secret
import { toast } from 'sonner'
import { formatDistanceToNow } from 'date-fns'
import { formatDateTime } from '@/lib/date-utils'
import {
  MYSQL_BACKUPS_PAGE_SIZE,
  useMysqlBackupPolicies,
  useMysqlBackups,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  Plus,
  Archive,
  Trash2,
  RotateCcw,
  Copy,
  Clock,
  CheckCircle2,
  AlertCircle,
  CircleDashed,
} from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { toByteCount } from '@/lib/utils/byte-display-unit'
import { useOrganizationPlan, useProject } from '@/lib/react-query/hooks'
import {
  getBackupPoliciesPlanLimit,
  isBackupPoliciesAtPlanLimit,
  supportsAdvancedBackupPolicies,
} from '@/lib/databases/backup-policy-plan-limits'
import { PlanLimitWarning } from '../../shared/PlanLimitWarning'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

type BackupStatusVariant = 'completed' | 'failed' | 'pending' | 'processing'

function getMysqlBackupStatus(status: string) {
  const statusMap: Record<
    string,
    { label: string; icon: typeof Clock; badgeVariant: BackupStatusVariant }
  > = {
    pending: { label: 'Pending', icon: Clock, badgeVariant: 'pending' },
    running: {
      label: 'Processing',
      icon: CircleDashed,
      badgeVariant: 'processing',
    },
    completed: {
      label: 'Complete',
      icon: CheckCircle2,
      badgeVariant: 'completed',
    },
    verified: {
      label: 'Verified',
      icon: CheckCircle2,
      badgeVariant: 'completed',
    },
    failed: { label: 'Failed', icon: AlertCircle, badgeVariant: 'failed' },
  }
  const statusInfo = statusMap[status] || {
    label: 'Waiting',
    icon: Clock,
    badgeVariant: 'pending' as BackupStatusVariant,
  }
  return {
    label: statusInfo.label,
    badgeVariant: statusInfo.badgeVariant,
    icon: statusInfo.icon,
  }
}

function formatBackupSize(bytes: number | bigint | undefined) {
  const n = toByteCount(bytes)
  if (n <= 0) return '-'
  const mb = n / (1000 * 1000)
  if (mb < 1) {
    return `${(n / 1000).toFixed(2)} KB`
  }
  return `${mb.toFixed(2)} MB`
}

function getNextBackupDate(schedule: string, t: (text: string) => string) {
  const now = new Date()
  const nextDate = new Date(now)

  if (schedule === '0 * * * *') {
    nextDate.setHours(nextDate.getHours() + 1, 0, 0, 0)
  } else if (schedule.includes('* * *')) {
    nextDate.setDate(nextDate.getDate() + 1)
    nextDate.setHours(0, 0, 0, 0)
  } else {
    return t('Calculating...')
  }

  return formatDateTime(nextDate)
}

function isManualMysqlBackup(backup: Models.DedicatedDatabaseBackup) {
  return backup.trigger === 'manual' || !backup.policyId
}

type ViewProps = {
  projectId: string
  databaseId: string
}

export function View({ projectId, databaseId }: ViewProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const backupPoliciesLimit = getBackupPoliciesPlanLimit(organizationPlan)
  const planName = resolveOrganizationPlanDisplayLabel({
    planName: organizationPlan?.name ?? null,
    planId: organizationPlan?.$id,
  })
  const [backupsPage, setBackupsPage] = useState(1)
  const [backupsPageSize, setBackupsPageSize] = useState(
    MYSQL_BACKUPS_PAGE_SIZE,
  )
  const [createPolicyDialogOpen, setCreatePolicyDialogOpen] = useState(false)
  const [createManualBackupDialogOpen, setCreateManualBackupDialogOpen] =
    useState(false)
  const [restoreDialogOpen, setRestoreDialogOpen] = useState(false)
  const [deletePolicyDialogOpen, setDeletePolicyDialogOpen] = useState(false)
  const [deleteBackupDialogOpen, setDeleteBackupDialogOpen] = useState(false)
  const [bulkDeleteDialogOpen, setBulkDeleteDialogOpen] = useState(false)
  const [selectedPolicy, setSelectedPolicy] =
    useState<Models.BackupPolicy | null>(null)
  const [selectedBackup, setSelectedBackup] =
    useState<Models.DedicatedDatabaseBackup | null>(null)
  const [selectedBackups, setSelectedBackups] = useState<Set<string>>(new Set())

  const { data: policiesData, isLoading: policiesLoading } =
    useMysqlBackupPolicies(projectId, databaseId)
  const { data: backupsData, isLoading: backupsLoading } = useMysqlBackups(
    projectId,
    databaseId,
    backupsPage - 1,
    backupsPageSize,
  )

  const policies: Models.BackupPolicy[] = policiesData?.policies || []
  const backups: Models.DedicatedDatabaseBackup[] = backupsData?.backups || []
  const backupsTotal =
    typeof backupsData?.total === 'bigint'
      ? Number(backupsData.total)
      : backupsData?.total || 0
  const isAtBackupPoliciesLimit = isBackupPoliciesAtPlanLimit(
    policies.length,
    backupPoliciesLimit,
  )
  const createPolicyDisabledTooltip = isAtBackupPoliciesLimit
    ? t("You've reached the limit for this resource on your plan")
    : undefined

  const isPoliciesActuallyLoading =
    policiesLoading && policies.length === 0 && !policiesData
  const isBackupsActuallyLoading =
    backupsLoading && backups.length === 0 && !backupsData

  const invalidatePolicies = () => {
    queryClient.invalidateQueries({
      queryKey: [
        'dedicated-backup-policies',
        'project',
        projectId,
        databaseId,
      ],
    })
  }

  const invalidateBackups = () => {
    queryClient.invalidateQueries({
      queryKey: ['mysql-backups', 'project', projectId, databaseId],
    })
  }

  const createPolicyMutation = useMutation({
    mutationFn: async (
      items: Array<{
        policyId: string
        retention: number
        schedule: string
        name: string
        enabled?: boolean
      }>,
    ) => {
      const projectSdk = sdk.forProject(projectId)
      return Promise.all(
        items.map((policy) =>
          projectSdk.mysql.createBackupPolicy({
            databaseId,
            policyId: policy.policyId,
            name: policy.name,
            schedule: policy.schedule,
            retention: policy.retention,
            enabled: policy.enabled ?? true,
          }),
        ),
      )
    },
    onSuccess: (_data, variables) => {
      if (variables.length === 1) {
        toast.success(
          <div>
            <b>{variables[0].name || t('Policy')}</b>{' '}
            {t('policy has been created')}
          </div>,
        )
      } else {
        toast.success(t('Backup policies have been created'))
      }
      invalidatePolicies()
      setCreatePolicyDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create backup policy'))
    },
  })

  const deletePolicyMutation = useMutation({
    mutationFn: async (policyId: string) => {
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.mysql.deleteBackupPolicy({
        databaseId,
        policyId,
      })
    },
    onSuccess: () => {
      toast.success(t('Backup policy has been deleted'))
      invalidatePolicies()
      setDeletePolicyDialogOpen(false)
      setSelectedPolicy(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete backup policy'))
    },
  })

  const createBackupMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.mysql.createBackup({ databaseId })
    },
    onSuccess: () => {
      toast.success(t('Database backup has started'))
      invalidateBackups()
      setCreateManualBackupDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create backup'))
    },
  })

  const deleteBackupMutation = useMutation({
    mutationFn: async (backupId: string) => {
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.mysql.deleteBackup({ databaseId, backupId })
    },
    onSuccess: () => {
      toast.success(t('1 backup deleted'))
      invalidateBackups()
      setDeleteBackupDialogOpen(false)
      setSelectedBackup(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete backup'))
    },
  })

  const bulkDeleteBackupsMutation = useMutation({
    mutationFn: async (backupIds: string[]) => {
      const projectSdk = sdk.forProject(projectId)
      await Promise.all(
        backupIds.map((backupId) =>
          projectSdk.mysql.deleteBackup({ databaseId, backupId }),
        ),
      )
    },
    onSuccess: () => {
      toast.success(
        selectedBackups.size === 1
          ? t('Backup deleted successfully')
          : t('Backups deleted successfully'),
      )
      invalidateBackups()
      setSelectedBackups(new Set())
      setBulkDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete backups'))
    },
  })

  const createRestorationMutation = useMutation({
    mutationFn: async (backupId: string) => {
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.mysql.createRestoration({
        databaseId,
        type: 'backup',
        backupId,
      })
    },
    onSuccess: () => {
      toast.success(t('Database restore initiated'))
      invalidateBackups()
      setRestoreDialogOpen(false)
      setSelectedBackup(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to restore backup'))
    },
  })

  const getPreviousBackup = (policyId: string) => {
    return backups.find(
      (backup) =>
        backup.policyId === policyId &&
        (backup.status === 'completed' || backup.status === 'verified'),
    )
  }

  useEffect(() => {
    setSelectedBackups(new Set())
    setBulkDeleteDialogOpen(false)
  }, [backupsPage, backupsPageSize])

  const handleBulkDelete = () => {
    if (selectedBackups.size === 0) return
    setBulkDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedBackups.size === 0) return
    bulkDeleteBackupsMutation.mutate(Array.from(selectedBackups))
  }

  const handlePageChange = (page: number) => {
    setBackupsPage(page)
    setSelectedBackups(new Set())
  }

  const handlePageSizeChange = (size: number) => {
    setBackupsPageSize(size)
    setBackupsPage(1)
    setSelectedBackups(new Set())
  }

  const showPlanLimitWarning =
    backupPoliciesLimit > 0 &&
    policies.length >= backupPoliciesLimit * 0.5

  return (
    <div className="w-full">
      {organizationPlan !== undefined ? (
        <PlanLimitWarning
          currentCount={policies.length}
          limit={backupPoliciesLimit}
          planName={planName}
          resourceName="backup policies"
          orgId={project?.teamId}
          fullWidth
        />
      ) : null}
      <div
        className={cn(
          'mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6',
          !showPlanLimitWarning && 'mt-4 sm:mt-6',
          showPlanLimitWarning && 'pt-4 sm:pt-6',
        )}
      >
      <div className="grid gap-6 lg:grid-cols-3 lg:items-stretch">
        <div className="lg:col-span-1 flex flex-col">
          <div className="mb-4 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Policies')}
              </h3>
              <Badge
                variant="secondary"
                className="text-[12px] font-normal"
              >
                {backupPoliciesLimit > 0
                  ? `${policies.length}/${backupPoliciesLimit}`
                  : t('Unlimited')}
              </Badge>
            </div>
            {isAtBackupPoliciesLimit ? (
              <Tooltip>
                <TooltipTrigger asChild>
                  <span>
                    <Button
                      onClick={() => setCreatePolicyDialogOpen(true)}
                      variant="brandCta"
                      disabled
                      size="sm"
                      className="h-8 gap-1.5 text-[12px] font-medium"
                    >
                      <Plus className="h-3.5 w-3.5" />
                      {t('Create policy')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent>
                  <p className="text-xs">{createPolicyDisabledTooltip}</p>
                </TooltipContent>
              </Tooltip>
            ) : (
              <Button
                onClick={() => setCreatePolicyDialogOpen(true)}
                variant="brandCta"
                size="sm"
                className="h-8 gap-1.5 text-[12px] font-medium"
              >
                <Plus className="h-3.5 w-3.5" />
                {t('Create policy')}
              </Button>
            )}
          </div>
          <div className="flex-1">
            {isPoliciesActuallyLoading ? (
              <div className="flex h-full min-h-[280px] items-center justify-center rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">
                  {t('Loading policies...')}
                </div>
              </div>
            ) : policies.length === 0 ? (
              <EmptyState
                icon={Archive}
                title={t('Ensure your data stays safe')}
                description={t(
                  'Create a backup policy to automate regular and secure data protection.',
                )}
                isEmpty={true}
                variant="card"
                iconSize="md"
                className="flex h-full min-h-[280px] flex-col items-center justify-center"
              />
            ) : (
              <div className="space-y-3">
                {policies.map((policy) => {
                  const previousBackup = getPreviousBackup(policy.$id)
                  const scheduleText =
                    policy.schedule === '0 * * * *'
                      ? t('Runs hourly')
                      : policy.schedule.includes('* * *')
                        ? t('Runs daily')
                        : t('Runs on schedule')
                  const retentionText =
                    policy.retention === 36500
                      ? t('Retained forever')
                      : policy.retention === 7
                        ? t('Retained for 1 week')
                        : policy.retention === 1
                          ? t('Retained for 1 day')
                          : `${t('Retained for')} ${policy.retention} ${t('days')}`

                  return (
                    <div
                      key={policy.$id}
                      className="rounded-lg border border-border bg-background p-4"
                    >
                      <div className="flex items-start justify-between mb-3">
                        <div className="flex-1 min-w-0">
                          <h4 className="text-[14px] font-medium text-foreground mb-1">
                            {policy.name || t('Unnamed Policy')}
                          </h4>
                          <p className="text-[13px] text-muted-foreground">
                            {scheduleText} • {retentionText}
                          </p>
                        </div>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem
                              onClick={() => {
                                setSelectedPolicy(policy)
                                setDeletePolicyDialogOpen(true)
                              }}
                            >
                              <MenuItemContent icon={Trash2}>
                                {t('Delete')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                      <div className="border-t border-border my-3" />
                      <div className="grid grid-cols-2 gap-4 text-[13px]">
                        <div>
                          <div className="text-muted-foreground mb-1.5">
                            {t('Previous')}
                          </div>
                          <div className="flex items-center gap-1.5">
                            {previousBackup ? (
                              <>
                                <div className="h-2 w-2 rounded-full bg-green-500 shrink-0" />
                                <DateTooltip date={previousBackup.$createdAt} />
                              </>
                            ) : (
                              <>
                                <div className="h-2 w-2 rounded-full bg-muted-foreground shrink-0" />
                                <span className="text-foreground">
                                  {t('No backups yet')}
                                </span>
                              </>
                            )}
                          </div>
                        </div>
                        <div>
                          <div className="text-muted-foreground mb-1.5">
                            {t('Next')}
                          </div>
                          <div className="text-foreground">
                            {getNextBackupDate(policy.schedule, t)}
                          </div>
                        </div>
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        </div>

        <div className="lg:col-span-2 flex flex-col">
          <div className="mb-4 flex items-center justify-between">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Backups')}
            </h3>
            <Button
              variant="brandCta"
              onClick={() => setCreateManualBackupDialogOpen(true)}
              size="sm"
              className="h-8 gap-1.5 text-[12px] font-medium"
            >
              <Plus className="h-3.5 w-3.5" />
              {t('Manual backup')}
            </Button>
          </div>
          <div className="flex-1">
            {isBackupsActuallyLoading ? (
              <div className="flex h-full min-h-[280px] items-center justify-center rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">
                  {t('Loading backups...')}
                </div>
              </div>
            ) : backups.length === 0 ? (
              <EmptyState
                icon={Archive}
                title={t('No backups yet')}
                description={t(
                  'Create a manual backup or set up a policy to get started.',
                )}
                isEmpty={true}
                variant="card"
                iconSize="md"
                className="flex h-full min-h-[280px] flex-col items-center justify-center"
              />
            ) : (
              <>
                <div className="rounded-lg border border-border bg-card">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="w-[50px] px-4 py-3">
                          <Checkbox
                            checked={
                              backups.length > 0 &&
                              backups.every((backup) =>
                                selectedBackups.has(backup.$id),
                              )
                            }
                            onCheckedChange={(checked) => {
                              if (checked) {
                                setSelectedBackups(
                                  new Set(backups.map((backup) => backup.$id)),
                                )
                              } else {
                                setSelectedBackups(new Set())
                              }
                            }}
                          />
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                          {t('Backup ID')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[150px]">
                          {t('Created')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                          {t('Size')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                          {t('Status')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          {t('Policy')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[100px] pe-4" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {backups.map((backup) => {
                        const status = getMysqlBackupStatus(backup.status)
                        const StatusIcon = status.icon
                        const policy = backup.policyId
                          ? policies.find((item) => item.$id === backup.policyId)
                          : null
                        const canRestore =
                          backup.status === 'completed' ||
                          backup.status === 'verified'

                        return (
                          <TableRow
                            key={backup.$id}
                            className="hover:bg-muted/50"
                          >
                            <TableCell className="px-4 py-3">
                              <Checkbox
                                checked={selectedBackups.has(backup.$id)}
                                onCheckedChange={(checked) => {
                                  const next = new Set(selectedBackups)
                                  if (checked) {
                                    next.add(backup.$id)
                                  } else {
                                    next.delete(backup.$id)
                                  }
                                  setSelectedBackups(next)
                                }}
                                onClick={(e) => e.stopPropagation()}
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <CopyableId
                                id={backup.$id}
                                size="sm"
                                maxWidth={180}
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <DateTooltip
                                date={backup.$createdAt}
                                className="text-[12px] font-medium text-muted-foreground"
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <code className="text-[12px] font-mono text-muted-foreground">
                                {formatBackupSize(backup.sizeBytes)}
                              </code>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Badge
                                variant={status.badgeVariant}
                                className="gap-1.5 text-[11px] font-medium"
                              >
                                <StatusIcon className="h-3 w-3" />
                                {t(status.label)}
                              </Badge>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              {policy ? (
                                <span className="text-[12px] text-foreground">
                                  {policy.name || t('Unnamed Policy')}
                                </span>
                              ) : isManualMysqlBackup(backup) ? (
                                <span className="text-[12px] text-muted-foreground">
                                  {t('Manual')}
                                </span>
                              ) : (
                                <span className="text-[12px] text-muted-foreground">
                                  -
                                </span>
                              )}
                            </TableCell>
                            <TableCell className="px-4 py-3 text-end pe-4">
                              <div className="flex justify-end">
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <RowActionsMenuTrigger />
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                    {canRestore && (
                                      <DropdownMenuItem
                                        onClick={() => {
                                          setSelectedBackup(backup)
                                          setRestoreDialogOpen(true)
                                        }}
                                      >
                                        <MenuItemContent icon={RotateCcw}>
                                          {t('Restore')}
                                        </MenuItemContent>
                                      </DropdownMenuItem>
                                    )}
                                    <DropdownMenuItem
                                      onClick={() => {
                                        navigator.clipboard.writeText(
                                          backup.$id,
                                        )
                                        toast.success(
                                          t('Backup ID copied to clipboard'),
                                        )
                                      }}
                                    >
                                      <MenuItemContent icon={Copy}>
                                        {t('Copy ID')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                      onClick={() => {
                                        setSelectedBackup(backup)
                                        setDeleteBackupDialogOpen(true)
                                      }}
                                    >
                                      <MenuItemContent icon={Trash2}>
                                        {t('Delete')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              </div>
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
                {backupsTotal > 0 && (
                  <div className="mt-4">
                    <Pagination
                      currentPage={backupsPage}
                      totalItems={backupsTotal}
                      pageSize={backupsPageSize}
                      pageSizeOptions={[12, 18, 36, 72]}
                      onPageChange={handlePageChange}
                      onPageSizeChange={handlePageSizeChange}
                      showTotal={true}
                      itemLabel={t('backups')}
                    />
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      </div>

      {selectedBackups.size > 0 && (
        <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
          <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              {selectedBackups.size}{' '}
              {selectedBackups.size > 1
                ? t('backups selected')
                : t('backup selected')}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSelectedBackups(new Set())}
                className="h-8 text-xs"
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={handleBulkDelete}
                disabled={bulkDeleteBackupsMutation.isPending}
                className="h-8 gap-2"
              >
                <Trash2 className="h-4 w-4" />
                {t('Delete')}
              </Button>
            </div>
          </div>
        </div>
      )}

      <Dialog
        open={bulkDeleteDialogOpen}
        onOpenChange={setBulkDeleteDialogOpen}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Delete backups')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {selectedBackups.size > 1
                ? t(
                    'Are you sure you want to delete the selected backups? This action cannot be undone.',
                  )
                : t(
                    'Are you sure you want to delete this backup? This action cannot be undone.',
                  )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setBulkDeleteDialogOpen(false)}
              disabled={bulkDeleteBackupsMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={confirmBulkDelete}
              disabled={bulkDeleteBackupsMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <CreatePolicyDialog
        open={createPolicyDialogOpen}
        onOpenChange={setCreatePolicyDialogOpen}
        onSubmit={(items) => createPolicyMutation.mutate(items)}
        isLoading={createPolicyMutation.isPending}
        existingPoliciesCount={policies.length}
        backupPoliciesLimit={backupPoliciesLimit}
      />

      <CreateManualBackupDialog
        open={createManualBackupDialogOpen}
        onOpenChange={setCreateManualBackupDialogOpen}
        onSubmit={() => createBackupMutation.mutate()}
        isLoading={createBackupMutation.isPending}
      />

      {selectedBackup && (
        <RestoreBackupDialog
          open={restoreDialogOpen}
          onOpenChange={setRestoreDialogOpen}
          backup={selectedBackup}
          onSubmit={() => createRestorationMutation.mutate(selectedBackup.$id)}
          isLoading={createRestorationMutation.isPending}
        />
      )}

      {selectedPolicy && (
        <DeletePolicyDialog
          open={deletePolicyDialogOpen}
          onOpenChange={setDeletePolicyDialogOpen}
          policy={selectedPolicy}
          onConfirm={() => deletePolicyMutation.mutate(selectedPolicy.$id)}
          isLoading={deletePolicyMutation.isPending}
        />
      )}

      {selectedBackup && (
        <DeleteBackupDialog
          open={deleteBackupDialogOpen}
          onOpenChange={setDeleteBackupDialogOpen}
          backup={selectedBackup}
          onConfirm={() => deleteBackupMutation.mutate(selectedBackup.$id)}
          isLoading={deleteBackupMutation.isPending}
        />
      )}
      </div>
    </div>
  )
}

interface CreatePolicyDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (
    policies: Array<{
      policyId: string
      retention: number
      schedule: string
      name: string
      enabled?: boolean
    }>,
  ) => void
  isLoading: boolean
  existingPoliciesCount: number
  backupPoliciesLimit: number
}

function CreatePolicyDialog({
  open,
  onOpenChange,
  onSubmit,
  isLoading,
  existingPoliciesCount,
  backupPoliciesLimit,
}: CreatePolicyDialogProps) {
  const t = useT()
  const [selectedPresets, setSelectedPresets] = useState<string[]>([])
  const [customPolicies, setCustomPolicies] = useState<
    Array<{
      frequency: 'hourly' | 'daily' | 'weekly' | 'monthly'
      time: string
      dayOfWeek?: number[]
      dayOfMonth?: 'first' | 'middle' | 'end'
      retention: number
      retentionUnit: 'days' | 'weeks' | 'months' | 'years' | 'forever'
      customRetention?: number
      name: string
    }>
  >([])

  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setSelectedPresets([])
      setCustomPolicies([])
    }
    onOpenChange(newOpen)
  }

  const handleSubmit = () => {
    const policies: Array<{
      policyId: string
      retention: number
      schedule: string
      name: string
      enabled?: boolean
    }> = []

    if (selectedPresets.includes('hourly')) {
      policies.push({
        policyId: ID.unique(),
        retention: 1,
        schedule: '0 * * * *',
        name: 'Hourly backup',
        enabled: true,
      })
    }
    if (selectedPresets.includes('daily')) {
      policies.push({
        policyId: ID.unique(),
        retention: 7,
        schedule: '0 2 * * *',
        name: 'Daily backup',
        enabled: true,
      })
    }

    customPolicies.forEach((custom) => {
      let schedule = ''
      let retention = custom.retention

      if (custom.retentionUnit === 'forever') {
        retention = 36500
      } else if (custom.retentionUnit === 'weeks') {
        retention = (custom.customRetention || 1) * 7
      } else if (custom.retentionUnit === 'months') {
        retention = (custom.customRetention || 1) * 30
      } else if (custom.retentionUnit === 'years') {
        retention = (custom.customRetention || 1) * 365
      } else {
        retention = custom.customRetention || 1
      }

      const [hour, minute] = custom.time.split(':').map(Number)
      if (custom.frequency === 'hourly') {
        schedule = '0 * * * *'
      } else if (custom.frequency === 'daily') {
        schedule = `${minute || 0} ${hour || 2} * * *`
      } else if (custom.frequency === 'weekly') {
        const dayOfWeek = custom.dayOfWeek?.[0] || 1
        schedule = `${minute || 0} ${hour || 2} * * ${dayOfWeek}`
      } else if (custom.frequency === 'monthly') {
        const dayOfMonth =
          custom.dayOfMonth === 'first'
            ? 1
            : custom.dayOfMonth === 'middle'
              ? 15
              : 28
        schedule = `${minute || 0} ${hour || 2} ${dayOfMonth} * *`
      }

      policies.push({
        policyId: ID.unique(),
        retention,
        schedule,
        name: custom.name || `${custom.frequency} backup`,
        enabled: true,
      })
    })

    if (policies.length === 0) return
    onSubmit(policies)
  }

  const totalPolicies = selectedPresets.length + customPolicies.length
  const canCreateCustom =
    backupPoliciesLimit === 0 ||
    existingPoliciesCount + totalPolicies < backupPoliciesLimit
  const supportsCustomPolicies =
    supportsAdvancedBackupPolicies(backupPoliciesLimit)
  const remainingSlots =
    backupPoliciesLimit > 0
      ? Math.max(0, backupPoliciesLimit - existingPoliciesCount - totalPolicies)
      : null
  const canSelectAnotherPreset =
    remainingSlots == null || remainingSlots > 0

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Create backup policy')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {supportsCustomPolicies
              ? t('Choose preset policies or create custom backup schedules.')
              : t(
                  'Your plan only supports the daily preset policy. Upgrade to create custom policies.',
                )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0 space-y-6">
          <div className="space-y-3">
            <Label className="text-[13px]">{t('Preset Policies')}</Label>
            <div className="space-y-2">
              {supportsCustomPolicies ? (
                <div className="flex items-center space-x-2 rounded-lg border border-border p-3">
                  <Checkbox
                    id="hourly"
                    checked={selectedPresets.includes('hourly')}
                    disabled={
                      !selectedPresets.includes('hourly') &&
                      !canSelectAnotherPreset
                    }
                    onCheckedChange={(checked) => {
                      if (checked) {
                        if (
                          selectedPresets.includes('hourly') ||
                          !canSelectAnotherPreset
                        ) {
                          return
                        }
                        setSelectedPresets([...selectedPresets, 'hourly'])
                      } else {
                        setSelectedPresets(
                          selectedPresets.filter(
                            (preset) => preset !== 'hourly',
                          ),
                        )
                      }
                    }}
                  />
                  <Label htmlFor="hourly" className="flex-1 cursor-pointer">
                    <div className="font-medium text-[13px]">{t('Hourly')}</div>
                    <div className="text-[12px] text-muted-foreground">
                      {t('Runs every hour, retained for 24 hours')}
                    </div>
                  </Label>
                </div>
              ) : null}
              <div className="flex items-center space-x-2 rounded-lg border border-border p-3">
                <Checkbox
                  id="daily"
                  checked={selectedPresets.includes('daily')}
                  disabled={
                    !selectedPresets.includes('daily') && !canSelectAnotherPreset
                  }
                  onCheckedChange={(checked) => {
                    if (checked) {
                      if (
                        selectedPresets.includes('daily') ||
                        !canSelectAnotherPreset
                      ) {
                        return
                      }
                      setSelectedPresets([...selectedPresets, 'daily'])
                    } else {
                      setSelectedPresets(
                        selectedPresets.filter((preset) => preset !== 'daily'),
                      )
                    }
                  }}
                />
                <Label htmlFor="daily" className="flex-1 cursor-pointer">
                  <div className="font-medium text-[13px]">{t('Daily')}</div>
                  <div className="text-[12px] text-muted-foreground">
                    {t('Runs every day, retained for 7 days')}
                  </div>
                </Label>
              </div>
            </div>
          </div>

          {supportsCustomPolicies ? (
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <Label className="text-[13px]">{t('Custom Policies')}</Label>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!canCreateCustom}
                onClick={() => {
                  if (!canCreateCustom) return
                  setCustomPolicies([
                    ...customPolicies,
                    {
                      frequency: 'daily',
                      time: '02:00',
                      retention: 7,
                      retentionUnit: 'days',
                      name: '',
                    },
                  ])
                }}
              >
                <Plus className="me-1.5 h-4 w-4" />
                {t('Add custom policy')}
              </Button>
            </div>

            {customPolicies.map((policy, index) => (
              <Card key={index}>
                <CardHeader className="pb-3">
                  <div className="flex items-center justify-between">
                    <CardTitle className="text-[14px]">
                      {t('Custom Policy')} {index + 1}
                    </CardTitle>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        setCustomPolicies(
                          customPolicies.filter((_, itemIndex) => itemIndex !== index),
                        )
                      }}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="space-y-2">
                    <Label className="text-[13px]">{t('Frequency')}</Label>
                    <Select
                      value={policy.frequency}
                      onValueChange={(
                        value: 'hourly' | 'daily' | 'weekly' | 'monthly',
                      ) => {
                        const updated = [...customPolicies]
                        updated[index].frequency = value
                        setCustomPolicies(updated)
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="hourly">{t('Hourly')}</SelectItem>
                        <SelectItem value="daily">{t('Daily')}</SelectItem>
                        <SelectItem value="weekly">{t('Weekly')}</SelectItem>
                        <SelectItem value="monthly">{t('Monthly')}</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  {policy.frequency !== 'hourly' && (
                    <div className="space-y-2">
                      <Label className="text-[13px]">{t('Time')}</Label>
                      <Input
                        type="time"
                        value={policy.time}
                        onChange={(e) => {
                          const updated = [...customPolicies]
                          updated[index].time = e.target.value
                          setCustomPolicies(updated)
                        }}
                      />
                    </div>
                  )}

                  {policy.frequency === 'weekly' && (
                    <div className="space-y-2">
                      <Label className="text-[13px]">{t('Day of Week')}</Label>
                      <Select
                        value={policy.dayOfWeek?.[0]?.toString() || '1'}
                        onValueChange={(value) => {
                          const updated = [...customPolicies]
                          updated[index].dayOfWeek = [parseInt(value, 10)]
                          setCustomPolicies(updated)
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="1">{t('Monday')}</SelectItem>
                          <SelectItem value="2">{t('Tuesday')}</SelectItem>
                          <SelectItem value="3">{t('Wednesday')}</SelectItem>
                          <SelectItem value="4">{t('Thursday')}</SelectItem>
                          <SelectItem value="5">{t('Friday')}</SelectItem>
                          <SelectItem value="6">{t('Saturday')}</SelectItem>
                          <SelectItem value="0">{t('Sunday')}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  )}

                  {policy.frequency === 'monthly' && (
                    <div className="space-y-2">
                      <Label className="text-[13px]">{t('Day of Month')}</Label>
                      <Select
                        value={policy.dayOfMonth || 'first'}
                        onValueChange={(value: 'first' | 'middle' | 'end') => {
                          const updated = [...customPolicies]
                          updated[index].dayOfMonth = value
                          setCustomPolicies(updated)
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="first">
                            {t('First of month')}
                          </SelectItem>
                          <SelectItem value="middle">
                            {t('Middle (15th)')}
                          </SelectItem>
                          <SelectItem value="end">{t('End (28th)')}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  )}

                  <div className="space-y-2">
                    <Label className="text-[13px]">{t('Retention')}</Label>
                    <Select
                      value={policy.retentionUnit}
                      onValueChange={(
                        value:
                          | 'days'
                          | 'weeks'
                          | 'months'
                          | 'years'
                          | 'forever',
                      ) => {
                        const updated = [...customPolicies]
                        updated[index].retentionUnit = value
                        setCustomPolicies(updated)
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="days">{t('Days')}</SelectItem>
                        <SelectItem value="weeks">{t('Weeks')}</SelectItem>
                        <SelectItem value="months">{t('Months')}</SelectItem>
                        <SelectItem value="years">{t('Years')}</SelectItem>
                        <SelectItem value="forever">{t('Forever')}</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  {policy.retentionUnit !== 'forever' && (
                    <div className="space-y-2">
                      <Label className="text-[13px]">{t('Number')}</Label>
                      <Input
                        type="number"
                        min="1"
                        value={policy.customRetention || 1}
                        onChange={(e) => {
                          const updated = [...customPolicies]
                          updated[index].customRetention =
                            parseInt(e.target.value, 10) || 1
                          setCustomPolicies(updated)
                        }}
                      />
                    </div>
                  )}

                  <div className="space-y-2">
                    <Label className="text-[13px]">{t('Policy Name')}</Label>
                    <Input
                      placeholder={`${policy.frequency} backup`}
                      value={policy.name}
                      onChange={(e) => {
                        const updated = [...customPolicies]
                        updated[index].name = e.target.value
                        setCustomPolicies(updated)
                      }}
                    />
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
          ) : null}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isLoading || totalPolicies === 0}
          >
            {t('Create')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function CreateManualBackupDialog({
  open,
  onOpenChange,
  onSubmit,
  isLoading,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: () => void
  isLoading: boolean
}) {
  const t = useT()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Create manual backup')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Manual backups are retained forever unless manually deleted. Use for major data changes or rollback safeguards.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0">
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertDescription className="text-[13px]">
              <b>
                {t('Depending on the size of your data, this may take a while.')}
              </b>
            </AlertDescription>
          </Alert>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={onSubmit} disabled={isLoading}>
            {t('Create')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function RestoreBackupDialog({
  open,
  onOpenChange,
  backup,
  onSubmit,
  isLoading,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  backup: Models.DedicatedDatabaseBackup
  onSubmit: () => void
  isLoading: boolean
}) {
  const t = useT()
  const [confirmRestore, setConfirmRestore] = useState(false)
  const backupStatus = getMysqlBackupStatus(backup.status)
  const StatusIcon = backupStatus.icon

  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setConfirmRestore(false)
    }
    onOpenChange(newOpen)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-5 text-start">
          <DialogTitle>{t('Restore backup')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Restore this database from the selected backup. All current data will be replaced.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0">
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-5">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Archive snapshot')}
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="grid grid-cols-2 gap-x-6 gap-y-3 px-6 py-4 text-[13px]">
              <div className="flex flex-col gap-0.5">
                <span className="text-muted-foreground">{t('Created')}</span>
                <span className="text-foreground">
                  {new Date(backup.$createdAt)
                    .toISOString()
                    .replace('T', ' ')
                    .slice(0, 19)}
                </span>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-muted-foreground">{t('Size')}</span>
                <span className="text-foreground">
                  {formatBackupSize(backup.sizeBytes)}
                </span>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-muted-foreground">{t('Status')}</span>
                <Badge
                  variant={backupStatus.badgeVariant}
                  className="gap-1.5 text-[11px] font-medium w-fit"
                >
                  <StatusIcon className="h-3 w-3" />
                  {t(backupStatus.label)}
                </Badge>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-muted-foreground">{t('Age')}</span>
                <span className="text-foreground">
                  {formatDistanceToNow(new Date(backup.$createdAt), {
                    addSuffix: true,
                  })}
                </span>
              </div>
            </div>
          </div>

          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-start gap-3">
              <Checkbox
                id="confirm-restore"
                checked={confirmRestore}
                onCheckedChange={(checked) =>
                  setConfirmRestore(checked === true)
                }
                className="mt-0.5"
              />
              <Label
                htmlFor="confirm-restore"
                className="flex-1 cursor-pointer text-[13px] text-foreground"
              >
                {t(
                  'I understand that all current database data will be permanently replaced by this backup.',
                )}
              </Label>
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={onSubmit}
            disabled={isLoading || !confirmRestore}
          >
            {t('Restore')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function DeletePolicyDialog({
  open,
  onOpenChange,
  policy,
  onConfirm,
  isLoading,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  policy: Models.BackupPolicy
  onConfirm: () => void
  isLoading: boolean
}) {
  const t = useT()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Delete policy')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Are you sure you want to delete the policy')}{' '}
            <strong>{policy.name || t('Unnamed Policy')}</strong>?{' '}
            {t('This action cannot be undone.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0">
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertDescription className="text-[13px]">
              {t(
                'Backups already taken by this policy are kept until their retention expires.',
              )}
            </AlertDescription>
          </Alert>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            size="sm"
            className="h-9 text-[13px]"
            onClick={onConfirm}
            disabled={isLoading}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function DeleteBackupDialog({
  open,
  onOpenChange,
  backup,
  onConfirm,
  isLoading,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  backup: Models.DedicatedDatabaseBackup
  onConfirm: () => void
  isLoading: boolean
}) {
  const t = useT()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Delete backup')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Are you sure you want to delete the backup from')}{' '}
            <strong>{new Date(backup.$createdAt).toLocaleString()}</strong>?{' '}
            {t('This action cannot be undone.')}
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            size="sm"
            className="h-9 text-[13px]"
            onClick={onConfirm}
            disabled={isLoading}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
