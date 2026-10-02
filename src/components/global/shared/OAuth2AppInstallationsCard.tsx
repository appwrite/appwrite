import { useMemo, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { Blocks, Loader2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type OAuth2AppInstallationsCardProps = {
  installations: Models.AppInstallation[]
  isLoading: boolean
  onDelete: (installationId: string) => Promise<unknown>
  isDeleting?: boolean
  /** Another page of installations exists beyond the loaded ones. */
  hasMore?: boolean
  onLoadMore?: () => void
  isLoadingMore?: boolean
  /** Label for the installing team ("Organization" for console apps, "Team" in projects). */
  teamLabel: string
  /** Compact paddings for use inside drawers. */
  embedded?: boolean
  /** Extra classes for the nested dialog, e.g. a z-index above a drawer. */
  dialogClassName?: string
}

/** Resources listed in RFC 9396 `authorization_details` entries (`identifiers`). */
function countAuthorizedResources(
  details: Record<string, unknown>[] | undefined,
): number {
  return (details ?? []).reduce((sum, entry) => {
    const identifiers = entry.identifiers
    return sum + (Array.isArray(identifiers) ? identifiers.length : 0)
  }, 0)
}

/**
 * Installations of an OAuth2 app (teams that installed it, the scopes they
 * granted, and a way to remove them). Shared by the organization app pages
 * and the project OAuth2 app drawer, which only differ in the data source.
 */
export function OAuth2AppInstallationsCard({
  installations,
  isLoading,
  onDelete,
  isDeleting = false,
  hasMore = false,
  onLoadMore,
  isLoadingMore = false,
  teamLabel,
  embedded = false,
  dialogClassName,
}: OAuth2AppInstallationsCardProps) {
  const t = useT()
  const [deleteTarget, setDeleteTarget] =
    useState<Models.AppInstallation | null>(null)

  const sorted = useMemo(
    () =>
      [...installations].sort(
        (a, b) =>
          new Date(b.$createdAt).getTime() - new Date(a.$createdAt).getTime(),
      ),
    [installations],
  )

  const handleDelete = async () => {
    if (!deleteTarget) return
    try {
      await onDelete(deleteTarget.$id)
      toast.success(t('Installation removed'))
      setDeleteTarget(null)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to remove installation')))
    }
  }

  const padding = embedded ? 'px-4 py-3' : 'px-6 py-4'

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className={padding}>
          <div className="flex items-center gap-2">
            <h3
              className={cn(
                'font-semibold text-foreground',
                embedded ? 'text-[13px]' : 'text-[15px]',
              )}
            >
              {t('Installations')}
            </h3>
            {!isLoading && sorted.length > 0 ? (
              <Badge variant="info" className="text-[10px] shrink-0">
                {sorted.length}
                {hasMore ? '+' : ''}
              </Badge>
            ) : null}
          </div>
          <p
            className={cn(
              'text-muted-foreground',
              embedded ? 'mt-1 text-[12px]' : 'mt-2 text-[13px]',
            )}
          >
            {t(
              'Teams that installed this app and the scopes they granted. Removing an installation revokes its access tokens.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className={padding}>
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : sorted.length === 0 ? (
            <EmptyState
              icon={Blocks}
              title={t('No installations')}
              description={t(
                'Installations appear here once a team installs this app.',
              )}
              variant="card"
            />
          ) : (
            <div className="overflow-hidden rounded-lg border border-border divide-y divide-border">
              {sorted.map((installation) => {
                const resources = countAuthorizedResources(
                  installation.authorizationDetails,
                )
                return (
                  <div
                    key={installation.$id}
                    className="flex items-start justify-between gap-3 p-3"
                  >
                    <div className="min-w-0 flex-1 space-y-2">
                      <div className="flex flex-wrap items-center gap-2 text-[12px] text-muted-foreground">
                        <span className="shrink-0 font-medium text-foreground">
                          {teamLabel}
                        </span>
                        <CopyableId
                          id={installation.teamId}
                          size="xs"
                          copyLabel="Team ID"
                          copyToastLabel="Team ID"
                        />
                      </div>
                      <div className="flex flex-wrap items-center gap-1">
                        {installation.scopes.length === 0 ? (
                          <span className="text-[12px] text-muted-foreground">
                            {t('No scopes granted')}
                          </span>
                        ) : (
                          installation.scopes.map((scope) => (
                            <Badge
                              key={scope}
                              variant="info"
                              className="font-mono text-[10px] shrink-0"
                            >
                              {scope}
                            </Badge>
                          ))
                        )}
                      </div>
                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted-foreground">
                        <span className="whitespace-nowrap">
                          {t('Created')}{' '}
                          <DateTooltip
                            date={installation.$createdAt}
                            className="text-[12px] text-muted-foreground"
                          />
                        </span>
                        {installation.createdByName ? (
                          <span className="whitespace-nowrap">
                            {t('Created by')} {installation.createdByName}
                          </span>
                        ) : null}
                        <span className="whitespace-nowrap">
                          {installation.lastAccessedAt ? (
                            <>
                              {t('Last used')}{' '}
                              <DateTooltip
                                date={installation.lastAccessedAt}
                                className="text-[12px] text-muted-foreground"
                              />
                            </>
                          ) : (
                            t('Never used')
                          )}
                        </span>
                        {resources > 0 ? (
                          <span className="whitespace-nowrap">
                            {t('Authorized resources')}: {resources}
                          </span>
                        ) : null}
                      </div>
                    </div>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          onClick={() => setDeleteTarget(installation)}
                        >
                          <MenuItemContent icon={Trash2}>
                            {t('Remove')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                )
              })}
            </div>
          )}
          {hasMore ? (
            <div className="flex justify-center pt-3">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 text-[12px]"
                disabled={isLoadingMore}
                onClick={onLoadMore}
              >
                {t('Load more')}
              </Button>
            </div>
          ) : null}
        </div>
      </div>

      <Dialog
        open={deleteTarget !== null}
        onOpenChange={(open) => {
          if (!open && !isDeleting) setDeleteTarget(null)
        }}
      >
        <DialogContent
          className={cn('sm:max-w-md p-0', dialogClassName)}
          overlayClassName={dialogClassName}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Remove installation')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'The app loses access to this team immediately and its installation tokens stop working. The team can install the app again later.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteTarget(null)}
              disabled={isDeleting}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={isDeleting}
              onClick={() => void handleDelete()}
            >
              {t('Remove')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
