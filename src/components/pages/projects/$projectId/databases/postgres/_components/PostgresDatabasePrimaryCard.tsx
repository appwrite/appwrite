import { useEffect, useMemo, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
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
  postgresDatabaseQueryOptions,
  useCreatePostgresDatabaseFailover,
  usePostgresDatabaseReplicas,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { PostgresDatabaseSettingsCardProps } from './postgres-database-settings-types'

function sortMembers(
  members: Models.DedicatedDatabaseMember[],
): Models.DedicatedDatabaseMember[] {
  return [...members].sort((left, right) => {
    if (left.role === 'primary') return -1
    if (right.role === 'primary') return 1
    return left.$id.localeCompare(right.$id)
  })
}

function getMemberStatusVariant(
  status: string,
): 'success' | 'warning' | 'error' | 'info' {
  const normalized = status.trim().toLowerCase()
  if (normalized === 'active') return 'success'
  if (normalized === 'pending') return 'warning'
  if (normalized === 'notfound') return 'error'
  return 'info'
}

function formatMemberStatus(status: string, t: ReturnType<typeof useT>) {
  const normalized = status.trim().toLowerCase()
  if (normalized === 'active') return t('Active')
  if (normalized === 'pending') return t('Pending')
  if (normalized === 'notfound') return t('Not found')
  return status
}

function formatLagSeconds(
  role: string,
  lagSeconds: number | null | undefined,
  t: ReturnType<typeof useT>,
) {
  if (role === 'primary') return t('N/A')
  if (lagSeconds == null || !Number.isFinite(lagSeconds)) return t('N/A')
  return t('{seconds}s lag').replace('{seconds}', String(lagSeconds))
}

function getReplicaLabel(
  member: Models.DedicatedDatabaseMember,
  replicaIndex: number,
  t: ReturnType<typeof useT>,
) {
  if (member.role === 'primary') return t('Primary instance')
  return `${t('Read replica')} ${replicaIndex}`
}

export function PostgresDatabasePrimaryCard({
  projectId,
  databaseId,
  database,
  canWrite,
}: PostgresDatabaseSettingsCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const haEnabled = (database.replicas ?? 0) > 0
  const pollReplicas = database.status !== 'ready'
  const { members, isLoading, isFetching } = usePostgresDatabaseReplicas(
    projectId,
    databaseId,
    haEnabled,
    pollReplicas ? 5000 : false,
  )
  const failoverMutation = useCreatePostgresDatabaseFailover(projectId, databaseId)
  const [selectedReplicaId, setSelectedReplicaId] = useState<string | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

  const sortedMembers = useMemo(() => sortMembers(members), [members])
  const primaryMember = sortedMembers.find((member) => member.role === 'primary')
  const failoverTargets = sortedMembers.filter((member) => member.role !== 'primary')

  useEffect(() => {
    if (!selectedReplicaId) return
    if (!failoverTargets.some((member) => member.$id === selectedReplicaId)) {
      setSelectedReplicaId(null)
    }
  }, [failoverTargets, selectedReplicaId])

  useEffect(() => {
    if (!pollReplicas || !projectId || !databaseId) return
    void queryClient.invalidateQueries({
      queryKey: postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
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
    if (!selectedReplicaId) return
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
        <div className="px-6 py-4 space-y-4">
          {isLoading && sortedMembers.length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {t('Loading cluster members…')}
            </p>
          ) : sortedMembers.length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {t('No cluster members are available yet.')}
            </p>
          ) : (
            <>
              <RadioGroup
                value={selectedReplicaId ?? undefined}
                onValueChange={setSelectedReplicaId}
                className="space-y-0"
              >
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4" />
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Instance')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Role')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Status')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                        {t('Replication lag')}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {sortedMembers.map((member) => {
                      const isPrimary = member.role === 'primary'
                      const replicaIndex = isPrimary ? 0 : ++replicaCounter
                      const label = getReplicaLabel(member, replicaIndex, t)
                      const statusVariant = getMemberStatusVariant(member.status)

                      return (
                        <TableRow key={member.$id}>
                          <TableCell className="px-4 py-3">
                            {isPrimary ? (
                              <span className="inline-block size-4" aria-hidden />
                            ) : (
                              <RadioGroupItem
                                value={member.$id}
                                id={`primary-target-${member.$id}`}
                                disabled={writeDisabled || databaseBusy}
                                aria-label={label}
                              />
                            )}
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Label
                              htmlFor={
                                isPrimary ? undefined : `primary-target-${member.$id}`
                              }
                              className={cn(
                                'text-[13px] font-medium text-foreground',
                                !isPrimary && 'cursor-pointer',
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
                          <TableCell className="px-4 py-3 text-right">
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

              {primaryMember ? (
                <p className="text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'The current primary is {instance}. Select a read replica and promote it to move write traffic.',
                  ).replace('{instance}', getReplicaLabel(primaryMember, 0, t))}
                </p>
              ) : null}
            </>
          )}

          {isFetching && sortedMembers.length > 0 ? (
            <div className="flex items-center gap-2 text-[12px] text-muted-foreground">
              <Loader2 className="size-3.5 animate-spin" aria-hidden />
              {t('Refreshing cluster members…')}
            </div>
          ) : null}
        </div>
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
          <div className="border-t border-border" />
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
