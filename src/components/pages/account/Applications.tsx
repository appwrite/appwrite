import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AppWindow, Package } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useAccountConnectedApps,
  type AccountConnectedApp,
  type AccountConnectedAppsData,
} from '@/lib/react-query/hooks/account-applications'
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
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useT } from '@/lib/i18n/translate'

const Dependencies = {
  IDENTITIES: ['identities', 'account'],
  APPLICATIONS: ['applications', 'account'],
} as const

function getConnectedAppDisplayName(
  appId: string,
  app: Models.App | null,
): string {
  return app?.name || appId
}

function ConnectedAppAvatar({ app }: { app: Models.App | null }) {
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

  return (
    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-muted ring-1 ring-border/50">
      <AppWindow className="h-4 w-4 text-muted-foreground" />
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
  const connectedApps = data?.connectedApps ?? initialData?.connectedApps ?? []
  const hasResolvedData = isFetched || initialData !== undefined

  const [revokeDialogOpen, setRevokeDialogOpen] = useState(false)
  const [appToRevoke, setAppToRevoke] = useState<AccountConnectedApp | null>(
    null,
  )

  const revokeMutation = useMutation({
    mutationFn: async (identityId: string) => {
      return await sdk.forConsole.account.deleteIdentity({ identityId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.IDENTITIES })
      queryClient.invalidateQueries({ queryKey: Dependencies.APPLICATIONS })
      toast.success(t('Application access has been revoked'))
      setRevokeDialogOpen(false)
      setAppToRevoke(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to revoke application access'))
    },
  })

  const handleRevokeClick = (connectedApp: AccountConnectedApp) => {
    setAppToRevoke(connectedApp)
    setRevokeDialogOpen(true)
  }

  const handleConfirmRevoke = () => {
    if (appToRevoke) {
      revokeMutation.mutate(appToRevoke.identity.$id)
    }
  }

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

      {hasResolvedData && connectedApps.length === 0 ? (
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
      ) : connectedApps.length > 0 ? (
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
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px]" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {connectedApps.map(({ identity, appId, app }) => {
                const displayName = getConnectedAppDisplayName(appId, app)
                const subtitle = app?.tagline?.trim() || app?.description?.trim()

                return (
                  <TableRow key={identity.$id}>
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-3 min-w-0">
                        <ConnectedAppAvatar app={app} />
                        <div className="min-w-0">
                          <div className="flex items-center gap-2 min-w-0">
                            <span className="truncate text-[13px] font-medium text-foreground">
                              {displayName}
                            </span>
                            {app?.deviceFlow ? (
                              <Badge
                                variant="info"
                                className="text-[10px] shrink-0"
                              >
                                {t('Device flow')}
                              </Badge>
                            ) : null}
                          </div>
                          {subtitle ? (
                            <p className="truncate text-[12px] text-muted-foreground mt-0.5">
                              {subtitle}
                            </p>
                          ) : null}
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip
                        date={new Date(identity.$createdAt)}
                        className="text-[12px] text-muted-foreground"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-8 text-[13px]"
                        onClick={() =>
                          handleRevokeClick({ identity, appId, app })
                        }
                        disabled={revokeMutation.isPending}
                      >
                        {t('Revoke')}
                      </Button>
                    </TableCell>
                  </TableRow>
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
              {t(
                'Are you sure you want to revoke access for this application? You may need to authorize it again to use it.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
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
              {t('Revoke')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
