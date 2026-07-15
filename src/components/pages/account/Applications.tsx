import { Fragment, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, Package } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useAccountConnectedApps,
  groupConnectedApps,
  type AccountConnectedAppGroup,
  type AccountConnectedAppsData,
} from '@/lib/react-query/hooks/account-applications'
import type { KnownOAuthClient } from '@/lib/oauth-known-clients'
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const Dependencies = {
  IDENTITIES: ['identities', 'account'],
  APPLICATIONS: ['applications', 'account'],
} as const

function truncateClientId(id: string): string {
  return id.length > 12 ? `${id.slice(0, 4)}…${id.slice(-4)}` : id
}

function ConnectedAppAvatar({
  app,
  knownClient,
}: {
  app: Models.App | null
  knownClient: KnownOAuthClient | null
}) {
  if (app?.logoUri) {
    return (
      <img
        src={app.logoUri}
        alt={app.name}
        className="h-9 w-9 rounded-xl object-cover ring-1 ring-border/50"
        height={36}
        width={36}
      />
    )
  }

  if (knownClient) {
    return (
      <img
        src={knownClient.iconPath}
        alt={knownClient.name}
        className="h-9 w-9 rounded-xl object-cover ring-1 ring-border/50"
        height={36}
        width={36}
      />
    )
  }

  return (
    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-muted ring-1 ring-border/50">
      <Package className="h-4 w-4 text-muted-foreground" />
    </div>
  )
}

type AccountApplicationsProps = {
  initialData?: AccountConnectedAppsData
}

