import { useCallback, useEffect, useMemo, useState } from 'react'
import { endOfDay, startOfDay, subDays } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { Info, Minus, Plus, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Progress } from '@/components/ui/progress'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'
import {
  calculateDedicatedDatabaseMonthlyCost,
  DEDICATED_DB_HA_REPLICA_OPTIONS,
  getDedicatedDatabaseCreatePricing,
  MAX_DEDICATED_DB_HA_REPLICA_COUNT,
} from '@/lib/database-create-pricing'
import {
  mapDedicatedDatabaseSpecifications,
  parseDatabaseMaxConnections,
} from '@/lib/database-specs'
import { isMysqlClientBackend } from '@/lib/mysql-metrics'
import {
  MYSQL_DATABASE_SPECS_SOURCE,
  useDatabaseSpecifications,
  useDedicatedDatabaseCardMetrics,
  useDedicatedDatabaseStorageChart,
  useOrganizationPlan,
  useMysqlActiveConnections,
  useDedicatedDatabaseReplicas,
  useProject,
  useUpdateDedicatedDatabaseHa,
  useUpdateMysqlDatabase,
} from '@/lib/react-query/hooks'
import { isDedicatedDatabaseReady } from '@/lib/databases/dedicated-database-status'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { DedicatedReplicationSource } from '@/lib/databases/dedicated-replication'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { getDedicatedDatabaseGaugeHeadline } from '@/lib/usage/dedicated-databases-usage'
import { formatCompactBytes } from '@/lib/usage/format-metric'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { MysqlReplicationSyncModePicker } from './MysqlReplicationSyncModePicker'
import {
  DatabaseClusterPreview,
  clusterNodeStatusesFromMembers,
  clusterReplicaChangePreview,
  resolveClusterProxyLabel,
} from '../../_components/DatabaseClusterPreview'
import type { MysqlDatabaseSettingsCardProps } from './mysql-database-settings-types'

function getDefaultStorageUsageDateRange(): DateRange {
  return {
    from: startOfDay(subDays(new Date(), 1)),
    to: endOfDay(new Date()),
  }
}

function getStorageUsageTone(
  percentage: number | null,
): 'normal' | 'warning' | 'critical' {
  if (percentage == null) return 'normal'
  if (percentage >= 90) return 'critical'
  if (percentage >= 75) return 'warning'
  return 'normal'
}

function getReplicaOption(count: number) {
  return (
    DEDICATED_DB_HA_REPLICA_OPTIONS.find((option) => option.count === count) ??
    DEDICATED_DB_HA_REPLICA_OPTIONS[0]
  )
}

function useWriteAccess(canWrite: boolean, isPending: boolean) {
  const t = useT()
  const writeDisabled = !canWrite || isPending
  const writeTooltip = !canWrite
    ? t("You don't have permission to change database settings.")
    : undefined
  return { writeDisabled, writeTooltip }
}

function useReplicationSource(
  props: MysqlDatabaseSettingsCardProps,
): DedicatedReplicationSource {
  return (
    props.replicationSource ?? {
      type: 'engine',
      engine: props.haEngine || props.database.engine || 'mysql',
    }
  )
}

function useHaEngine(props: MysqlDatabaseSettingsCardProps): string {
  return (
    props.haEngine ||
    props.database.engine ||
    (props.replicationSource?.type === 'engine'
      ? props.replicationSource.engine
      : 'mysql')
  )
}

