import { useState, useEffect } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ID, BackupServices } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { formatDistanceToNow } from 'date-fns'
import { formatDateTime } from '@/lib/date-utils'
import {
  useBackupPolicies,
  useBackupArchives,
  useDatabaseRestoreMigrations,
  enrichRestorationTargetOptions,
} from '@/lib/react-query/hooks'
import { RestoreProgressBanner } from './_components/RestoreProgressBanner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger} from '@/components/ui/tooltip'
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
  Lock} from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { UpgradeCurtain } from '@/components/ui/upgrade-curtain'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue} from '@/components/ui/select'
import type { Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { useProject, useOrganizationPlan } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  getBackupPoliciesPlanLimit,
  isBackupPoliciesAtPlanLimit,
  supportsAdvancedBackupPolicies,
} from '@/lib/databases/backup-policy-plan-limits'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { toByteCount } from '@/lib/utils/byte-display-unit'

type BackupStatusVariant = 'completed' | 'failed' | 'pending' | 'processing'

function getBackupStatus(status: string) {
  const statusMap: Record<
    string,
    { label: string; icon: typeof Clock; badgeVariant: BackupStatusVariant }
  > = {
    pending: { label: 'Pending', icon: Clock, badgeVariant: 'pending' },
    completed: {
      label: 'Complete',
      icon: CheckCircle2,
      badgeVariant: 'completed'},
    uploading: {
      label: 'Processing',
      icon: CircleDashed,
      badgeVariant: 'processing'},
    downloading: {
      label: 'Processing',
      icon: CircleDashed,
      badgeVariant: 'processing'},
    failed: { label: 'Failed', icon: AlertCircle, badgeVariant: 'failed' }}
  const statusInfo = statusMap[status] || {
    label: 'Waiting',
    icon: Clock,
    badgeVariant: 'pending' as BackupStatusVariant}
  return {
    label: statusInfo.label,
    badgeVariant: statusInfo.badgeVariant,
    icon: statusInfo.icon}
}

interface BackupsViewProps {
  databaseId: string
}

