import { Fragment, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, KeyRound, Loader2, Package } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useAccountConnectedApps,
  useConsentTokens,
  groupConnectedApps,
  cimdUrlHost,
  type AccountConnectedApp,
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
  APPLICATIONS: ['applications', 'account'],
} as const

function truncateClientId(id: string): string {
  return id.length > 12 ? `${id.slice(0, 4)}…${id.slice(-4)}` : id
}

function clientIdDisplay(connectedApp: AccountConnectedApp): string {
  return connectedApp.cimdUrl
    ? cimdUrlHost(connectedApp.cimdUrl)
    : truncateClientId(connectedApp.clientId)
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

function ClientIdentifier({
  connectedApp,
  className,
}: {
  connectedApp: AccountConnectedApp
  className?: string
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'flex items-center gap-1 text-[12px] text-muted-foreground',
        className,
      )}
    >
      <span className="shrink-0">
        {connectedApp.cimdUrl ? t('Client URL') : t('Client ID')}:
      </span>
      <CopyableId
        id={connectedApp.clientId}
        displayText={clientIdDisplay(connectedApp)}
        variant="inline"
        size="sm"
        copyToastLabel={connectedApp.cimdUrl ? 'Client URL' : 'Client ID'}
        className="-my-0.5 px-1 py-0.5 text-muted-foreground"
      />
    </div>
  )
}

