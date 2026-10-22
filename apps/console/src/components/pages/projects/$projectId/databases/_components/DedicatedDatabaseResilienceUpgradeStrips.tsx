import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useLocation } from '@tanstack/react-router'
import { ID, type Models } from '@appwrite.io/console'
import { AlertCircle, Minus, Plus, X } from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateDatabase } from '@/lib/console-access-checks'
import type { DedicatedReplicationSource } from '@/lib/databases/dedicated-replication'
import { isDedicatedDatabaseReady } from '@/lib/databases/dedicated-database-status'
import {
  MAX_DEDICATED_DB_HA_REPLICA_COUNT,
  MIN_DEDICATED_DB_PITR_RETENTION_DAYS,
  MAX_DEDICATED_DB_PITR_RETENTION_DAYS,
  MIN_DEDICATED_DB_BACKUP_RETENTION_DAYS,
  MAX_DEDICATED_DB_BACKUP_RETENTION_DAYS,
} from '@/lib/databases/dedicated-database-resilience'
import { useT } from '@/lib/i18n/translate'
import {
  useDismissConsoleBanner,
  useOrganizationScopes,
  useProject,
  useUpdateDedicatedDatabaseHa,
  useUpdatePostgresDatabase,
  useUpdateMysqlDatabase,
  usePostgresBackupPolicies,
  useMysqlBackupPolicies,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { isConsoleBannerDismissed, type UserPrefs } from '@/lib/user-prefs-keys'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useDatabaseAdminOperationsAccess } from './DatabaseOperationsLockContext'
import { cn } from '@/lib/utils'

export function dedicatedDatabaseHaUpgradeBannerId(databaseId: string) {
  return `dedicated-db-ha-upgrade-${databaseId}`
}

export function dedicatedDatabaseBackupUpgradeBannerId(databaseId: string) {
  return `dedicated-db-backup-upgrade-${databaseId}`
}

type NativeDedicatedEngine = 'postgresql' | 'mysql'

type DedicatedDatabaseResilienceUpgradeStripsProps = {
  projectId: string
  databaseId: string
  database: Models.DedicatedDatabase
  engine: NativeDedicatedEngine
}

function UpgradeStripShell({
  title,
  description,
  action,
  onDismiss,
  dismissDisabled,
}: {
  title: string
  description: string
  action: ReactNode
  onDismiss: () => void
  dismissDisabled?: boolean
}) {
  const t = useT()

  return (
    <div className="border-b border-border bg-amber-500/5">
      <div className="w-full px-4 py-3 sm:px-6">
        <Alert variant="default" className="border-amber-500/30 bg-transparent">
          <AlertCircle className="h-4 w-4 text-amber-500" />
          <div className="flex flex-1 items-start justify-between gap-4">
            <div className="min-w-0 flex-1">
              <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                {title}
              </AlertTitle>
              <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                {description}
              </AlertDescription>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              {action}
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8 text-amber-600 hover:bg-amber-500/10 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300"
                onClick={onDismiss}
                disabled={dismissDisabled}
                aria-label={t('Dismiss banner')}
              >
                <X className="size-4" />
              </Button>
            </div>
          </div>
        </Alert>
      </div>
    </div>
  )
}

function NumberStepper({
  value,
  min,
  max,
  disabled,
  ariaLabel,
  onChange,
}: {
  value: number
  min: number
  max: number
  disabled?: boolean
  ariaLabel: string
  onChange: (next: number) => void
}) {
  const t = useT()

  return (
    <div
      className="flex items-center gap-1.5"
      role="group"
      aria-label={ariaLabel}
    >
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="size-8 border-amber-500/30 bg-background"
        disabled={disabled || value <= min}
        onClick={() => onChange(Math.max(min, value - 1))}
        aria-label={t('Decrease')}
      >
        <Minus className="size-3.5" />
      </Button>
      <span
        className={cn(
          'flex size-8 items-center justify-center rounded-md border border-amber-500/30 bg-muted/40 text-[13px] font-semibold tabular-nums text-foreground',
        )}
        aria-live="polite"
        aria-atomic="true"
      >
        {value}
      </span>
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="size-8 border-amber-500/30 bg-background"
        disabled={disabled || value >= max}
        onClick={() => onChange(Math.min(max, value + 1))}
        aria-label={t('Increase')}
      >
        <Plus className="size-3.5" />
      </Button>
    </div>
  )
}

