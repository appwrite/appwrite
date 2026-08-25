import { useEffect, useMemo, useState } from 'react'
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { CheckCircle2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import {
  hasLockedDatabaseSpecifications,
  mapDedicatedDatabaseSpecifications,
} from '@/lib/database-specs'
import {
  MYSQL_DATABASE_SPECS_SOURCE,
  useDatabaseSpecifications,
  useProject,
  useUpdateMysqlDatabase,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { localizeResourceStatusLabel } from '@/lib/i18n/resource-status-labels'
import { useT } from '@/lib/i18n/translate'
import type { MysqlDatabaseSettingsCardProps } from './mysql-database-settings-types'

export type { MysqlDatabaseSettingsCardProps } from './mysql-database-settings-types'

function dedicatedStatusVariant(
  status: string,
): 'success' | 'warning' | 'error' | 'info' | 'inactive' {
  switch (status) {
    case 'ready':
      return 'success'
    case 'provisioning':
    case 'scaling':
    case 'restoring':
    case 'upgrading':
    case 'migrating':
    case 'pausing':
    case 'resuming':
    case 'deleting':
      return 'warning'
    case 'failed':
    case 'deleted':
      return 'error'
    case 'paused':
    case 'inactive':
      return 'inactive'
    default:
      return 'info'
  }
}

function useWriteAccess(canWrite: boolean, isPending: boolean) {
  const t = useT()
  const writeDisabled = !canWrite || isPending
  const writeTooltip = !canWrite
    ? t("You don't have permission to change database settings.")
    : undefined
  return { writeDisabled, writeTooltip }
}

export function MysqlDatabaseNameCard({
  projectId,
  databaseId,
  database,
  canWrite,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const updateMutation = useUpdateMysqlDatabase(projectId, databaseId)
  const [databaseName, setDatabaseName] = useState(database.name)
  const { writeDisabled, writeTooltip } = useWriteAccess(
    canWrite,
    updateMutation.isPending,
  )

  useEffect(() => {
    setDatabaseName(database.name)
  }, [database.name])

  const handleNameUpdate = () => {
    const trimmed = databaseName.trim()
    if (!trimmed || trimmed === database.name) return
    updateMutation.mutate(
      { name: trimmed },
      {
        onSuccess: () => toast.success(t('Database name updated')),
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to update database name'))),
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Name')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {t(
            "Update your database's display name. This will be visible to all organization members.",
          )}
        </p>
        <Input
          value={databaseName}
          onChange={(e) => setDatabaseName(e.target.value)}
          placeholder={t('Database name')}
          disabled={writeDisabled}
          title={writeTooltip}
          className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
        />
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={
            writeDisabled ||
            !databaseName.trim() ||
            databaseName.trim() === database.name
          }
          title={writeTooltip}
          onClick={handleNameUpdate}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function MysqlDatabaseDetailsCard({
  projectId,
  databaseId,
  database,
  canWrite,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const updateMutation = useUpdateMysqlDatabase(projectId, databaseId)
  const [paused, setPaused] = useState(database.status === 'paused')
  const { writeDisabled, writeTooltip } = useWriteAccess(
    canWrite,
    updateMutation.isPending,
  )

  useEffect(() => {
    setPaused(database.status === 'paused')
  }, [database.status])

  const canPauseResume =
    database.status === 'ready' || database.status === 'paused'

  const handlePausedUpdate = () => {
    const nextStatus = paused ? 'paused' : 'ready'
    if (nextStatus === database.status) return
    updateMutation.mutate(
      { status: nextStatus },
      {
        onSuccess: () =>
          toast.success(
            paused ? t('Database paused') : t('Database resumed'),
          ),
        onError: (error) => {
          setPaused(database.status === 'paused')
          toast.error(
            getErrorMessage(error, t('Failed to update database status')),
          )
        },
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {database.name}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge
            variant={dedicatedStatusVariant(database.status)}
            className="text-[11px] capitalize"
          >
            {localizeResourceStatusLabel(database.status, t)}
          </Badge>
          {database.version ? (
            <span className="text-[12px] text-muted-foreground">
              {t('Version')}: {database.version}
            </span>
          ) : null}
        </div>

        {canPauseResume ? (
          <div className="flex items-center gap-3">
            <Switch
              id="mysql-db-paused"
              checked={paused}
              onCheckedChange={setPaused}
              disabled={writeDisabled}
            />
            <Label
              htmlFor="mysql-db-paused"
              className="text-[13px] text-foreground"
            >
              {paused ? t('Paused') : t('Running')}
            </Label>
          </div>
        ) : null}

        <div className="space-y-1">
          <p className="text-[13px] text-muted-foreground">
            {t('Database ID')}:{' '}
            <span className="ms-1.5">
              <CopyableId id={database.$id} size="sm" />
            </span>
          </p>
          <p className="text-[13px] text-muted-foreground">
            {t('Created')}:{' '}
            <DateTooltip
              date={database.$createdAt}
              showFormattedDate
              className="text-foreground"
            />
          </p>
          <p className="text-[13px] text-muted-foreground">
            {t('Last updated')}:{' '}
            <DateTooltip
              date={database.$updatedAt || database.$createdAt}
              showFormattedDate
              className="text-foreground"
            />
          </p>
          {database.networkMaxConnections > 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {t('Max connections')}:{' '}
              <span className="text-foreground tabular-nums">
                {database.networkMaxConnections}
              </span>
            </p>
          ) : null}
        </div>
      </div>
      {canPauseResume ? (
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={
              writeDisabled ||
              (paused ? 'paused' : 'ready') === database.status
            }
            title={writeTooltip}
            onClick={handlePausedUpdate}
          >
            {t('Update')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}

export function MysqlDatabaseComputeTierCard({
  projectId,
  databaseId,
  database,
  canWrite,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const { data: specificationsData } = useDatabaseSpecifications(projectId, MYSQL_DATABASE_SPECS_SOURCE)
  const updateMutation = useUpdateMysqlDatabase(projectId, databaseId)

  const specs = useMemo(
    () =>
      mapDedicatedDatabaseSpecifications(specificationsData?.specifications),
    [specificationsData?.specifications],
  )

  const currentSpecIndex = useMemo(
    () => specs.findIndex((spec) => spec.id === database.specification),
    [specs, database.specification],
  )

  const handleSpecificationUpgrade = (specification: string) => {
    updateMutation.mutate(
      { specification },
      {
        onSuccess: () => toast.success(t('Compute tier update started')),
        onError: (error) =>
          toast.error(
            getErrorMessage(error, t('Failed to update compute tier')),
          ),
      },
    )
  }

  return (
    <div
      data-card-id="specification"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Compute tier')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Change the compute tier for this database. Upgrades apply with zero downtime via rolling cutover.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border bg-muted/40">
            <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Tier')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              CPU
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Memory')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Connections')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
              {t('Price')}
            </TableHead>
            <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[120px]" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {specs.map((spec, index) => {
            const isCurrent = spec.id === database.specification
            const locked = spec.comingSoon === true
            const isDowngrade =
              currentSpecIndex >= 0 && index < currentSpecIndex
            const canUpgrade =
              canWrite &&
              !locked &&
              !isCurrent &&
              !isDowngrade &&
              (currentSpecIndex < 0 || index > currentSpecIndex)

            return (
              <TableRow
                key={spec.id}
                className={cn(
                  'border-b border-border last:border-b-0',
                  isCurrent && 'bg-primary/5',
                )}
              >
                <TableCell className="px-6 py-3">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[13px] font-medium text-foreground">
                      {spec.label}
                    </span>
                    {isCurrent ? (
                      <Badge
                        variant="success"
                        className="gap-1 text-[10px] shrink-0"
                      >
                        <CheckCircle2 className="h-3 w-3" />
                        {t('Current')}
                      </Badge>
                    ) : null}
                    {locked ? (
                      <Badge
                        variant="inactive"
                        className="text-[10px] shrink-0"
                      >
                        {t('Coming soon')}
                      </Badge>
                    ) : null}
                  </span>
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                  {spec.cpu}
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                  {spec.memory}
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] tabular-nums text-muted-foreground">
                  {spec.connections}
                </TableCell>
                <TableCell className="px-4 py-3 text-end text-[13px] font-medium tabular-nums text-foreground">
                  {spec.price}
                </TableCell>
                <TableCell className="px-6 py-3 text-end">
                  {canUpgrade ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-[12px]"
                      disabled={updateMutation.isPending}
                      onClick={() => handleSpecificationUpgrade(spec.id)}
                    >
                      {t('Upgrade')}
                    </Button>
                  ) : null}
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
      {hasLockedDatabaseSpecifications(specs) ? (
        <div className="px-6 py-3">
          <SpecificationsUpgradeNote
            orgId={project?.teamId}
            showContactSales
          />
        </div>
      ) : null}
    </div>
  )
}
