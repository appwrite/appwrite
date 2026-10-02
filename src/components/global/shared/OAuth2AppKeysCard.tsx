import { useMemo, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import {
  Check,
  Copy,
  KeySquare,
  Loader2,
  Lock,
  Plus,
  Terminal,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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
import { copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type OAuth2AppKeysCardProps = {
  keys: Models.AppKey[]
  isLoading: boolean
  onCreate: () => Promise<Models.AppKey>
  onDelete: (keyId: string) => Promise<unknown>
  isCreating?: boolean
  isDeleting?: boolean
  /** Another page of keys exists beyond the loaded ones. */
  hasMore?: boolean
  onLoadMore?: () => void
  isLoadingMore?: boolean
  /** Compact paddings for use inside drawers. */
  embedded?: boolean
  /** Extra classes for nested dialogs, e.g. a z-index above a drawer. */
  dialogClassName?: string
}

/**
 * App keys of an OAuth2 app. The app signs a JWT with a key to authenticate
 * as itself (for example to mint installation access tokens). Unlike OAuth
 * secrets, key values stay readable, so rows offer a copy action.
 */
export function OAuth2AppKeysCard({
  keys,
  isLoading,
  onCreate,
  onDelete,
  isCreating = false,
  isDeleting = false,
  hasMore = false,
  onLoadMore,
  isLoadingMore = false,
  embedded = false,
  dialogClassName,
}: OAuth2AppKeysCardProps) {
  const t = useT()
  const [createdKey, setCreatedKey] = useState<Models.AppKey | null>(null)
  const [copiedCreatedKey, setCopiedCreatedKey] = useState(false)
  const [deleteTargetId, setDeleteTargetId] = useState<string | null>(null)

  const sorted = useMemo(
    () =>
      [...keys].sort(
        (a, b) =>
          new Date(b.$createdAt).getTime() - new Date(a.$createdAt).getTime(),
      ),
    [keys],
  )

  const handleCreate = async () => {
    try {
      const created = await onCreate()
      setCreatedKey(created)
      toast.success(t('App key created'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create app key')))
    }
  }

  const handleDelete = async () => {
    if (!deleteTargetId) return
    try {
      await onDelete(deleteTargetId)
      toast.success(t('App key deleted'))
      setDeleteTargetId(null)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete app key')))
    }
  }

  // The shared helper's toast is English only; show a translated one.
  const copyKey = async (secret: string) => {
    const copied = await copyToClipboard('App key', secret, {
      showToast: false,
    })
    if (copied) toast.success(t('App key copied'))
    return copied
  }

  const handleCopyCreatedKey = async () => {
    if (!createdKey) return
    if (!(await copyKey(createdKey.secret))) return
    setCopiedCreatedKey(true)
    setTimeout(() => setCopiedCreatedKey(false), 2000)
  }

  const padding = embedded ? 'px-4 py-3' : 'px-6 py-4'

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className={cn(padding, 'flex items-start justify-between gap-4')}>
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <h3
                className={cn(
                  'font-semibold text-foreground',
                  embedded ? 'text-[13px]' : 'text-[15px]',
                )}
              >
                {t('App keys')}
              </h3>
              {!isLoading && sorted.length > 0 ? (
                <Badge variant="info" className="text-[10px] shrink-0">
                  {sorted.length}
                  {hasMore ? '+' : ''} {t('active')}
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
                'Your app signs a JWT with an app key to authenticate as itself, for example to mint installation access tokens. Delete a key to revoke it.',
              )}
            </p>
          </div>
          <Button
            type="button"
            size="sm"
            variant={embedded ? 'outline' : 'default'}
            className={cn(
              'shrink-0',
              embedded ? 'h-8 text-[12px]' : 'h-9 text-[13px]',
            )}
            disabled={isCreating}
            onClick={() => void handleCreate()}
          >
            <Plus className="me-1.5 h-3.5 w-3.5" />
            {t('Create key')}
          </Button>
        </div>
        <div className="border-t border-border" />
        <div className={cn(padding, 'space-y-3')}>
          <Alert className="border-border bg-muted/30">
            <Lock className="h-4 w-4 text-muted-foreground" />
            <AlertTitle className="text-[12px] font-medium text-foreground">
              {t('Server-side only')}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-muted-foreground">
              {t(
                'Never ship app keys in mobile apps, SPAs, or public repositories. Store them in a secrets manager.',
              )}
            </AlertDescription>
          </Alert>

          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : sorted.length === 0 ? (
            <EmptyState
              icon={KeySquare}
              title={t('No app keys')}
              description={t(
                'Create a key when your app needs to authenticate as itself, such as when it mints installation tokens.',
              )}
              variant="card"
            />
          ) : (
            <div className="overflow-hidden rounded-lg border border-border divide-y divide-border">
              {sorted.map((key) => (
                <div
                  key={key.$id}
                  className="flex items-center justify-between gap-3 p-3"
                >
                  <div className="min-w-0 flex-1">
                    <p className="font-mono text-[12px] font-medium truncate">
                      key_{key.hint}
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                      <span className="whitespace-nowrap">
                        {t('Created')}{' '}
                        <DateTooltip
                          date={key.$createdAt}
                          className="text-[11px] text-muted-foreground"
                        />
                      </span>
                      {key.createdByName ? (
                        <span className="whitespace-nowrap">
                          {t('Created by')} {key.createdByName}
                        </span>
                      ) : null}
                      <span className="whitespace-nowrap">
                        {key.lastAccessedAt ? (
                          <>
                            {t('Last used')}{' '}
                            <DateTooltip
                              date={key.lastAccessedAt}
                              className="text-[11px] text-muted-foreground"
                            />
                          </>
                        ) : (
                          t('Never used')
                        )}
                      </span>
                      <CopyableId
                        id={key.$id}
                        copyLabel="Key ID"
                        copyToastLabel="Key ID"
                        variant="inline"
                        size="xs"
                      />
                    </div>
                  </div>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <RowActionsMenuTrigger />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem
                        onClick={() => void copyKey(key.secret)}
                      >
                        <MenuItemContent icon={Copy}>
                          {t('Copy key')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        onClick={() => setDeleteTargetId(key.$id)}
                      >
                        <MenuItemContent icon={Trash2}>
                          {t('Delete')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              ))}
            </div>
          )}
          {hasMore ? (
            <div className="flex justify-center pt-1">
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
        open={createdKey !== null}
        onOpenChange={(open) => {
          if (!open) setCreatedKey(null)
        }}
      >
        <DialogContent
          className={cn(
            'sm:max-w-lg p-0 max-h-[90dvh] flex flex-col overflow-hidden',
            dialogClassName,
          )}
          overlayClassName={dialogClassName}
        >
          <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('App key created')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Copy the key into your server environment. You can copy it again later from the keys list.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="shrink-0 border-t border-border" />
          <div className="flex-1 min-h-0 overflow-y-auto px-6 py-4">
            <div className="min-w-0 overflow-hidden rounded-lg border border-border bg-muted/20">
              <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-3 py-2">
                <div className="flex min-w-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  <Terminal className="h-3.5 w-3.5 shrink-0" />
                  {t('Key value')}
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 shrink-0 px-2 text-[12px]"
                  onClick={() => void handleCopyCreatedKey()}
                >
                  {copiedCreatedKey ? (
                    <Check className="me-1.5 h-3.5 w-3.5 text-emerald-500" />
                  ) : (
                    <Copy className="me-1.5 h-3.5 w-3.5" />
                  )}
                  {t('Copy')}
                </Button>
              </div>
              <pre
                className={cn(
                  'max-h-[min(30dvh,200px)] overflow-auto p-4 font-mono text-[13px] leading-relaxed text-foreground',
                  'break-all whitespace-pre-wrap',
                )}
              >
                {createdKey?.secret}
              </pre>
            </div>
          </div>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
            <Button type="button" onClick={() => setCreatedKey(null)}>
              {t('Done')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog
        open={deleteTargetId !== null}
        onOpenChange={(open) => {
          if (!open && !isDeleting) setDeleteTargetId(null)
        }}
      >
        <DialogContent
          className={cn('sm:max-w-md p-0', dialogClassName)}
          overlayClassName={dialogClassName}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete app key')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Requests signed with this key stop working immediately. This cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteTargetId(null)}
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
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
