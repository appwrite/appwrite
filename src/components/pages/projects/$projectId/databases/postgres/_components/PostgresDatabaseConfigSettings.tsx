import { useEffect, useMemo, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { Minus, Plus, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'
import {
  DEDICATED_DB_HA_REPLICA_OPTIONS,
  MAX_DEDICATED_DB_HA_REPLICA_COUNT,
} from '@/lib/database-create-pricing'
import {
  usePostgresDatabasePooler,
  useUpdatePostgresDatabase,
  useUpdatePostgresDatabaseMaintenance,
  useUpdatePostgresDatabasePooler,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { PostgresReplicationSyncModePicker } from './PostgresReplicationSyncModePicker'

type PostgresDatabaseConfigSettingsProps = {
  projectId: string
  databaseId: string
  database: Models.DedicatedDatabase
  canWrite: boolean
}

const MAINTENANCE_DAYS = [
  { value: 'sun', label: 'Sunday' },
  { value: 'mon', label: 'Monday' },
  { value: 'tue', label: 'Tuesday' },
  { value: 'wed', label: 'Wednesday' },
  { value: 'thu', label: 'Thursday' },
  { value: 'fri', label: 'Friday' },
  { value: 'sat', label: 'Saturday' },
] as const

const POOLER_MODE_OPTIONS = [
  { value: 'transaction', label: 'Transaction' },
  { value: 'session', label: 'Session' },
] as const

function getReplicaOption(count: number) {
  return (
    DEDICATED_DB_HA_REPLICA_OPTIONS.find((option) => option.count === count) ??
    DEDICATED_DB_HA_REPLICA_OPTIONS[0]
  )
}

export function PostgresDatabaseConfigSettings({
  projectId,
  databaseId,
  database,
  canWrite,
}: PostgresDatabaseConfigSettingsProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const updateMutation = useUpdatePostgresDatabase(projectId, databaseId)
  const maintenanceMutation = useUpdatePostgresDatabaseMaintenance(
    projectId,
    databaseId,
  )
  const poolerMutation = useUpdatePostgresDatabasePooler(projectId, databaseId)
  const { pooler } = usePostgresDatabasePooler(projectId, databaseId)

  const [replicaCount, setReplicaCount] = useState(database.replicas ?? 0)
  const [syncMode, setSyncMode] = useState(database.syncMode || 'async')
  const [idleTimeoutSeconds, setIdleTimeoutSeconds] = useState(
    String(database.networkIdleTimeoutSeconds ?? ''),
  )
  const [ipAllowlist, setIpAllowlist] = useState<string[]>(
    database.networkIPAllowlist ?? [],
  )
  const [ipInput, setIpInput] = useState('')
  const [pitrEnabled, setPitrEnabled] = useState(database.pitr === true)
  const [pitrRetentionDays, setPitrRetentionDays] = useState(
    String(database.pitrRetentionDays ?? ''),
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
  const [maintenanceDay, setMaintenanceDay] = useState(
    database.maintenanceWindowDay || 'sun',
  )
  const [maintenanceHourUtc, setMaintenanceHourUtc] = useState(
    String(database.maintenanceWindowHourUtc ?? 0),
  )
  const [poolerMode, setPoolerMode] = useState(pooler?.mode || 'transaction')
  const [poolerMaxConnections, setPoolerMaxConnections] = useState(
    String(pooler?.maxConnections ?? ''),
  )
  const [poolerDefaultPoolSize, setPoolerDefaultPoolSize] = useState(
    String(pooler?.defaultPoolSize ?? ''),
  )
  const [poolerReadWriteSplitting, setPoolerReadWriteSplitting] = useState(
    pooler?.readWriteSplitting !== false,
  )

  useEffect(() => {
    setReplicaCount(database.replicas ?? 0)
    setSyncMode(database.syncMode || 'async')
    setIdleTimeoutSeconds(String(database.networkIdleTimeoutSeconds ?? ''))
    setIpAllowlist(database.networkIPAllowlist ?? [])
    setPitrEnabled(database.pitr === true)
    setPitrRetentionDays(String(database.pitrRetentionDays ?? ''))
    setStorageAutoscaling(database.storageAutoscaling === true)
    setAutoscalingThreshold(
      String(database.storageAutoscalingThresholdPercent ?? ''),
    )
    setAutoscalingMaxGb(String(database.storageAutoscalingMaxGb ?? ''))
    setMaintenanceDay(database.maintenanceWindowDay || 'sun')
    setMaintenanceHourUtc(String(database.maintenanceWindowHourUtc ?? 0))
  }, [database])

  useEffect(() => {
    if (!pooler) return
    setPoolerMode(pooler.mode || 'transaction')
    setPoolerMaxConnections(String(pooler.maxConnections ?? ''))
    setPoolerDefaultPoolSize(String(pooler.defaultPoolSize ?? ''))
    setPoolerReadWriteSplitting(pooler.readWriteSplitting !== false)
  }, [pooler])

  const replicaOption = useMemo(
    () => getReplicaOption(replicaCount),
    [replicaCount],
  )

  const writeDisabled = !canWrite || updateMutation.isPending
  const writeTooltip = !canWrite
    ? t("You don't have permission to change database settings.")
    : undefined

  const addIpAddress = () => {
    const value = ipInput.trim()
    if (!value || ipAllowlist.includes(value)) return
    setIpAllowlist((prev) => [...prev, value])
    setIpInput('')
  }

  const removeIpAddress = (value: string) => {
    setIpAllowlist((prev) => prev.filter((entry) => entry !== value))
  }

  const handleHaUpdate = () => {
    updateMutation.mutate(
      { replicas: replicaCount, syncMode },
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

  const handleMaintenanceUpdate = () => {
    const hourUtc = Number.parseInt(maintenanceHourUtc, 10)
    if (!Number.isFinite(hourUtc) || hourUtc < 0 || hourUtc > 23) {
      toast.error(t('Enter an hour between 0 and 23 (UTC).'))
      return
    }
    maintenanceMutation.mutate(
      { day: maintenanceDay, hourUtc },
      {
        onSuccess: () => toast.success(t('Maintenance window updated')),
        onError: (error) =>
          toast.error(
            getErrorMessage(error, t('Failed to update maintenance window')),
          ),
      },
    )
  }

  const handlePoolerUpdate = () => {
    const maxConnections = Number.parseInt(poolerMaxConnections, 10)
    const defaultPoolSize = Number.parseInt(poolerDefaultPoolSize, 10)
    poolerMutation.mutate(
      {
        mode: poolerMode,
        maxConnections: Number.isFinite(maxConnections)
          ? maxConnections
          : undefined,
        defaultPoolSize: Number.isFinite(defaultPoolSize)
          ? defaultPoolSize
          : undefined,
        readWriteSplitting: poolerReadWriteSplitting,
      },
      {
        onSuccess: () => toast.success(t('Connection pooler settings updated')),
        onError: (error) =>
          toast.error(
            getErrorMessage(
              error,
              t('Failed to update connection pooler settings'),
            ),
          ),
      },
    )
  }

  const haDirty =
    replicaCount !== (database.replicas ?? 0) ||
    syncMode !== (database.syncMode || 'async')

  const networkDirty =
    idleTimeoutSeconds !== String(database.networkIdleTimeoutSeconds ?? '') ||
    JSON.stringify(ipAllowlist) !==
      JSON.stringify(database.networkIPAllowlist ?? [])

  const pitrDirty =
    pitrEnabled !== (database.pitr === true) ||
    pitrRetentionDays !== String(database.pitrRetentionDays ?? '')

  const storageDirty =
    storageAutoscaling !== (database.storageAutoscaling === true) ||
    autoscalingThreshold !==
      String(database.storageAutoscalingThresholdPercent ?? '') ||
    autoscalingMaxGb !== String(database.storageAutoscalingMaxGb ?? '')

  const maintenanceDirty =
    maintenanceDay !== (database.maintenanceWindowDay || 'sun') ||
    maintenanceHourUtc !== String(database.maintenanceWindowHourUtc ?? 0)

  const poolerDirty =
    !!pooler?.enabled &&
    (poolerMode !== (pooler.mode || 'transaction') ||
      poolerMaxConnections !== String(pooler.maxConnections ?? '') ||
      poolerDefaultPoolSize !== String(pooler.defaultPoolSize ?? '') ||
      poolerReadWriteSplitting !== (pooler.readWriteSplitting !== false))

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('High availability')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Configure read replicas and replication sync mode for failover resilience.',
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

          <PostgresReplicationSyncModePicker
            syncMode={syncMode}
            onSyncModeChange={setSyncMode}
            disabled={writeDisabled}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={writeDisabled || !haDirty}
            title={writeTooltip}
            onClick={handleHaUpdate}
          >
            {t('Update')}
          </Button>
        </div>
      </div>

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

      {features.databaseBackups ? (
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
      ) : null}

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

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Connection pooler')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Configure pooled connections when the pooler sidecar is attached to this database.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          {!pooler?.enabled ? (
            <p className="text-[13px] text-muted-foreground">
              {t('Connection pooler is not enabled for this database.')}
            </p>
          ) : (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label className="text-[13px]">{t('Pool mode')}</Label>
                <Select
                  value={poolerMode}
                  onValueChange={setPoolerMode}
                  disabled={writeDisabled}
                >
                  <SelectTrigger className="h-9 max-w-xs text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {POOLER_MODE_OPTIONS.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {t(option.label)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="pooler-max-connections" className="text-[13px]">
                    {t('Max pooled connections')}
                  </Label>
                  <Input
                    id="pooler-max-connections"
                    type="number"
                    min={1}
                    value={poolerMaxConnections}
                    onChange={(e) => setPoolerMaxConnections(e.target.value)}
                    disabled={writeDisabled}
                    className="h-9 text-[13px]"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="pooler-default-size" className="text-[13px]">
                    {t('Default pool size')}
                  </Label>
                  <Input
                    id="pooler-default-size"
                    type="number"
                    min={1}
                    value={poolerDefaultPoolSize}
                    onChange={(e) => setPoolerDefaultPoolSize(e.target.value)}
                    disabled={writeDisabled}
                    className="h-9 text-[13px]"
                  />
                </div>
              </div>

              <div className="flex items-center justify-between gap-4">
                <Label htmlFor="pooler-rw-split" className="text-[13px]">
                  {t('Read/write splitting')}
                </Label>
                <Switch
                  id="pooler-rw-split"
                  checked={poolerReadWriteSplitting}
                  onCheckedChange={setPoolerReadWriteSplitting}
                  disabled={writeDisabled}
                />
              </div>
            </div>
          )}
        </div>
        {pooler?.enabled ? (
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={writeDisabled || !poolerDirty || poolerMutation.isPending}
              title={writeTooltip}
              onClick={handlePoolerUpdate}
            >
              {t('Update')}
            </Button>
          </div>
        ) : null}
      </div>

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Maintenance window')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Minor version upgrades and maintenance tasks run during this weekly window (UTC).',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label className="text-[13px]">{t('Day')}</Label>
              <Select
                value={maintenanceDay}
                onValueChange={setMaintenanceDay}
                disabled={writeDisabled}
              >
                <SelectTrigger className="h-9 text-[13px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {MAINTENANCE_DAYS.map((day) => (
                    <SelectItem key={day.value} value={day.value}>
                      {t(day.label)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="maintenance-hour" className="text-[13px]">
                {t('Start hour (UTC)')}
              </Label>
              <Input
                id="maintenance-hour"
                type="number"
                min={0}
                max={23}
                value={maintenanceHourUtc}
                onChange={(e) => setMaintenanceHourUtc(e.target.value)}
                disabled={writeDisabled}
                className="h-9 text-[13px]"
              />
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={
              writeDisabled || !maintenanceDirty || maintenanceMutation.isPending
            }
            title={writeTooltip}
            onClick={handleMaintenanceUpdate}
          >
            {t('Update')}
          </Button>
        </div>
      </div>
    </>
  )
}
