import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  mysqlDatabaseQueryOptions,
  useCreateDedicatedDatabaseFailover,
  useDedicatedDatabaseReplicas,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { MysqlDatabaseSettingsCardProps } from './mysql-database-settings-types'
import type { DedicatedReplicationSource } from '@/lib/databases/dedicated-replication'

function isPrimaryRole(role: string) {
  return role.trim().toLowerCase() === 'primary'
}

function isPlaceholderMemberId(id: string) {
  return id.startsWith('__')
}

function getMemberId(
  member: Models.DedicatedDatabaseMember,
  index: number,
): string {
  const rawId =
    member.$id ||
    (member as { id?: string }).id ||
    `${member.role || 'member'}-${index}`
  return String(rawId)
}

function sortMembers(
  members: Models.DedicatedDatabaseMember[],
): Models.DedicatedDatabaseMember[] {
  return [...members].sort((left, right) => {
    if (isPrimaryRole(left.role)) return -1
    if (isPrimaryRole(right.role)) return 1
    return getMemberId(left, 0).localeCompare(getMemberId(right, 0))
  })
}

/**
 * Ensure the table always lists the primary plus every configured replica.
 * When getReplicas returns a partial members list (common while pods are still
 * coming up), fill the missing slots so the UI matches the replica count.
 */
function expandClusterMembers(
  members: Models.DedicatedDatabaseMember[],
  configuredReplicaCount: number,
): Models.DedicatedDatabaseMember[] {
  const sorted = sortMembers(members)
  const primary = sorted.find((member) => isPrimaryRole(member.role))
  const replicaMembers = sorted.filter((member) => !isPrimaryRole(member.role))
  const targetReplicas = Math.max(
    Math.max(0, Math.floor(configuredReplicaCount)),
    replicaMembers.length,
  )

  // Members without a primary role: keep API order rather than inventing one.
  if (!primary && sorted.length > 0) {
    return sorted
  }

  const result: Models.DedicatedDatabaseMember[] = []

  if (primary) {
    result.push(primary)
  } else if (targetReplicas > 0) {
    result.push({
      $id: '__primary__',
      role: 'primary',
      status: 'provisioning',
      lagSeconds: 0,
    })
  } else {
    return []
  }

  for (let index = 0; index < targetReplicas; index++) {
    const existing = replicaMembers[index]
    if (existing) {
      result.push(existing)
    } else {
      result.push({
        $id: `__replica_${index + 1}__`,
        role: 'replica',
        status: 'provisioning',
        lagSeconds: 0,
      })
    }
  }

  return result
}

function getMemberStatusVariant(
  status: string,
): 'success' | 'warning' | 'error' | 'info' {
  const normalized = status.trim().toLowerCase()
  if (normalized === 'active') return 'success'
  if (normalized === 'provisioning' || normalized === 'starting') {
    return 'warning'
  }
  if (normalized === 'failed') return 'error'
  // Legacy API values (pre-15.3)
  if (normalized === 'pending') return 'warning'
  if (normalized === 'notfound') return 'error'
  return 'info'
}

function formatMemberStatus(status: string, t: ReturnType<typeof useT>) {
  const normalized = status.trim().toLowerCase()
  if (normalized === 'active') return t('Active')
  if (normalized === 'provisioning') return t('Provisioning')
  if (normalized === 'starting') return t('Starting')
  if (normalized === 'failed') return t('Failed')
  // Legacy API values (pre-15.3)
  if (normalized === 'pending') return t('Pending')
  if (normalized === 'notfound') return t('Not found')
  return status
}

function formatLagSeconds(
  role: string,
  lagSeconds: number | null | undefined,
  t: ReturnType<typeof useT>,
) {
  // Primary is the source of truth; lag only applies to replicas.
  if (isPrimaryRole(role)) return '-'
  if (lagSeconds == null || !Number.isFinite(lagSeconds)) return t('N/A')
  return t('{seconds}s lag').replace('{seconds}', String(lagSeconds))
}

function getReplicaLabel(
  member: Models.DedicatedDatabaseMember,
  replicaIndex: number,
  t: ReturnType<typeof useT>,
) {
  if (isPrimaryRole(member.role)) return t('Primary instance')
  return `${t('Read replica')} ${replicaIndex}`
}