export function BackupsView({ databaseId }: BackupsViewProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const queryClient = useQueryClient()
  const [backupsPage, setBackupsPage] = useState(1)
  const [backupsPageSize, setBackupsPageSize] = useState(12)
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
    useState<Models.BackupArchive | null>(null)
  const [selectedBackups, setSelectedBackups] = useState<Set<string>>(new Set())
  const [sessionRestorations, setSessionRestorations] = useState<
    Models.BackupRestoration[]
  >([])

  // Get project to get teamId for organization plan
  const { project } = useProject(projectId)
  const region = project?.region

  // Get organization plan to check backups availability
  const { plan: organizationPlan, isLoading: planLoading } =
    useOrganizationPlan(project?.teamId)
  const backupsEnabled = organizationPlan?.backupsEnabled ?? false
  const backupPoliciesLimit = getBackupPoliciesPlanLimit(organizationPlan)
  const planName = resolveOrganizationPlanDisplayLabel({
    planName: organizationPlan?.name ?? null,
    planId: organizationPlan?.$id,
  })

  // Fetch policies and archives - only if backups are enabled
  const { data: policiesData, isLoading: policiesLoading } = useBackupPolicies(
    projectId,
    databaseId,
    { enabled: backupsEnabled },
  )
  const { data: archivesData, isLoading: archivesLoading } = useBackupArchives(
    projectId,
    databaseId,
    backupsPage - 1,
    backupsPageSize,
    { enabled: backupsEnabled },
  )
  const { data: restoreMigrations } = useDatabaseRestoreMigrations(
    projectId,
    databaseId,
    { enabled: backupsEnabled },
  )

  const visibleRestorations = (() => {
    const byMigrationId = new Map<string, Models.BackupRestoration>()
    // Prefer session entries first so enriched target options win over the API copy.
    for (const restoration of [
      ...sessionRestorations,
      ...(restoreMigrations || []),
    ]) {
      const id = restoration.migrationId
      if (!id || byMigrationId.has(id)) continue
      byMigrationId.set(id, restoration)
    }
    return Array.from(byMigrationId.values()).sort((a, b) => {
      const aTime = Date.parse(a.$createdAt || a.$updatedAt || '') || 0
      const bTime = Date.parse(b.$createdAt || b.$updatedAt || '') || 0
      return bTime - aTime
    })
  })()

  const policies: Models.BackupPolicy[] = policiesData?.policies || []
  const archives: Models.BackupArchive[] = archivesData?.archives || []
  const archivesTotal =
    typeof archivesData?.total === 'bigint'
      ? Number(archivesData.total)
      : archivesData?.total || 0
  const isAtBackupPoliciesLimit = isBackupPoliciesAtPlanLimit(
    policies.length,
    backupPoliciesLimit,
  )
  const showPlanLimitWarning =
    backupPoliciesLimit > 0 && policies.length >= backupPoliciesLimit * 0.5
  const createPolicyDisabledTooltip = isAtBackupPoliciesLimit
    ? t("You've reached the limit for this resource on your plan")
    : undefined

  // Only show loading if we don't have data yet (account for prefetched data from route loader)
  const isPoliciesActuallyLoading =
    policiesLoading && policies.length === 0 && !policiesData
  const isArchivesActuallyLoading =
    archivesLoading && archives.length === 0 && !archivesData

  // Check if backups are disabled
  // Wait for plan to load before determining if backups are disabled
  const isBackupsDisabled = planLoading
    ? false // Don't show lock screen while loading
    : !backupsEnabled

  // Policy mutations
  const createPolicyMutation = useMutation({
    mutationFn: async (
      policies: Array<{
        policyId: string
        services: BackupServices[]
        retention: number
        schedule: string
        name?: string
        resourceId?: string
        enabled?: boolean
      }>,
    ) => {
      const projectSdk = sdk.forProject(projectId)
      return Promise.all(
        policies.map((policy) =>
          projectSdk.backups.createPolicy({
            policyId: policy.policyId,
            services: policy.services,
            retention: policy.retention,
            schedule: policy.schedule,
            name: policy.name,
            resourceId: policy.resourceId || databaseId,
            enabled: policy.enabled ?? true}),
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
      // Invalidate policies query for this specific database
      queryClient.invalidateQueries({
        queryKey: [
          'backup-policies',
          'project',
          projectId,
          'database',
          databaseId,
        ]})
      setCreatePolicyDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create backup policy'))
    }})

  const deletePolicyMutation = useMutation({
    mutationFn: async (policyId: string) => {
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.backups.deletePolicy({ policyId })
    },
    onSuccess: () => {
      toast.success(t('Backup policy has been deleted'))
      // Invalidate policies query for this specific database
      queryClient.invalidateQueries({
        queryKey: [
          'backup-policies',
          'project',
          projectId,
          'database',
          databaseId,
        ]})
      setDeletePolicyDialogOpen(false)
      setSelectedPolicy(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete backup policy'))
    }})

  // Archive mutations
  const createArchiveMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.backups.createArchive({
        services: [BackupServices.Databases],
        resourceId: databaseId})
    },
    onSuccess: () => {
      toast.success(t('Database backup has started'))
      // Invalidate archives query for this specific database (all pages)
      queryClient.invalidateQueries({
        queryKey: [
          'backup-archives',
          'project',
          projectId,
          'database',
          databaseId,
        ]})
      setCreateManualBackupDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create backup'))
    }})

  const deleteArchiveMutation = useMutation({
    mutationFn: async (archiveId: string) => {
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.backups.deleteArchive({ archiveId })
    },
    onSuccess: () => {
      toast.success(t('1 backup deleted'))
      // Invalidate archives query for this specific database (all pages)
      queryClient.invalidateQueries({
        queryKey: [
          'backup-archives',
          'project',
          projectId,
          'database',
          databaseId,
        ]})
      setDeleteBackupDialogOpen(false)
      setSelectedBackup(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete backup'))
    }})

  const bulkDeleteArchivesMutation = useMutation({
    mutationFn: async (archiveIds: string[]) => {
      const projectSdk = sdk.forProject(projectId)
      // Delete all archives in parallel
      await Promise.all(
        archiveIds.map((archiveId) =>
          projectSdk.backups.deleteArchive({ archiveId }),
        ),
      )
    },
    onSuccess: () => {
      toast.success(
        selectedBackups.size === 1
          ? t('Backup deleted successfully')
          : t('Backups deleted successfully'),
      )
      // Invalidate archives query for this specific database (all pages)
      queryClient.invalidateQueries({
        queryKey: [
          'backup-archives',
          'project',
          projectId,
          'database',
          databaseId,
        ]})
      setSelectedBackups(new Set())
      setBulkDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete backups'))
    }})

  const createRestorationMutation = useMutation({
    mutationFn: async (params: {
      archiveId: string
      services: BackupServices[]
      restoreTo: 'new' | 'same'
      newResourceId?: string
      newResourceName?: string
    }) => {
      const projectSdk = sdk.forProject(projectId)
      if (params.restoreTo === 'new') {
        return projectSdk.backups.createRestoration({
          archiveId: params.archiveId,
          services: params.services,
          newResourceId: params.newResourceId,
          newResourceName: params.newResourceName,
        })
      }
      return projectSdk.backups.createRestoration({
        archiveId: params.archiveId,
        services: params.services,
      })
    },
    onSuccess: (restoration, variables) => {
      toast.success(t('Database restore initiated'))
      if (restoration?.migrationId) {
        const enrichedRestoration = enrichRestorationTargetOptions(
          restoration,
          {
            oldId: databaseId,
            newId:
              variables.restoreTo === 'new' ? variables.newResourceId || '' : '',
            newName:
              variables.restoreTo === 'new'
                ? variables.newResourceName || ''
                : '',
          },
        )
        setSessionRestorations((prev) =>
          prev.some((item) => item.migrationId === restoration.migrationId)
            ? prev
            : [enrichedRestoration, ...prev],
        )
        queryClient.setQueryData<Models.BackupRestoration[]>(
          [
            'restorations',
            'project',
            projectId,
            'database',
            databaseId,
            'recent-migrations',
          ],
          (previous) => {
            const list = previous ?? []
            if (
              list.some((item) => item.migrationId === restoration.migrationId)
            ) {
              return list
            }
            return [enrichedRestoration, ...list]
          },
        )
      }
      // Invalidate archives query to refresh backup status
      queryClient.invalidateQueries({
        queryKey: [
          'backup-archives',
          'project',
          projectId,
          'database',
          databaseId,
        ],
      })
      queryClient.invalidateQueries({
        queryKey: [
          'restorations',
          'project',
          projectId,
          'database',
          databaseId,
        ],
      })
      setRestoreDialogOpen(false)
      setSelectedBackup(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to restore backup'))
    },
  })

  // Format backup size (API may return int64 size as bigint)
  const formatSize = (bytes: number | bigint | undefined) => {
    const n = toByteCount(bytes)
    if (n <= 0) return '-'
    const mb = n / (1000 * 1000)
    if (mb < 1) {
      return `${(n / 1000).toFixed(2)} KB`
    }
    return `${mb.toFixed(2)} MB`
  }

  // Calculate next backup date from cron
  const getNextBackupDate = (schedule: string) => {
    // Simple implementation - for hourly (0 * * * *) and daily (* * * *)
    // In production, use a proper cron parser library
    const now = new Date()
    const nextDate = new Date(now)

    if (schedule === '0 * * * *') {
      // Hourly - next hour at minute 0
      nextDate.setHours(nextDate.getHours() + 1, 0, 0, 0)
    } else if (schedule.includes('* * *')) {
      // Daily - next day at 00:00
      nextDate.setDate(nextDate.getDate() + 1)
      nextDate.setHours(0, 0, 0, 0)
    } else {
      // For other schedules, return a placeholder
      return t('Calculating...')
    }

    return formatDateTime(nextDate)
  }

  // Get previous backup for a policy
  const getPreviousBackup = (policyId: string) => {
    return archives.find(
      (archive) =>
        (archive.policyId as string | null | undefined) === policyId &&
        archive.status === 'completed',
    )
  }

  // Clear selection when page changes
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
    bulkDeleteArchivesMutation.mutate(Array.from(selectedBackups))
  }

  const handlePageChange = (page: number) => {
    setBackupsPage(page)
    setSelectedBackups(new Set()) // Clear selection on page change
  }

  const handlePageSizeChange = (size: number) => {
    setBackupsPageSize(size)
    setBackupsPage(1)
    setSelectedBackups(new Set()) // Clear selection on page size change
  }

  if (isBackupsDisabled) {
    return (
      <div className="mx-auto w-full max-w-7xl mt-4 px-4 pb-4 sm:mt-6 sm:px-6 sm:pb-6">
        <UpgradeCurtain
          isLocked={true}
          orgId={project?.teamId}
          message={t('Backups are available on Appwrite Cloud Pro and higher plans.') /* pragma: allowlist secret */}
        >
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <div className="flex items-center gap-2">
                <Lock className="h-5 w-5 text-muted-foreground" />
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Backups')}
                </h3>
              </div>
              <p className="text-[13px] text-muted-foreground mt-2">
                {t(
                  'Automated backup policies and manual backups to ensure your data stays safe.',
                )}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 min-h-[400px]">
              <EmptyState
                icon={Archive}
                title={t('Ensure your data stays safe')}
                description={t(
                  'Create backup policies to automate regular and secure data protection for your databases.',
                )}
                isEmpty={true}
                variant="centered"
                iconSize="md"
              />
            </div>
          </div>
        </UpgradeCurtain>
      </div>
    )
  }

  return (
    <div className="w-full">
      {visibleRestorations.length > 0 ? (
        <>
          <div className="mx-auto w-full max-w-7xl mt-4 px-4 sm:mt-6 sm:px-6">
            <div className="space-y-2">
              {visibleRestorations.map((restoration) => (
                <RestoreProgressBanner
                  key={restoration.migrationId}
                  projectId={projectId}
                  databaseId={databaseId}
                  region={region}
                  restoration={restoration}
                />
              ))}
            </div>
          </div>
          <div className="my-6 w-full border-t border-border" />
        </>
      ) : null}
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
          visibleRestorations.length === 0 &&
            !showPlanLimitWarning &&
            'mt-4 sm:mt-6',
          showPlanLimitWarning && 'pt-4 sm:pt-6',
        )}
      >
      <div className="grid gap-6 lg:grid-cols-3 lg:items-stretch">
        {/* Policies Section */}
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
                          <div className="flex items-center gap-2 mb-1">
                            <h4 className="text-[14px] font-medium text-foreground">
                              {policy.name || t('Unnamed Policy')}
                            </h4>
                          </div>
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
                            {getNextBackupDate(policy.schedule)}
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

        {/* Backups Section */}
        <div className="lg:col-span-2 flex flex-col">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Backups')}
              </h3>
            </div>
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
            {isArchivesActuallyLoading ? (
              <div className="flex h-full min-h-[280px] items-center justify-center rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">
                  {t('Loading backups...')}
                </div>
              </div>
            ) : archives.length === 0 ? (
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
                              archives.length > 0 &&
                              archives.every((archive) =>
                                selectedBackups.has(archive.$id),
                              )
                            }
                            onCheckedChange={(checked) => {
                              if (checked) {
                                setSelectedBackups(
                                  new Set(archives.map((a) => a.$id)),
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
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[100px] pe-4"></TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {archives.map((archive) => {
                        const status = getBackupStatus(archive.status)
                        const StatusIcon = status.icon
                        // Handle null policyId for manual backups (API may return null even though type says string)
                        const policyId = archive.policyId as
                          | string
                          | null
                          | undefined
                        const policy = policyId
                          ? policies.find((p) => p.$id === policyId)
                          : null

                        return (
                          <TableRow
                            key={archive.$id}
                            className="hover:bg-muted/50"
                          >
                            <TableCell className="px-4 py-3">
                              <Checkbox
                                checked={selectedBackups.has(archive.$id)}
                                onCheckedChange={(checked) => {
                                  const newSelected = new Set(selectedBackups)
                                  if (checked) {
                                    newSelected.add(archive.$id)
                                  } else {
                                    newSelected.delete(archive.$id)
                                  }
                                  setSelectedBackups(newSelected)
                                }}
                                onClick={(e) => e.stopPropagation()}
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <CopyableId
                                id={archive.$id}
                                size="sm"
                                maxWidth={180}
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <DateTooltip
                                date={archive.$createdAt}
                                className="text-[12px] font-medium text-muted-foreground"
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <code className="text-[12px] font-mono text-muted-foreground">
                                {formatSize(archive.size)}
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
                              ) : (
                                <span className="text-[12px] text-muted-foreground">
                                  {t('Manual')}
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
                                    {archive.status === 'completed' && (
                                      <DropdownMenuItem
                                        onClick={() => {
                                          setSelectedBackup(archive)
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
                                          archive.$id,
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
                                        setSelectedBackup(archive)
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
                {archivesTotal > 0 && (
                  <div className="mt-4">
                    <Pagination
                      currentPage={backupsPage}
                      totalItems={archivesTotal}
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
      </div>

      {/* Bulk Delete Action Bar */}
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
                disabled={bulkDeleteArchivesMutation.isPending}
                className="h-8 gap-2"
              >
                <Trash2 className="h-4 w-4" />
                {t('Delete')}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Bulk Delete Confirmation Dialog */}
      <Dialog
        open={bulkDeleteDialogOpen}
        onOpenChange={setBulkDeleteDialogOpen}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Delete backups')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {selectedBackups.size > 1
                ? t('Are you sure you want to delete the selected backups? This action cannot be undone.')
                : t('Are you sure you want to delete this backup? This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setBulkDeleteDialogOpen(false)}
              disabled={bulkDeleteArchivesMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={confirmBulkDelete}
              disabled={bulkDeleteArchivesMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Create Policy Dialog */}
      <CreatePolicyDialog
        open={createPolicyDialogOpen}
        onOpenChange={setCreatePolicyDialogOpen}
        onSubmit={(policies) => {
          createPolicyMutation.mutate(policies)
        }}
        isLoading={createPolicyMutation.isPending}
        databaseId={databaseId}
        existingPoliciesCount={policies.length}
        backupPoliciesLimit={backupPoliciesLimit}
      />

      {/* Create Manual Backup Dialog */}
      <CreateManualBackupDialog
        open={createManualBackupDialogOpen}
        onOpenChange={setCreateManualBackupDialogOpen}
        onSubmit={() => {
          createArchiveMutation.mutate()
        }}
        isLoading={createArchiveMutation.isPending}
      />

      {/* Restore Backup Dialog */}
      {selectedBackup && (
        <RestoreBackupDialog
          open={restoreDialogOpen}
          onOpenChange={setRestoreDialogOpen}
          backup={selectedBackup}
          databaseId={databaseId}
          onSubmit={(params) => {
            createRestorationMutation.mutate(params)
          }}
          isLoading={createRestorationMutation.isPending}
        />
      )}

      {/* Delete Policy Dialog */}
      {selectedPolicy && (
        <DeletePolicyDialog
          open={deletePolicyDialogOpen}
          onOpenChange={setDeletePolicyDialogOpen}
          policy={selectedPolicy}
          onConfirm={() => {
            deletePolicyMutation.mutate(selectedPolicy.$id)
          }}
          isLoading={deletePolicyMutation.isPending}
        />
      )}

      {/* Delete Backup Dialog */}
      {selectedBackup && (
        <DeleteBackupDialog
          open={deleteBackupDialogOpen}
          onOpenChange={setDeleteBackupDialogOpen}
          backup={selectedBackup}
          onConfirm={() => {
            deleteArchiveMutation.mutate(selectedBackup.$id)
          }}
          isLoading={deleteArchiveMutation.isPending}
        />
      )}
    </div>
  )
}

// Create Policy Dialog Component
interface CreatePolicyDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (
    policies: Array<{
      policyId: string
      services: BackupServices[]
      retention: number
      schedule: string
      name?: string
      resourceId?: string
      enabled?: boolean
    }>,
  ) => void
  isLoading: boolean
  databaseId: string
  existingPoliciesCount: number
  backupPoliciesLimit: number
}

function CreatePolicyDialog({
  open,
  onOpenChange,
  onSubmit,
  isLoading,
  databaseId,
  existingPoliciesCount,
  backupPoliciesLimit}: CreatePolicyDialogProps) {
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
      services: BackupServices[]
      retention: number
      schedule: string
      name?: string
      resourceId?: string
      enabled?: boolean
    }> = []

    // Add preset policies
    if (selectedPresets.includes('hourly')) {
      policies.push({
        policyId: ID.unique(),
        services: [BackupServices.Databases],
        retention: 1,
        schedule: '0 * * * *',
        name: 'Hourly backup',
        resourceId: databaseId,
        enabled: true})
    }
    if (selectedPresets.includes('daily')) {
      policies.push({
        policyId: ID.unique(),
        services: [BackupServices.Databases],
        retention: 7,
        schedule: '0 2 * * *',
        name: 'Daily backup',
        resourceId: databaseId,
        enabled: true})
    }

    // Add custom policies
    customPolicies.forEach((custom) => {
      let schedule = ''
      let retention = custom.retention

      // Calculate retention in days
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

      // Build schedule
      const [hour, minute] = custom.time.split(':').map(Number)
      if (custom.frequency === 'hourly') {
        schedule = `0 * * * *`
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
        services: [BackupServices.Databases],
        retention,
        schedule,
        name: custom.name || `${custom.frequency} backup`,
        resourceId: databaseId,
        enabled: true})
    })

    if (policies.length === 0) return

    onSubmit(policies)
  }

  const totalPolicies = selectedPresets.length + customPolicies.length
  const canCreateCustom =
    backupPoliciesLimit === 0 ||
    existingPoliciesCount + totalPolicies < backupPoliciesLimit
  // Pro (limit = 1) is daily-only; unlimited (0) or higher unlocks hourly/custom.
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
              : t('Your plan only supports the daily preset policy. Upgrade to create custom policies.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0 space-y-6">
          {/* Preset Policies */}
          <div className="space-y-3">
            <Label className="text-[13px]">{t('Preset Policies')}</Label>
            <div className="space-y-2">
              {supportsCustomPolicies && (
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
                          selectedPresets.filter((p) => p !== 'hourly'),
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
              )}
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
                        selectedPresets.filter((p) => p !== 'daily'),
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

          {/* Custom Policies - Only if plan supports custom policies */}
          {supportsCustomPolicies && (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label className="text-[13px]">{t('Custom Policies')}</Label>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setCustomPolicies([
                      ...customPolicies,
                      {
                        frequency: 'daily',
                        time: '02:00',
                        retention: 7,
                        retentionUnit: 'days',
                        name: ''},
                    ])
                  }}
                  disabled={!canCreateCustom}
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
                            customPolicies.filter((_, i) => i !== index),
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
                            updated[index].dayOfWeek = [parseInt(value)]
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
                          onValueChange={(
                            value: 'first' | 'middle' | 'end',
                          ) => {
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
                              parseInt(e.target.value) || 1
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
          )}
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

// Create Manual Backup Dialog
interface CreateManualBackupDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: () => void
  isLoading: boolean
}

function CreateManualBackupDialog({
  open,
  onOpenChange,
  onSubmit,
  isLoading}: CreateManualBackupDialogProps) {
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

// Restore Backup Dialog
interface RestoreBackupDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  backup: Models.BackupArchive
  databaseId: string
  onSubmit: (params: {
    archiveId: string
    services: BackupServices[]
    restoreTo: 'new' | 'same'
    newResourceId?: string
    newResourceName?: string
  }) => void
  isLoading: boolean
}

function RestoreBackupDialog({
  open,
  onOpenChange,
  backup,
  databaseId,
  onSubmit,
  isLoading}: RestoreBackupDialogProps) {
  const t = useT()
  const [restoreOption, setRestoreOption] = useState<'new' | 'same'>('new')
  const [newDatabaseName, setNewDatabaseName] = useState('')
  const [newDatabaseId, setNewDatabaseId] = useState('')
  const [confirmSameDbRestore, setConfirmSameDbRestore] = useState(false)

  const formatSize = (bytes: number | bigint | undefined) => {
    const n = toByteCount(bytes)
    if (n <= 0) return '-'
    const mb = n / (1000 * 1000)
    if (mb < 1) {
      return `${(n / 1000).toFixed(2)} KB`
    }
    return `${mb.toFixed(2)} MB`
  }

  const handleSubmit = () => {
    if (restoreOption === 'new') {
      if (!newDatabaseName) return
      onSubmit({
        archiveId: backup.$id,
        services: [BackupServices.Databases],
        restoreTo: 'new',
        // Always send a concrete ID so Open database can target the new DB
        newResourceId: newDatabaseId.trim() || ID.unique(),
        newResourceName: newDatabaseName.trim(),
      })
    } else {
      if (!confirmSameDbRestore) return
      onSubmit({
        archiveId: backup.$id,
        services: [BackupServices.Databases],
        restoreTo: 'same',
      })
    }
  }

  const handleOpenChange = (newOpen: boolean) => {
    if (!newOpen) {
      setRestoreOption('new')
      setNewDatabaseName('')
      setNewDatabaseId('')
      setConfirmSameDbRestore(false)
    }
    onOpenChange(newOpen)
  }

  const isDisabled: boolean =
    restoreOption === 'new'
      ? !newDatabaseName.trim() ||
        Boolean(newDatabaseId && newDatabaseId === databaseId)
      : !confirmSameDbRestore

  const backupStatus = getBackupStatus(backup.status)
  const StatusIcon = backupStatus.icon

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-5 text-start">
          <DialogTitle>{t('Restore backup')}</DialogTitle>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0">
          {/* Archive snapshot details */}
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
                  {formatSize(backup.size)}
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
                    addSuffix: true})}
                </span>
              </div>
            </div>
          </div>

          {/* Restore target - options side by side */}
          <h3 className="text-[15px] font-semibold text-foreground mb-4">
            {t('Restore target')}
          </h3>
          <RadioGroup
            value={restoreOption}
            onValueChange={(value) => setRestoreOption(value as 'new' | 'same')}
            className="grid grid-cols-2 gap-3"
          >
            <Label
              htmlFor="restore-new"
              className={cn(
                'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors',
                restoreOption === 'new'
                  ? 'border-primary/50 bg-muted/50'
                  : 'border-border bg-card/50 hover:bg-muted/30',
              )}
            >
              <RadioGroupItem
                value="new"
                id="restore-new"
                className="mt-0.5 shrink-0"
              />
              <div className="min-w-0 flex-1 space-y-2">
                <span className="text-[13px] font-semibold text-foreground">
                  {t('New database')}
                </span>
                <p className="text-[12px] text-muted-foreground leading-snug">
                  {t(
                    'Create a new database from this archive; source remains unchanged.',
                  )}
                </p>
              </div>
            </Label>

            <Label
              htmlFor="restore-same"
              className={cn(
                'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors',
                restoreOption === 'same'
                  ? 'border-primary/50 bg-muted/50'
                  : 'border-border bg-card/50 hover:bg-muted/30',
              )}
            >
              <RadioGroupItem
                value="same"
                id="restore-same"
                className="mt-0.5 shrink-0"
              />
              <div className="min-w-0 flex-1 space-y-2">
                <span className="text-[13px] font-semibold text-foreground">
                  {t('Current database')}
                </span>
                <p className="text-[12px] text-muted-foreground leading-snug">
                  {t(
                    'Overwrite existing data with this backup. This cannot be undone.',
                  )}
                </p>
              </div>
            </Label>
          </RadioGroup>

          {/* Conditional: new database fields */}
          {restoreOption === 'new' && (
            <div className="mt-5 rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('New database')}
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4 space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="new-db-name" className="text-[13px]">
                    {t('Name')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="new-db-name"
                    placeholder={t('e.g. production-restore')}
                    value={newDatabaseName}
                    onChange={(e) => setNewDatabaseName(e.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="new-db-id" className="text-[13px]">
                    {t('ID')}{' '}
                    <span className="text-muted-foreground font-normal">
                      {t('(optional)')}
                    </span>
                  </Label>
                  <IdInput
                    id="new-db-id"
                    value={newDatabaseId}
                    onChange={(id) => setNewDatabaseId(id || '')}
                    placeholder={t('Auto-generated if blank')}
                  />
                  {newDatabaseId === databaseId && (
                    <p className="text-[12px] text-destructive flex items-center gap-1.5">
                      <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                      {t('Must differ from the source database ID')}
                    </p>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* Conditional: overwrite confirmation */}
          {restoreOption === 'same' && (
            <div className="mt-5 rounded-lg border border-border bg-muted/30 p-4">
              <div className="flex items-start gap-3">
                <Checkbox
                  id="confirm-same-db"
                  checked={confirmSameDbRestore}
                  onCheckedChange={(checked) =>
                    setConfirmSameDbRestore(checked === true)
                  }
                  className="mt-0.5"
                />
                <Label
                  htmlFor="confirm-same-db"
                  className="flex-1 cursor-pointer text-[13px] text-foreground"
                >
                  {t(
                    'I understand that all current database data will be permanently replaced by this backup.',
                  )}
                </Label>
              </div>
            </div>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleSubmit} disabled={isLoading || isDisabled}>
            {t('Restore')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

// Delete Policy Dialog
interface DeletePolicyDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  policy: Models.BackupPolicy
  onConfirm: () => void
  isLoading: boolean
}

function DeletePolicyDialog({
  open,
  onOpenChange,
  policy,
  onConfirm,
  isLoading}: DeletePolicyDialogProps) {
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
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription className="text-[13px]">
              {t('This will also delete all backups associated with this policy.')}
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

// Delete Backup Dialog
interface DeleteBackupDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  backup: Models.BackupArchive
  onConfirm: () => void
  isLoading: boolean
}

function DeleteBackupDialog({
  open,
  onOpenChange,
  backup,
  onConfirm,
  isLoading}: DeleteBackupDialogProps) {
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