function ConsentTokenFamilies({
  consent,
  onRevoked,
}: {
  consent: Models.Oauth2Consent
  onRevoked: () => void
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const { data: tokens, isLoading } = useConsentTokens(consent.$id)
  const [revokingId, setRevokingId] = useState<string | null>(null)

  const revokeToken = async (tokenId: string) => {
    setRevokingId(tokenId)
    try {
      await sdk.forConsole.account.deleteConsentToken({
        consentId: consent.$id,
        tokenId,
      })
      toast.success(t('Token has been revoked'))
      // Refresh this consent's own token list (its own cache key) so the
      // revoked family drops out of the open panel immediately, in addition
      // to the applications list total.
      queryClient.invalidateQueries({
        queryKey: ['applications', 'account', consent.$id, 'tokens'],
      })
      onRevoked()
    } catch {
      toast.error(t('Failed to revoke token'))
    } finally {
      setRevokingId(null)
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center gap-2 py-1 text-[12px] text-muted-foreground">
        <Loader2 className="h-3.5 w-3.5 animate-spin" />
        {t('Loading tokens')}
      </div>
    )
  }

  if (!tokens || tokens.length === 0) {
    return (
      <p className="py-1 text-[12px] text-muted-foreground">
        {t('No active tokens for this authorization.')}
      </p>
    )
  }

  return (
    <ul className="divide-y divide-border/60">
      {tokens.map((token) => {
        const expiresAt = token.expire ? new Date(token.expire) : null
        const isExpired = expiresAt !== null && expiresAt.getTime() < Date.now()
        return (
          <li
            key={token.$id}
            className="flex items-center justify-between gap-3 py-1.5"
          >
            <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-0.5 text-[12px] text-muted-foreground">
              <span className="inline-flex items-center gap-1.5">
                <KeyRound className="h-3.5 w-3.5 shrink-0" />
                {t('Issued')}{' '}
                <DateTooltip
                  date={new Date(token.$createdAt)}
                  className="text-[12px] text-muted-foreground"
                />
              </span>
              {expiresAt ? (
                <span>
                  {isExpired ? t('Expired') : t('Expires')}{' '}
                  <DateTooltip
                    date={expiresAt}
                    className="text-[12px] text-muted-foreground"
                  />
                </span>
              ) : null}
            </div>
            <Button
              variant="ghost"
              size="sm"
              className="h-7 shrink-0 text-[12px] text-muted-foreground hover:text-foreground"
              onClick={() => revokeToken(token.$id)}
              disabled={revokingId !== null}
            >
              {revokingId === token.$id ? (
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
              ) : (
                t('Revoke')
              )}
            </Button>
          </li>
        )
      })}
    </ul>
  )
}

function ConsentDetail({
  connectedApp,
  showRevoke,
  onRevoke,
  onTokenRevoked,
  revokePending,
}: {
  connectedApp: AccountConnectedApp
  showRevoke: boolean
  onRevoke: () => void
  onTokenRevoked: () => void
  revokePending: boolean
}) {
  const t = useT()
  const { consent } = connectedApp

  return (
    <div className="rounded-lg border border-border/60 bg-background/60 px-3 py-2">
      <div className="flex items-center justify-between gap-3">
        <div className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1">
          <ClientIdentifier connectedApp={connectedApp} />
          <span className="text-[12px] text-muted-foreground">
            {t('Authorized')}{' '}
            <DateTooltip
              date={new Date(consent.$createdAt)}
              className="text-[12px] text-muted-foreground"
            />
          </span>
        </div>
        {showRevoke ? (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 shrink-0 text-[12px] text-muted-foreground hover:text-foreground"
            onClick={onRevoke}
            disabled={revokePending}
          >
            {t('Revoke')}
          </Button>
        ) : null}
      </div>
      {consent.scopes.length > 0 ? (
        <div className="mt-1.5 flex flex-wrap items-center gap-1">
          {consent.scopes.map((scope) => (
            <Badge
              key={scope}
              variant="outline"
              className="text-[10px] font-normal text-muted-foreground"
            >
              {scope}
            </Badge>
          ))}
        </div>
      ) : null}
      <div className="mt-1.5">
        <ConsentTokenFamilies consent={consent} onRevoked={onTokenRevoked} />
      </div>
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

  const invalidateApplications = () => {
    queryClient.invalidateQueries({ queryKey: Dependencies.APPLICATIONS })
  }

  const revokeMutation = useMutation({
    mutationFn: async (consentIds: string[]) => {
      const results = await Promise.allSettled(
        consentIds.map((consentId) =>
          sdk.forConsole.account.deleteConsent({ consentId }),
        ),
      )
      // A 404 means the consent is already gone (e.g. a retry after a
      // partial failure) - treat it as revoked rather than failed.
      const failed = results.filter(
        (result) =>
          result.status === 'rejected' &&
          (result.reason as { code?: number } | null)?.code !== 404,
      ).length
      if (failed > 0) {
        throw new Error(
          failed === consentIds.length
            ? t('Failed to revoke application access')
            : t('Some authorizations could not be revoked. Please try again.'),
        )
      }
      return results.length
    },
    onSuccess: (revokedCount: number) => {
      invalidateApplications()
      toast.success(
        revokedCount > 1
          ? t('Application access has been revoked for all authorizations')
          : t('Application access has been revoked'),
      )
      setRevokeDialogOpen(false)
      setGroupToRevoke(null)
    },
    onError: (error: Error) => {
      invalidateApplications()
      toast.error(error.message || t('Failed to revoke application access'))
    },
  })

  const handleRevokeGroupClick = (group: AccountConnectedAppGroup) => {
    setGroupToRevoke(group)
    setRevokeDialogOpen(true)
  }

  const handleRevokeSingleClick = (
    group: AccountConnectedAppGroup,
    connectedApp: AccountConnectedApp,
  ) => {
    setGroupToRevoke({
      ...group,
      grants: [connectedApp],
      latestAuthorizedAt: connectedApp.consent.$createdAt,
    })
    setRevokeDialogOpen(true)
  }

  const handleConfirmRevoke = () => {
    if (groupToRevoke) {
      revokeMutation.mutate(
        groupToRevoke.grants.map((grant) => grant.consent.$id),
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
                const singleGrant = !isGrouped ? grants[0] : null

                return (
                  <Fragment key={key}>
                    <TableRow
                      className="cursor-pointer"
                      onClick={() => toggleGroup(key)}
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
                            {singleGrant ? (
                              <ClientIdentifier
                                connectedApp={singleGrant}
                                className="mt-0.5"
                              />
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
                                ? t('Hide authorization details')
                                : t('Show authorization details')
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
                        </div>
                      </TableCell>
                    </TableRow>

                    {isExpanded ? (
                      <TableRow className="bg-muted/30 hover:bg-muted/30">
                        <TableCell colSpan={3} className="px-4 py-3">
                          <div className="flex flex-col gap-2 ps-12">
                            {grants.map((connectedApp) => (
                              <ConsentDetail
                                key={connectedApp.consent.$id}
                                connectedApp={connectedApp}
                                showRevoke={isGrouped}
                                onRevoke={() =>
                                  handleRevokeSingleClick(group, connectedApp)
                                }
                                onTokenRevoked={invalidateApplications}
                                revokePending={revokeMutation.isPending}
                              />
                            ))}
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : null}
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
                    'authorizations and their active tokens. You may need to authorize it again to use it.',
                  )}
                </>
              ) : (
                t(
                  'Are you sure you want to revoke access for this application? All of its active tokens will stop working, and you may need to authorize it again to use it.',
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