export function AccountApplications({
  initialData,
}: AccountApplicationsProps = {}) {
  const t = useT()
  const queryClient = useQueryClient()
  const { data, isFetched } = useAccountConnectedApps()
  const resolvedData = data ?? initialData
  const groups =
    resolvedData?.groups ??
    (resolvedData ? groupConnectedApps(resolvedData.connectedApps) : [])
  const hasResolvedData = isFetched || initialData !== undefined

  const [revokeDialogOpen, setRevokeDialogOpen] = useState(false)
  const [groupToRevoke, setGroupToRevoke] =
    useState<AccountConnectedAppGroup | null>(null)
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(new Set())

  const toggleGroup = (key: string) => {
    setExpandedGroups((previous) => {
      const next = new Set(previous)
      if (next.has(key)) {
        next.delete(key)
      } else {
        next.add(key)
      }
      return next
    })
  }

  const revokeMutation = useMutation({
    mutationFn: async (identityIds: string[]) => {
      const results = await Promise.allSettled(
        identityIds.map((identityId) =>
          sdk.forConsole.account.deleteIdentity({ identityId }),
        ),
      )
      // A 404 means the identity is already gone (e.g. a retry after a
      // partial failure) — treat it as revoked rather than failed.
      const failed = results.filter(
        (result) =>
          result.status === 'rejected' &&
          (result.reason as { code?: number } | null)?.code !== 404,
      ).length
      if (failed > 0) {
        throw new Error(
          failed === identityIds.length
            ? t('Failed to revoke application access')
            : t('Some authorizations could not be revoked. Please try again.'),
        )
      }
      return results.length
    },
    onSuccess: (revokedCount: number) => {
      queryClient.invalidateQueries({ queryKey: Dependencies.IDENTITIES })
      queryClient.invalidateQueries({ queryKey: Dependencies.APPLICATIONS })
      toast.success(
        revokedCount > 1
          ? t('Application access has been revoked for all authorizations')
          : t('Application access has been revoked'),
      )
      setRevokeDialogOpen(false)
      setGroupToRevoke(null)
    },
    onError: (error: Error) => {
      queryClient.invalidateQueries({ queryKey: Dependencies.IDENTITIES })
      queryClient.invalidateQueries({ queryKey: Dependencies.APPLICATIONS })
      toast.error(error.message || t('Failed to revoke application access'))
    },
  })

  const handleRevokeGroupClick = (group: AccountConnectedAppGroup) => {
    setGroupToRevoke(group)
    setRevokeDialogOpen(true)
  }

  const handleRevokeSingleClick = (
    group: AccountConnectedAppGroup,
    identity: Models.Identity,
  ) => {
    const grant = group.grants.find((g) => g.identity.$id === identity.$id)
    if (!grant) return
    setGroupToRevoke({
      ...group,
      grants: [grant],
      latestAuthorizedAt: grant.identity.$createdAt,
    })
    setRevokeDialogOpen(true)
  }

  const handleConfirmRevoke = () => {
    if (groupToRevoke) {
      revokeMutation.mutate(
        groupToRevoke.grants.map((grant) => grant.identity.$id),
      )
    }
  }

  const revokeCount = groupToRevoke?.grants.length ?? 0

  return (
    <>
      <div className="mb-6">
        <h2 className="text-[15px] font-semibold text-foreground">
          {t('Applications')}
        </h2>
        <p className="mt-1 text-[13px] text-muted-foreground">
          {t(
            "Applications you've authorized to access your Appwrite account.",
          )}
        </p>
      </div>

      {hasResolvedData && groups.length === 0 ? (
        <EmptyState
          icon={Package}
          title={t('No applications connected')}
          description={t(
            'When you authorize an application through OAuth, it will appear here.',
          )}
          isEmpty={true}
          variant="card"
          iconSize="md"
        />
      ) : groups.length > 0 ? (
        <div className="rounded-lg border border-border bg-card overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Application')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Authorized')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[130px]" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {groups.map((group) => {
                const { key, displayName, app, knownClient, grants } = group
                const isGrouped = grants.length > 1
                const isExpanded = expandedGroups.has(key)
                const subtitle =
                  app?.tagline?.trim() || app?.description?.trim()
                const singleClientId = !isGrouped ? grants[0].appId : null

                return (
                  <Fragment key={key}>
                    <TableRow
                      className={cn(isGrouped && 'cursor-pointer')}
                      onClick={
                        isGrouped ? () => toggleGroup(key) : undefined
                      }
                    >
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center gap-3 min-w-0">
                          <ConnectedAppAvatar
                            app={app}
                            knownClient={knownClient}
                          />
                          <div className="min-w-0">
                            <div className="flex items-center gap-2 min-w-0">
                              <span className="truncate text-[13px] font-medium text-foreground">
                                {displayName}
                              </span>
                              {isGrouped ? (
                                <Badge
                                  variant="info"
                                  className="text-[10px] shrink-0"
                                >
                                  {grants.length} {t('authorizations')}
                                </Badge>
                              ) : null}
                              {app?.deviceFlow ? (
                                <Badge
                                  variant="info"
                                  className="text-[10px] shrink-0"
                                >
                                  {t('Device flow')}
                                </Badge>
                              ) : null}
                            </div>
                            {singleClientId ? (
                              <div className="mt-0.5 flex items-center gap-1 text-[12px] text-muted-foreground">
                                <span className="shrink-0">
                                  {t('Client ID')}:
                                </span>
                                <CopyableId
                                  id={singleClientId}
                                  displayText={truncateClientId(
                                    singleClientId,
                                  )}
                                  variant="inline"
                                  size="sm"
                                  copyToastLabel="Client ID"
                                  className="-my-0.5 px-1 py-0.5 text-muted-foreground"
                                />
                              </div>
                            ) : subtitle ? (
                              <p className="truncate text-[12px] text-muted-foreground mt-0.5">
                                {subtitle}
                              </p>
                            ) : null}
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DateTooltip
                          date={new Date(group.latestAuthorizedAt)}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        <div className="flex items-center justify-end gap-1">
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8 text-[13px]"
                            onClick={(event) => {
                              event.stopPropagation()
                              handleRevokeGroupClick(group)
                            }}
                            disabled={revokeMutation.isPending}
                          >
                            {isGrouped ? t('Revoke all') : t('Revoke')}
                          </Button>
                          {isGrouped ? (
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 p-0"
                              onClick={(event) => {
                                event.stopPropagation()
                                toggleGroup(key)
                              }}
                              aria-label={
                                isExpanded
                                  ? t('Hide authorizations')
                                  : t('Show authorizations')
                              }
                              aria-expanded={isExpanded}
                            >
                              <ChevronDown
                                className={cn(
                                  'h-4 w-4 text-muted-foreground transition-transform',
                                  isExpanded && 'rotate-180',
                                )}
                              />
                            </Button>
                          ) : null}
                        </div>
                      </TableCell>
                    </TableRow>

                    {isGrouped && isExpanded
                      ? grants.map(({ identity, appId }) => (
                          <TableRow
                            key={identity.$id}
                            className="bg-muted/30 hover:bg-muted/40"
                          >
                            <TableCell className="px-4 py-2">
                              <div className="flex items-center gap-1 ps-12 text-[12px] text-muted-foreground">
                                <span className="shrink-0">
                                  {t('Client ID')}:
                                </span>
                                <CopyableId
                                  id={appId}
                                  displayText={truncateClientId(appId)}
                                  variant="inline"
                                  size="sm"
                                  copyToastLabel="Client ID"
                                  className="-my-0.5 px-1 py-0.5 text-muted-foreground"
                                />
                              </div>
                            </TableCell>
                            <TableCell className="px-4 py-2">
                              <DateTooltip
                                date={new Date(identity.$createdAt)}
                                className="text-[12px] text-muted-foreground"
                              />
                            </TableCell>
                            <TableCell className="px-4 py-2 text-right">
                              <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 text-[12px] text-muted-foreground hover:text-foreground"
                                onClick={() =>
                                  handleRevokeSingleClick(group, identity)
                                }
                                disabled={revokeMutation.isPending}
                              >
                                {t('Revoke')}
                              </Button>
                            </TableCell>
                          </TableRow>
                        ))
                      : null}
                  </Fragment>
                )
              })}
            </TableBody>
          </Table>
        </div>
      ) : null}

      <Dialog open={revokeDialogOpen} onOpenChange={setRevokeDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Revoke application access')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {revokeCount > 1 ? (
                <>
                  {t(
                    'This application has been authorized multiple times, likely because it registers a new OAuth client on each connection.',
                  )}{' '}
                  {t('Revoking will remove all')} {revokeCount}{' '}
                  {t(
                    'authorizations. You may need to authorize it again to use it.',
                  )}
                </>
              ) : (
                t(
                  'Are you sure you want to revoke access for this application? You may need to authorize it again to use it.',
                )
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setRevokeDialogOpen(false)}
              disabled={revokeMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              onClick={handleConfirmRevoke}
              disabled={revokeMutation.isPending}
            >
              {revokeCount > 1 ? t('Revoke all') : t('Revoke')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