export function MysqlDatabaseReplicasCard({
  projectId,
  databaseId,
  database,
  canWrite,
  replicationSource,
  haEngine,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const source = useReplicationSource({
    projectId,
    databaseId,
    database,
    canWrite,
    replicationSource,
    haEngine,
  })
  const engine = useHaEngine({
    projectId,
    databaseId,
    database,
    canWrite,
    replicationSource,
    haEngine,
  })
  const updateMutation = useUpdateDedicatedDatabaseHa(
    projectId,
    databaseId,
    source,
  )
  // Specs come from the owning product/engine service (not a shared mysql list).
  const { data: specificationsData } = useDatabaseSpecifications(
    projectId,
    source,
  )
  const isMysqlEngine =
    engine === 'mysql' || engine === 'mysql' || !engine
  const { connections, isLoading: connectionsLoading, refetch: refetchConnections } =
    useMysqlActiveConnections(
      projectId,
      isMysqlEngine ? databaseId : null,
    )
  const [replicaCount, setReplicaCount] = useState(database.replicas ?? 0)
  const committedReplicaCount = database.replicas ?? 0
  const fetchReplicas =
    committedReplicaCount > 0 || replicaCount > 0 || updateMutation.isPending
  const { members, refetch: refetchReplicas } =
    useDedicatedDatabaseReplicas(
      projectId,
      databaseId,
      source,
      fetchReplicas,
      fetchReplicas ? 5000 : false,
    )
  const { writeDisabled, writeTooltip } = useWriteAccess(
    canWrite,
    updateMutation.isPending,
  )

  useEffect(() => {
    setReplicaCount(database.replicas ?? 0)
  }, [database.replicas])

  const replicaOption = useMemo(
    () => getReplicaOption(replicaCount),
    [replicaCount],
  )

  const maxConnections = useMemo(() => {
    const specs = mapDedicatedDatabaseSpecifications(
      specificationsData?.specifications,
    )
    const currentSpec = specs.find((spec) => spec.id === database.specification)
    return parseDatabaseMaxConnections(currentSpec?.connections)
  }, [database.specification, specificationsData?.specifications])

  const currentConnections = useMemo(
    () => connections.filter(isMysqlClientBackend).length,
    [connections],
  )

  const memberReplicaCount = useMemo(
    () =>
      members.filter(
        (member) =>
          String(member.role ?? '')
            .trim()
            .toLowerCase() !== 'primary',
      ).length,
    [members],
  )

  // Keep still-present pods in the diagram while a removal catches up, and
  // while the draft count is below the live cluster size before Update.
  const presentReplicaCount = Math.max(
    committedReplicaCount,
    memberReplicaCount,
  )

  const [topologyRefreshing, setTopologyRefreshing] = useState(false)

  const {
    nodeMetrics,
    refetch: refetchMetrics,
  } = useDedicatedDatabaseCardMetrics(
    projectId,
    databaseId,
    presentReplicaCount,
    features.usageStats,
  )

  const handleTopologyRefresh = useCallback(() => {
    setTopologyRefreshing(true)
    void Promise.all([
      refetchReplicas(),
      refetchConnections(),
      refetchMetrics(),
    ]).finally(() => {
      setTopologyRefreshing(false)
    })
  }, [refetchConnections, refetchMetrics, refetchReplicas])

  const memberStatuses = useMemo(
    () =>
      clusterNodeStatusesFromMembers(
        members,
        presentReplicaCount,
        isDedicatedDatabaseReady(database.status) ? 'active' : database.status,
      ),
    [database.status, members, presentReplicaCount],
  )

  const clusterProxy = useMemo(
    () => ({
      label: resolveClusterProxyLabel({
        engine: database.engine || 'mysql',
        api: database.api,
        t,
      }),
      connections: {
        current: connectionsLoading ? null : currentConnections,
        max: maxConnections,
      },
      status: memberStatuses[0] ?? 'active',
    }),
    [
      connectionsLoading,
      currentConnections,
      database.api,
      database.engine,
      maxConnections,
      memberStatuses,
      t,
    ],
  )

  const replicasDirty = replicaCount !== committedReplicaCount
  const clusterPreview = useMemo(
    () =>
      clusterReplicaChangePreview(
        database.status,
        presentReplicaCount,
        replicaCount,
        memberStatuses,
      ),
    [database.status, memberStatuses, presentReplicaCount, replicaCount],
  )

  const handleReplicasUpdate = () => {
    updateMutation.mutate(
      { replicas: replicaCount, name: database.name },
      {
        onSuccess: () => toast.success(t('High availability settings updated')),
        onError: (error) =>
          toast.error(
            getErrorMessage(
              error,
              t('Failed to update high availability settings'),
            ),
          ),
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Read replicas')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Add read-only instances to scale query traffic and improve failover resilience alongside your primary database.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-6">
        <div className="flex items-start justify-between gap-6">
          <div className="min-w-0 flex-1 space-y-2">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              <Label className="text-[13px] font-medium text-foreground">
                {t('Replica count')}
              </Label>
              <span className="text-[12px] text-muted-foreground">
                {t(replicaOption.label)} · 0–{MAX_DEDICATED_DB_HA_REPLICA_COUNT}
              </span>
            </div>
            <p className="text-[13px] leading-relaxed text-muted-foreground">
              {t(replicaOption.description)}
            </p>
          </div>
          <div
            className="flex shrink-0 items-center gap-1.5"
            role="group"
            aria-label={t('Replica count')}
          >
            <Button
              type="button"
              variant="outline"
              size="icon"
              className="size-8"
              disabled={writeDisabled || replicaCount <= 0}
              onClick={() => setReplicaCount((count) => Math.max(0, count - 1))}
            >
              <Minus className="size-3.5" />
            </Button>
            <span className="flex size-8 items-center justify-center rounded-md border border-border bg-muted/40 text-[13px] font-semibold tabular-nums text-foreground">
              {replicaCount}
            </span>
            <Button
              type="button"
              variant="outline"
              size="icon"
              className="size-8"
              disabled={
                writeDisabled ||
                replicaCount >= MAX_DEDICATED_DB_HA_REPLICA_COUNT
              }
              onClick={() =>
                setReplicaCount((count) =>
                  Math.min(MAX_DEDICATED_DB_HA_REPLICA_COUNT, count + 1),
                )
              }
            >
              <Plus className="size-3.5" />
            </Button>
          </div>
        </div>

        {replicaCount >= MAX_DEDICATED_DB_HA_REPLICA_COUNT ? (
          <div className="flex flex-col gap-3 rounded-lg border border-border bg-muted/30 px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-[12px] leading-relaxed text-muted-foreground">
              {t(
                'You have reached the maximum self-serve replica count. Contact sales if you need a custom high availability configuration.',
              )}
            </p>
            <Button
              variant="outline"
              size="sm"
              className="h-8 shrink-0 text-[13px]"
              asChild
            >
              <a
                href={CONTACT_ENTERPRISE_URL}
                target="_blank"
                rel="noopener noreferrer"
              >
                {t('Contact sales')}
              </a>
            </Button>
          </div>
        ) : null}

        <div className="space-y-3">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Cluster topology')}
          </p>
          <div className="overflow-hidden rounded-lg border border-border">
            <DatabaseClusterPreview
              replicaCount={clusterPreview.displayReplicaCount}
              nodeStatuses={clusterPreview.nodeStatuses}
              nodeMetrics={nodeMetrics}
              proxy={clusterProxy}
              withSectionDivider={false}
              interactive
              onRefresh={handleTopologyRefresh}
              isRefreshing={topologyRefreshing}
            />
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={writeDisabled || !replicasDirty}
          title={writeTooltip}
          onClick={handleReplicasUpdate}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function MysqlDatabaseSyncModeCard({
  projectId,
  databaseId,
  database,
  canWrite,
  replicationSource,
  haEngine,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const source = useReplicationSource({
    projectId,
    databaseId,
    database,
    canWrite,
    replicationSource,
    haEngine,
  })
  const updateMutation = useUpdateDedicatedDatabaseHa(
    projectId,
    databaseId,
    source,
  )
  const [syncMode, setSyncMode] = useState(database.syncMode || 'async')
  const { writeDisabled, writeTooltip } = useWriteAccess(
    canWrite,
    updateMutation.isPending,
  )

  useEffect(() => {
    setSyncMode(database.syncMode || 'async')
  }, [database.syncMode])

  const syncModeDirty = syncMode !== (database.syncMode || 'async')

  const handleSyncModeUpdate = () => {
    updateMutation.mutate(
      { syncMode, name: database.name },
      {
        onSuccess: () => toast.success(t('High availability settings updated')),
        onError: (error) =>
          toast.error(
            getErrorMessage(
              error,
              t('Failed to update high availability settings'),
            ),
          ),
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Sync mode')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t('Choose how the primary confirms writes with read replicas.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <MysqlReplicationSyncModePicker
        syncMode={syncMode}
        onSyncModeChange={setSyncMode}
        disabled={writeDisabled}
      />
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={writeDisabled || !syncModeDirty}
          title={writeTooltip}
          onClick={handleSyncModeUpdate}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function MysqlDatabaseNetworkCard({
  projectId,
  databaseId,
  database,
  canWrite,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const updateMutation = useUpdateMysqlDatabase(projectId, databaseId)
  const [idleTimeoutSeconds, setIdleTimeoutSeconds] = useState(
    String(database.networkIdleTimeoutSeconds ?? ''),
  )
  const [ipAllowlist, setIpAllowlist] = useState<string[]>(
    database.networkIPAllowlist ?? [],
  )
  const [ipInput, setIpInput] = useState('')
  const { writeDisabled, writeTooltip } = useWriteAccess(
    canWrite,
    updateMutation.isPending,
  )

  useEffect(() => {
    setIdleTimeoutSeconds(String(database.networkIdleTimeoutSeconds ?? ''))
    setIpAllowlist(database.networkIPAllowlist ?? [])
  }, [database.networkIdleTimeoutSeconds, database.networkIPAllowlist])

  const networkDirty =
    idleTimeoutSeconds !== String(database.networkIdleTimeoutSeconds ?? '') ||
    JSON.stringify(ipAllowlist) !==
      JSON.stringify(database.networkIPAllowlist ?? [])

  const addIpAddress = () => {
    const value = ipInput.trim()
    if (!value || ipAllowlist.includes(value)) return
    setIpAllowlist((prev) => [...prev, value])
    setIpInput('')
  }

  const removeIpAddress = (value: string) => {
    setIpAllowlist((prev) => prev.filter((entry) => entry !== value))
  }

  const handleNetworkUpdate = () => {
    const parsedIdleTimeout = Number.parseInt(idleTimeoutSeconds, 10)
    updateMutation.mutate(
      {
        networkIdleTimeoutSeconds: Number.isFinite(parsedIdleTimeout)
          ? parsedIdleTimeout
          : undefined,
        networkIPAllowlist: ipAllowlist,
      },
      {
        onSuccess: () => toast.success(t('Network settings updated')),
        onError: (error) =>
          toast.error(
            getErrorMessage(error, t('Failed to update network settings')),
          ),
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Network')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Configure connection idle timeout and restrict access to specific IP addresses or CIDR ranges.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="space-y-2">
          <Label htmlFor="network-idle-timeout" className="text-[13px]">
            {t('Connection idle timeout (seconds)')}
          </Label>
          <Input
            id="network-idle-timeout"
            type="number"
            min={60}
            max={86400}
            value={idleTimeoutSeconds}
            onChange={(e) => setIdleTimeoutSeconds(e.target.value)}
            disabled={writeDisabled}
            className="h-9 max-w-xs text-[13px]"
          />
        </div>

        <div className="space-y-2">
          <Label className="text-[13px]">{t('IP allowlist')}</Label>
          <p className="text-[12px] text-muted-foreground">
            {t('Leave empty to allow connections from any IP address.')}
          </p>
          <div className="flex flex-wrap gap-2">
            {ipAllowlist.map((entry) => (
              <span
                key={entry}
                className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/40 px-2 py-1 text-[12px] font-mono text-foreground"
              >
                {entry}
                {canWrite ? (
                  <button
                    type="button"
                    className="text-muted-foreground hover:text-foreground"
                    onClick={() => removeIpAddress(entry)}
                    aria-label={t('Remove IP address')}
                  >
                    <X className="h-3 w-3" />
                  </button>
                ) : null}
              </span>
            ))}
          </div>
          {canWrite ? (
            <div className="flex max-w-md gap-2">
              <Input
                value={ipInput}
                onChange={(e) => setIpInput(e.target.value)}
                placeholder={t('192.168.0.0/24')}
                className="h-9 text-[13px] font-mono"
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    addIpAddress()
                  }
                }}
              />
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 shrink-0 text-[13px]"
                onClick={addIpAddress}
                disabled={!ipInput.trim()}
              >
                {t('Add')}
              </Button>
            </div>
          ) : null}
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={writeDisabled || !networkDirty}
          title={writeTooltip}
          onClick={handleNetworkUpdate}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function MysqlDatabasePitrCard({
  projectId,
  databaseId,
  database,
  canWrite,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { data: specificationsData } = useDatabaseSpecifications(projectId, MYSQL_DATABASE_SPECS_SOURCE)
  const updateMutation = useUpdateMysqlDatabase(projectId, databaseId)
  const [pitrEnabled, setPitrEnabled] = useState(database.pitr === true)
  const [pitrRetentionDays, setPitrRetentionDays] = useState(
    String(database.pitrRetentionDays ?? ''),
  )
  const { writeDisabled, writeTooltip } = useWriteAccess(
    canWrite,
    updateMutation.isPending,
  )

  const pricing = useMemo(
    () =>
      getDedicatedDatabaseCreatePricing(
        organizationPlan,
        specificationsData?.pricing ?? null,
      ),
    [organizationPlan, specificationsData?.pricing],
  )

  const basePriceUsd = useMemo(() => {
    const specs = mapDedicatedDatabaseSpecifications(
      specificationsData?.specifications,
    )
    return (
      specs.find((spec) => spec.id === database.specification)?.priceUsd ?? 0
    )
  }, [database.specification, specificationsData?.specifications])

  const pitrCost = calculateDedicatedDatabaseMonthlyCost({
    basePriceUsd,
    replicaCount: 0,
    pitrEnabled: true,
    pricing,
  }).pitrUsd
  const pitrRatePercent = Math.round(pricing.pitrRate * 100)

  const showPitrChargeAlert =
    features.billing && database.pitr !== true && pricing.pitrRate > 0

  useEffect(() => {
    setPitrEnabled(database.pitr === true)
    setPitrRetentionDays(String(database.pitrRetentionDays ?? ''))
  }, [database.pitr, database.pitrRetentionDays])

  const pitrDirty =
    pitrEnabled !== (database.pitr === true) ||
    pitrRetentionDays !== String(database.pitrRetentionDays ?? '')

  const handlePitrUpdate = () => {
    const parsedPitrRetention = Number.parseInt(pitrRetentionDays, 10)
    updateMutation.mutate(
      {
        pitr: pitrEnabled,
        pitrRetentionDays: Number.isFinite(parsedPitrRetention)
          ? parsedPitrRetention
          : undefined,
      },
      {
        onSuccess: () => toast.success(t('PITR settings updated')),
        onError: (error) =>
          toast.error(
            getErrorMessage(error, t('Failed to update PITR settings')),
          ),
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Point-in-time recovery (PITR)')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Restore this database to any moment within the retention window.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex items-center justify-between gap-4">
          <Label htmlFor="pitr-enabled" className="text-[13px]">
            {t('Enable PITR')}
          </Label>
          <Switch
            id="pitr-enabled"
            checked={pitrEnabled}
            onCheckedChange={setPitrEnabled}
            disabled={writeDisabled}
          />
        </div>

        {pitrEnabled ? (
          <div className="space-y-2">
            <Label htmlFor="pitr-retention" className="text-[13px]">
              {t('PITR retention (days)')}
            </Label>
            <p className="text-[12px] text-muted-foreground">
              {t(
                'Sets how far back you can restore. Recovery points older than this period are deleted, so choose a window that covers how long data issues may go unnoticed.',
              )}
            </p>
            <Input
              id="pitr-retention"
              type="number"
              min={1}
              value={pitrRetentionDays}
              onChange={(e) => setPitrRetentionDays(e.target.value)}
              disabled={writeDisabled}
              className="h-9 max-w-xs text-[13px]"
            />
          </div>
        ) : null}

        {showPitrChargeAlert ? (
          <Alert>
            <Info className="h-4 w-4" />
            <AlertDescription className="text-[12px]">
              <p>
                {t('Enabling PITR will incur an additional charge of')}{' '}
                <span className="font-medium text-foreground">
                  {formatCurrency(pitrCost, 'USD')} {t('per month')}
                </span>{' '}
                ({pitrRatePercent}% {t('of your database price')}).
              </p>
            </AlertDescription>
          </Alert>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={writeDisabled || !pitrDirty}
          title={writeTooltip}
          onClick={handlePitrUpdate}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function MysqlDatabaseStorageCard({
  projectId,
  databaseId,
  database,
  canWrite,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const updateMutation = useUpdateMysqlDatabase(projectId, databaseId)
  const { data: specificationsData, isLoading: specificationsLoading } =
    useDatabaseSpecifications(projectId, MYSQL_DATABASE_SPECS_SOURCE)
  const storageDateRange = useMemo(() => getDefaultStorageUsageDateRange(), [])
  const storageUsageQuery = useDedicatedDatabaseStorageChart(
    projectId,
    databaseId,
    storageDateRange,
    true,
    DEFAULT_USAGE_CHART_INTERVAL,
  )
  const [storageAutoscaling, setStorageAutoscaling] = useState(
    database.storageAutoscaling === true,
  )
  const [autoscalingThreshold, setAutoscalingThreshold] = useState(
    String(database.storageAutoscalingThresholdPercent ?? ''),
  )
  const [autoscalingMaxGb, setAutoscalingMaxGb] = useState(
    String(database.storageAutoscalingMaxGb ?? ''),
  )
  const { writeDisabled, writeTooltip } = useWriteAccess(
    canWrite,
    updateMutation.isPending,
  )

  useEffect(() => {
    setStorageAutoscaling(database.storageAutoscaling === true)
    setAutoscalingThreshold(
      String(database.storageAutoscalingThresholdPercent ?? ''),
    )
    setAutoscalingMaxGb(String(database.storageAutoscalingMaxGb ?? ''))
  }, [
    database.storageAutoscaling,
    database.storageAutoscalingThresholdPercent,
    database.storageAutoscalingMaxGb,
  ])

  const storageDirty =
    storageAutoscaling !== (database.storageAutoscaling === true) ||
    autoscalingThreshold !==
      String(database.storageAutoscalingThresholdPercent ?? '') ||
    autoscalingMaxGb !== String(database.storageAutoscalingMaxGb ?? '')

  const storageLimitGb = useMemo(() => {
    if (database.storage && database.storage > 0) {
      return database.storage
    }
    const rawSpec = specificationsData?.specifications?.find(
      (spec) => spec.slug === database.specification,
    )
    if (rawSpec?.includedStorage && rawSpec.includedStorage > 0) {
      return rawSpec.includedStorage
    }
    return null
  }, [database.specification, database.storage, specificationsData?.specifications])

  const storageLimitBytes = useMemo(() => {
    if (storageLimitGb == null || storageLimitGb <= 0) return null
    return storageLimitGb * 1_000_000_000
  }, [storageLimitGb])

  const storagePoints = storageUsageQuery.isError
    ? []
    : (storageUsageQuery.data?.chartPoints ?? [])
  const storageUsedBytes =
    storagePoints.length > 0
      ? getDedicatedDatabaseGaugeHeadline(storagePoints)
      : null

  const storageUsagePercent = useMemo(() => {
    if (
      storageUsedBytes == null ||
      storageLimitBytes == null ||
      storageLimitBytes <= 0
    ) {
      return null
    }
    return (storageUsedBytes / storageLimitBytes) * 100
  }, [storageUsedBytes, storageLimitBytes])

  const storageUsageTone = getStorageUsageTone(storageUsagePercent)

  const awaitingStorageLimit =
    storageLimitBytes == null && specificationsLoading
  const awaitingUsage =
    storageLimitBytes != null &&
    storageUsageQuery.isLoading &&
    storageUsedBytes == null
  const storageUsageLoading = awaitingStorageLimit || awaitingUsage
  const showStorageProgress = storageLimitBytes != null || awaitingStorageLimit

  const handleStorageUpdate = () => {
    const parsedThreshold = Number.parseInt(autoscalingThreshold, 10)
    const parsedMaxGb = Number.parseInt(autoscalingMaxGb, 10)
    updateMutation.mutate(
      {
        storageAutoscaling,
        storageAutoscalingThresholdPercent: Number.isFinite(parsedThreshold)
          ? parsedThreshold
          : undefined,
        storageAutoscalingMaxGb: Number.isFinite(parsedMaxGb)
          ? parsedMaxGb
          : undefined,
      },
      {
        onSuccess: () => toast.success(t('Storage settings updated')),
        onError: (error) =>
          toast.error(
            getErrorMessage(error, t('Failed to update storage settings')),
          ),
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Storage')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Configure automatic storage expansion when disk usage reaches a threshold.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-3">
        <div className="flex items-start justify-between gap-2">
          <Label className="text-[13px]">{t('Storage used')}</Label>
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className="text-muted-foreground transition-colors hover:text-foreground"
                >
                  <Info className="h-3.5 w-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent
                side="top"
                className="max-w-xs text-[12px] leading-relaxed"
              >
                <p>
                  {t(
                    'Storage used by this database instance compared to provisioned capacity.',
                  )}
                </p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </div>
        <div className="flex min-h-[18px] items-baseline gap-2">
          {storageUsageLoading ? (
            <>
              <Skeleton className="h-[18px] w-14 shrink-0 rounded-sm" />
              <Skeleton className="h-3 w-40 max-w-full shrink-0 rounded-sm" />
            </>
          ) : (
            <>
              <span className="text-[15px] font-semibold tabular-nums text-foreground">
                {storageUsedBytes != null
                  ? formatCompactBytes(storageUsedBytes)
                  : '0B'}
              </span>
              {storageLimitBytes != null ? (
                <span className="text-[12px] text-muted-foreground">
                  {`/ ${formatCompactBytes(storageLimitBytes)} available${
                    storageUsagePercent != null
                      ? ` · ${storageUsagePercent.toFixed(1)}%`
                      : ''
                  }`}
                </span>
              ) : null}
            </>
          )}
        </div>
        {showStorageProgress ? (
          storageUsageLoading ? (
            <Skeleton className="h-1.5 w-full rounded-full" />
          ) : storageUsagePercent != null ? (
            <Progress
              value={Math.min(100, Math.max(0, storageUsagePercent))}
              className={cn(
                'h-1.5',
                storageUsageTone === 'critical' &&
                  '[&_[data-slot=progress-indicator]]:bg-red-500',
                storageUsageTone === 'warning' &&
                  '[&_[data-slot=progress-indicator]]:bg-amber-500',
                storageUsageTone === 'normal' &&
                  'bg-[var(--chart-brand)]/15 [&_[data-slot=progress-indicator]]:bg-[var(--chart-brand)]',
              )}
            />
          ) : (
            <div className="h-1.5" />
          )
        ) : null}
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex items-center justify-between gap-4">
          <Label htmlFor="storage-autoscaling" className="text-[13px]">
            {t('Storage autoscaling')}
          </Label>
          <Switch
            id="storage-autoscaling"
            checked={storageAutoscaling}
            onCheckedChange={setStorageAutoscaling}
            disabled={writeDisabled}
          />
        </div>

        {storageAutoscaling ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="autoscaling-threshold" className="text-[13px]">
                {t('Autoscaling threshold (%)')}
              </Label>
              <Input
                id="autoscaling-threshold"
                type="number"
                min={50}
                max={95}
                value={autoscalingThreshold}
                onChange={(e) => setAutoscalingThreshold(e.target.value)}
                disabled={writeDisabled}
                className="h-9 text-[13px]"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="autoscaling-max-gb" className="text-[13px]">
                {t('Autoscaling max (GB)')}
              </Label>
              <Input
                id="autoscaling-max-gb"
                type="number"
                min={0}
                value={autoscalingMaxGb}
                onChange={(e) => setAutoscalingMaxGb(e.target.value)}
                disabled={writeDisabled}
                className="h-9 text-[13px]"
              />
            </div>
          </div>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={writeDisabled || !storageDirty}
          title={writeTooltip}
          onClick={handleStorageUpdate}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