export function MysqlDatabasePrimaryCard({
  projectId,
  databaseId,
  database,
  canWrite,
  replicationSource,
  haEngine,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const source: DedicatedReplicationSource = replicationSource ?? {
    type: 'engine',
    engine: haEngine || database.engine || 'mysql',
  }
  const haEnabled = (database.replicas ?? 0) > 0
  const pollReplicas = database.status !== 'ready'
  const { replicas, members, isLoading } = useDedicatedDatabaseReplicas(
    projectId,
    databaseId,
    source,
    haEnabled,
    pollReplicas ? 5000 : false,
  )
  const failoverMutation = useCreateDedicatedDatabaseFailover(
    projectId,
    databaseId,
    source,
  )
  const [selectedReplicaId, setSelectedReplicaId] = useState<string | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

  const configuredReplicaCount = replicas?.replicas ?? database.replicas ?? 0
  const displayMembers = useMemo(
    () => expandClusterMembers(members, configuredReplicaCount),
    [members, configuredReplicaCount],
  )
  const primaryMember = displayMembers.find((member) =>
    isPrimaryRole(member.role),
  )
  const failoverTargets = displayMembers.filter(
    (member) =>
      !isPrimaryRole(member.role) && !isPlaceholderMemberId(getMemberId(member, 0)),
  )

  useEffect(() => {
    if (!selectedReplicaId) return
    if (!failoverTargets.some((member) => member.$id === selectedReplicaId)) {
      setSelectedReplicaId(null)
    }
  }, [failoverTargets, selectedReplicaId])

  useEffect(() => {
    if (!pollReplicas || !projectId || !databaseId) return
    void queryClient.invalidateQueries({
      queryKey: mysqlDatabaseQueryOptions(projectId, databaseId).queryKey,
    })
  }, [databaseId, pollReplicas, projectId, queryClient])

  const writeDisabled = !canWrite || failoverMutation.isPending
  const writeTooltip = !canWrite
    ? t("You don't have permission to change database settings.")
    : undefined
  const databaseBusy = database.status !== 'ready'
  const busyTooltip = databaseBusy
    ? t('Failover is unavailable while the database status is {status}.').replace(
        '{status}',
        database.status,
      )
    : undefined

  const selectedMember = failoverTargets.find(
    (member) => member.$id === selectedReplicaId,
  )
  const promoteDisabled =
    writeDisabled ||
    databaseBusy ||
    !selectedMember ||
    selectedMember.status.trim().toLowerCase() !== 'active'

  const promoteTooltip =
    writeTooltip ??
    busyTooltip ??
    (!selectedMember
      ? t('Select a read replica to promote.')
      : selectedMember.status.trim().toLowerCase() !== 'active'
        ? t('Only active replicas can be promoted to primary.')
        : undefined)

  const handlePromote = () => {
    if (!selectedReplicaId || isPlaceholderMemberId(selectedReplicaId)) return
    failoverMutation.mutate(
      { targetReplicaId: selectedReplicaId },
      {
        onSuccess: () => {
          toast.success(t('Failover started'))
          setConfirmOpen(false)
          setSelectedReplicaId(null)
        },
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to start failover'))),
      },
    )
  }

  if (!haEnabled) return null

  let replicaCounter = 0

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Primary instance')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Choose which cluster member accepts reads and writes. Promoting a read replica triggers a manual failover.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        {isLoading && displayMembers.length === 0 ? (
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading cluster members…')}
            </p>
          </div>
        ) : displayMembers.length === 0 ? (
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t('No cluster members are available yet.')}
            </p>
          </div>
        ) : (
          <>
            <RadioGroup
              value={selectedReplicaId ?? undefined}
              onValueChange={setSelectedReplicaId}
              className="w-full gap-0"
            >
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="w-[40px] px-6 py-3" />
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Instance')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Role')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Status')}
                    </TableHead>
                    <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                      {t('Replication lag')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {displayMembers.map((member, index) => {
                    const memberId = getMemberId(member, index)
                    const isPrimary = isPrimaryRole(member.role)
                    const isPlaceholder = isPlaceholderMemberId(memberId)
                    const replicaIndex = isPrimary ? 0 : ++replicaCounter
                    const label = getReplicaLabel(member, replicaIndex, t)
                    const statusVariant = getMemberStatusVariant(member.status)
                    const rowDisabled =
                      writeDisabled || databaseBusy || isPlaceholder

                    return (
                      <TableRow key={`${member.role}-${memberId}-${index}`}>
                        <TableCell className="px-6 py-3">
                          {isPrimary ? (
                            <span className="inline-block size-4" aria-hidden />
                          ) : (
                            <RadioGroupItem
                              value={memberId}
                              id={`primary-target-${memberId}`}
                              disabled={rowDisabled}
                              aria-label={label}
                            />
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <Label
                            htmlFor={
                              isPrimary ? undefined : `primary-target-${memberId}`
                            }
                            className={cn(
                              'text-[13px] font-medium text-foreground',
                              !isPrimary && !rowDisabled && 'cursor-pointer',
                              rowDisabled && !isPrimary && 'cursor-not-allowed',
                            )}
                          >
                            {label}
                          </Label>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <span className="text-[13px] text-muted-foreground">
                            {isPrimary ? t('Primary') : t('Read replica')}
                          </span>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <Badge
                            variant={statusVariant}
                            className="text-[10px] shrink-0"
                          >
                            {formatMemberStatus(member.status, t)}
                          </Badge>
                        </TableCell>
                        <TableCell className="px-6 py-3 text-right">
                          <span className="text-[13px] tabular-nums text-muted-foreground">
                            {formatLagSeconds(member.role, member.lagSeconds, t)}
                          </span>
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </RadioGroup>

            <div className="space-y-2 px-6 py-3">
              {primaryMember ? (
                <p className="text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'The current primary is {instance}. Select a read replica and promote it to move write traffic.',
                  ).replace('{instance}', getReplicaLabel(primaryMember, 0, t))}
                </p>
              ) : null}
            </div>
          </>
        )}
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={promoteDisabled}
            title={promoteTooltip}
            onClick={() => setConfirmOpen(true)}
          >
            {t('Promote to primary')}
          </Button>
        </div>
      </div>

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent className="sm:max-w-md p-0">
          <AlertDialogHeader className="px-6 pt-6 pb-4 text-left">
            <AlertDialogTitle>{t('Promote to primary')}</AlertDialogTitle>
            <AlertDialogDescription className="text-[13px] mt-2">
              {selectedMember
                ? t(
                    'Promote {instance} to primary? The current primary will become a read replica. Writes may be briefly unavailable while failover completes.',
                  ).replace(
                    '{instance}',
                    getReplicaLabel(
                      selectedMember,
                      failoverTargets.indexOf(selectedMember) + 1,
                      t,
                    ),
                  )
                : t(
                    'Promote the selected read replica to primary? The current primary will become a read replica.',
                  )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="px-6 py-4 border-t border-border bg-muted/30 flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AlertDialogCancel disabled={failoverMutation.isPending}>
              {t('Cancel')}
            </AlertDialogCancel>
            <Button
              variant="destructive"
              disabled={failoverMutation.isPending || !selectedMember}
              onClick={handlePromote}
            >
              {t('Promote to primary')}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