function ConfirmChipButton({
  label,
  disabled,
  disabledTooltip,
  pending,
  onClick,
}: {
  label: string
  disabled?: boolean
  disabledTooltip?: string
  pending?: boolean
  onClick: () => void
}) {
  const button = (
    <Button
      type="button"
      size="sm"
      className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
      disabled={disabled || pending}
      onClick={onClick}
    >
      {label}
    </Button>
  )

  if (!disabled || !disabledTooltip) {
    return button
  }

  return (
    <TooltipProvider delayDuration={200}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">{button}</span>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="max-w-xs text-[12px]">
          {disabledTooltip}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

function DedicatedDatabaseHaUpgradeStrip({
  projectId,
  databaseId,
  database,
  engine,
  dismissed,
  onDismiss,
}: {
  projectId: string
  databaseId: string
  database: Models.DedicatedDatabase
  engine: NativeDedicatedEngine
  dismissed: boolean
  onDismiss: () => void
}) {
  const t = useT()
  const { pathname } = useLocation()
  const committed = database.replicas ?? 0
  const [draftReplicas, setDraftReplicas] = useState(
    committed > 0 ? committed : 1,
  )
  const replicationSource: DedicatedReplicationSource = useMemo(
    () => ({ type: 'engine', engine }),
    [engine],
  )
  const updateMutation = useUpdateDedicatedDatabaseHa(
    projectId,
    databaseId,
    replicationSource,
  )
  const { canWrite } = useDatabaseAdminOperationsAccess()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const permissionCanWrite = canCreateDatabase(access, features)
  const writeDisabled = !canWrite || !permissionCanWrite || updateMutation.isPending
  const writeTooltip = !permissionCanWrite
    ? t("You don't have permission to change database settings.")
    : undefined

  useEffect(() => {
    setDraftReplicas(committed > 0 ? committed : 1)
  }, [committed])

  const hideOnReplicationSettings = pathname.includes('/settings/replication')
  const visible =
    !dismissed &&
    isDedicatedDatabaseReady(database.status) &&
    committed === 0 &&
    !hideOnReplicationSettings

  const handleConfirm = useCallback(() => {
    updateMutation.mutate(
      { replicas: draftReplicas, name: database.name },
      {
        onSuccess: () => {
          toast.success(t('High availability settings updated'))
          onDismiss()
        },
        onError: (error) =>
          toast.error(
            getErrorMessage(
              error,
              t('Failed to update high availability settings'),
            ),
          ),
      },
    )
  }, [
    database.name,
    draftReplicas,
    onDismiss,
    t,
    updateMutation,
  ])

  if (!visible) return null

  return (
    <UpgradeStripShell
      title={t('Add read replicas for high availability')}
      description={t(
        'This dedicated database has no read replicas. Add at least one replica to improve failover resilience and scale read traffic.',
      )}
      onDismiss={onDismiss}
      dismissDisabled={updateMutation.isPending}
      action={
        <div className="flex flex-col items-end gap-2 sm:flex-row sm:items-center">
          <NumberStepper
            value={draftReplicas}
            min={1}
            max={MAX_DEDICATED_DB_HA_REPLICA_COUNT}
            disabled={writeDisabled}
            ariaLabel={t('Replica count')}
            onChange={setDraftReplicas}
          />
          <ConfirmChipButton
            label={t('Add replicas')}
            disabled={writeDisabled || draftReplicas <= committed}
            disabledTooltip={writeTooltip}
            pending={updateMutation.isPending}
            onClick={handleConfirm}
          />
        </div>
      }
    />
  )
}

function useNativeDatabaseUpdate(
  projectId: string,
  databaseId: string,
  engine: NativeDedicatedEngine,
) {
  const postgres = useUpdatePostgresDatabase(projectId, databaseId)
  const mysql = useUpdateMysqlDatabase(projectId, databaseId)
  return engine === 'postgresql' ? postgres : mysql
}

function DedicatedDatabaseBackupUpgradeStrip({
  projectId,
  databaseId,
  database,
  engine,
  dismissed,
  onDismiss,
}: {
  projectId: string
  databaseId: string
  database: Models.DedicatedDatabase
  engine: NativeDedicatedEngine
  dismissed: boolean
  onDismiss: () => void
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const { pathname } = useLocation()
  const { features } = useConsoleProfile()
  const pitrEnabled = database.pitr === true
  const postgresPolicies = usePostgresBackupPolicies(
    engine === 'postgresql' ? projectId : null,
    engine === 'postgresql' ? databaseId : null,
  )
  const mysqlPolicies = useMysqlBackupPolicies(
    engine === 'mysql' ? projectId : null,
    engine === 'mysql' ? databaseId : null,
  )
  const policies =
    engine === 'postgresql'
      ? (postgresPolicies.data?.policies ?? [])
      : (mysqlPolicies.data?.policies ?? [])
  const policiesLoading =
    engine === 'postgresql'
      ? postgresPolicies.isLoading
      : mysqlPolicies.isLoading

  const [pitrRetentionDays, setPitrRetentionDays] = useState(7)
  const [backupRetentionDays, setBackupRetentionDays] = useState(7)

  const updateDatabase = useNativeDatabaseUpdate(projectId, databaseId, engine)
  const { canWrite } = useDatabaseAdminOperationsAccess()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const permissionCanWrite = canCreateDatabase(access, features)
  const writeDisabled =
    !canWrite || !permissionCanWrite || updateDatabase.isPending
  const writeTooltip = !permissionCanWrite
    ? t("You don't have permission to change database settings.")
    : undefined

  const createPolicyMutation = useMutation({
    mutationFn: async (retention: number) => {
      const projectSdk = sdk.forProject(projectId)
      const name = t('Daily backup')
      const payload = {
        databaseId,
        policyId: ID.unique(),
        name,
        schedule: '0 3 * * *',
        retention,
        enabled: true,
      }
      if (engine === 'postgresql') {
        return projectSdk.postgresql.createBackupPolicy(payload)
      }
      return projectSdk.mysql.createBackupPolicy(payload)
    },
    onSuccess: async () => {
      toast.success(t('Backup policy has been created'))
      await queryClient.invalidateQueries({
        queryKey: [
          'dedicated-backup-policies',
          'project',
          projectId,
          databaseId,
        ],
      })
      onDismiss()
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error, t('Failed to create backup policy')),
      )
    },
  })

  const hideOnBackupRoutes =
    pathname.includes('/backups') || pathname.includes('/settings/pitr')
  const needsPitr = !pitrEnabled
  const needsBackupPolicy = policies.length === 0
  const visible =
    features.databaseBackups &&
    !dismissed &&
    isDedicatedDatabaseReady(database.status) &&
    !hideOnBackupRoutes &&
    !policiesLoading &&
    (needsPitr || needsBackupPolicy)

  const handleConfirm = useCallback(() => {
    if (needsPitr) {
      updateDatabase.mutate(
        {
          pitr: true,
          pitrRetentionDays,
        },
        {
          onSuccess: () => {
            toast.success(t('PITR settings updated'))
            if (!needsBackupPolicy) {
              onDismiss()
            }
          },
          onError: (error) =>
            toast.error(
              getErrorMessage(error, t('Failed to update PITR settings')),
            ),
        },
      )
      return
    }

    createPolicyMutation.mutate(backupRetentionDays)
  }, [
    backupRetentionDays,
    createPolicyMutation,
    needsBackupPolicy,
    needsPitr,
    onDismiss,
    pitrRetentionDays,
    t,
    updateDatabase,
  ])

  const pending = updateDatabase.isPending || createPolicyMutation.isPending
  const stepperValue = needsPitr ? pitrRetentionDays : backupRetentionDays
  const stepperMin = needsPitr
    ? MIN_DEDICATED_DB_PITR_RETENTION_DAYS
    : MIN_DEDICATED_DB_BACKUP_RETENTION_DAYS
  const stepperMax = needsPitr
    ? MAX_DEDICATED_DB_PITR_RETENTION_DAYS
    : MAX_DEDICATED_DB_BACKUP_RETENTION_DAYS
  const stepperLabel = needsPitr
    ? t('PITR retention (days)')
    : t('Backup retention (days)')

  const title = needsPitr
    ? t('Enable point-in-time recovery')
    : t('Add a scheduled backup policy')
  const description = needsPitr
    ? t(
        'Point-in-time recovery is off for this database. Turn it on to restore to any moment within the retention window.',
      )
    : t(
        'This database has no backup policies. Add a daily backup schedule to protect against data loss.',
      )
  const confirmLabel = needsPitr ? t('Enable PITR') : t('Add daily backup')

  if (!visible) return null

  return (
    <UpgradeStripShell
      title={title}
      description={description}
      onDismiss={onDismiss}
      dismissDisabled={pending}
      action={
        <div className="flex flex-col items-end gap-2 sm:flex-row sm:items-center">
          <NumberStepper
            value={stepperValue}
            min={stepperMin}
            max={stepperMax}
            disabled={writeDisabled || pending}
            ariaLabel={stepperLabel}
            onChange={
              needsPitr ? setPitrRetentionDays : setBackupRetentionDays
            }
          />
          <ConfirmChipButton
            label={confirmLabel}
            disabled={writeDisabled}
            disabledTooltip={writeTooltip}
            pending={pending}
            onClick={handleConfirm}
          />
        </div>
      }
    />
  )
}

export function DedicatedDatabaseResilienceUpgradeStrips({
  projectId,
  databaseId,
  database,
  engine,
}: DedicatedDatabaseResilienceUpgradeStripsProps) {
  const { account } = useAuth()
  const dismissBanner = useDismissConsoleBanner()
  const haBannerId = dedicatedDatabaseHaUpgradeBannerId(databaseId)
  const backupBannerId = dedicatedDatabaseBackupUpgradeBannerId(databaseId)
  const prefs = (account as { prefs?: UserPrefs } | null | undefined)?.prefs
  const haDismissed = isConsoleBannerDismissed(prefs, haBannerId)
  const backupDismissed = isConsoleBannerDismissed(prefs, backupBannerId)

  const dismissHa = useCallback(() => {
    dismissBanner.mutate(haBannerId)
  }, [dismissBanner, haBannerId])

  const dismissBackup = useCallback(() => {
    dismissBanner.mutate(backupBannerId)
  }, [backupBannerId, dismissBanner])

  return (
    <>
      <DedicatedDatabaseHaUpgradeStrip
        projectId={projectId}
        databaseId={databaseId}
        database={database}
        engine={engine}
        dismissed={haDismissed}
        onDismiss={dismissHa}
      />
      <DedicatedDatabaseBackupUpgradeStrip
        projectId={projectId}
        databaseId={databaseId}
        database={database}
        engine={engine}
        dismissed={backupDismissed}
        onDismiss={dismissBackup}
      />
    </>
  )
}
