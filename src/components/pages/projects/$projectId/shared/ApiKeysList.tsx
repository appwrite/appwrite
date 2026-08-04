import { useState, Fragment } from 'react'
import { Key, Eye, Copy, Check, Pencil, Trash2 } from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { cn } from '@/lib/utils'
import { ApiKeyContextMenu } from '../api-keys/_components/ApiKeyContextMenu'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

export interface ApiKey {
  id: string
  name: string
  key: string
  scopes: string[]
  createdAt: string
  lastUsed: string | null
  expire: string | null
}

interface ApiKeysListProps {
  apiKeys: ApiKey[]
  isLoading?: boolean
  onView?: (keyId: string) => void
  onUpdate?: (keyId: string) => void
  onDelete?: (keyId: string) => void
  onCopy?: (key: string, field: string) => void
  copiedField?: string | null
  showActions?: boolean
  /** When set, wrap each row with a right-click context menu */
  projectId?: string
}

function getExpirationStatus(expire: string | null) {
  if (!expire) return null

  const now = new Date()
  const expireDate = new Date(expire)
  const isExpired = expireDate < now
  const isExpiringSoon =
    !isExpired &&
    expireDate.getTime() - now.getTime() <= 7 * 24 * 60 * 60 * 1000 // 7 days

  return { isExpired, isExpiringSoon, expireDate }
}

export function ApiKeysList({
  apiKeys,
  isLoading = false,
  onView,
  onUpdate,
  onDelete,
  onCopy,
  copiedField,
  showActions = true,
  projectId}: ApiKeysListProps) {
  const t = useT()
  const [viewingKeyId, setViewingKeyId] = useState<string | null>(null)

  const maskKey = (key: string) => {
    return key.slice(0, 7) + '•'.repeat(24) + key.slice(-4)
  }

  const viewingKey = apiKeys.find((key) => key.id === viewingKeyId)

  const handleView = (keyId: string) => {
    if (onView) {
      onView(keyId)
    } else {
      setViewingKeyId(keyId)
    }
  }

  const handleCopy = (key: string, field: string) => {
    if (onCopy) {
      onCopy(key, field)
    } else {
      navigator.clipboard.writeText(key)
    }
  }

  if (isLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading API keys...')}
        </p>
      </div>
    )
  }

  if (apiKeys.length === 0) {
    return (
      <EmptyState
        icon={Key}
        title={t('No API keys found')}
        description={t(
          'Create your first API key to authenticate your applications',
        )}
        isEmpty={true}
        variant="card"
      />
    )
  }

  return (
    <>
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="divide-y divide-border">
          {apiKeys.map((apiKey) => {
            const expirationStatus = getExpirationStatus(apiKey.expire)
            const row = (
              <div
                role={onUpdate ? 'button' : undefined}
                tabIndex={onUpdate ? 0 : undefined}
                onClick={onUpdate ? () => onUpdate(apiKey.id) : undefined}
                onKeyDown={
                  onUpdate
                    ? (e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                          e.preventDefault()
                          onUpdate(apiKey.id)
                        }
                      }
                    : undefined
                }
                className={cn(
                  'flex items-center justify-between gap-3 p-4 overflow-hidden',
                  onUpdate &&
                    'cursor-pointer transition-colors hover:bg-muted/50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                )}
              >
                <div className="min-w-0 flex-1 overflow-hidden">
                  <div className="flex items-center gap-2 min-w-0">
                    <Key className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <p className="text-[14px] font-medium text-foreground truncate min-w-0">
                      {apiKey.name}
                    </p>
                    {expirationStatus?.isExpired ? (
                      <Badge variant="error" className="text-[10px] shrink-0">
                        {t('Expired')}
                      </Badge>
                    ) : expirationStatus?.isExpiringSoon ? (
                      <Badge variant="warning" className="text-[10px] shrink-0">
                        {t('Expires soon')}
                      </Badge>
                    ) : null}
                    <Badge variant="info" className="text-[10px] shrink-0">
                      {apiKey.scopes.length === 0
                        ? t('No scopes')
                        : `${apiKey.scopes.length} ${apiKey.scopes.length !== 1 ? t('scopes') : t('scope')}`}
                    </Badge>
                  </div>
                  <div className="mt-1.5 flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                    <div className="flex min-w-0 items-center gap-2 overflow-hidden">
                      <code className="rounded bg-muted px-2 py-0.5 font-mono text-[12px] text-muted-foreground truncate max-w-[200px] sm:max-w-none">
                        {maskKey(apiKey.key)}
                      </code>
                      <button
                        type="button"
                        data-api-key-action
                        onClick={(e) => {
                          e.stopPropagation()
                          handleView(apiKey.id)
                        }}
                        className="cursor-pointer rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground shrink-0"
                        title={t('View key')}
                      >
                        <Eye className="h-3.5 w-3.5" />
                      </button>
                      <button
                        type="button"
                        data-api-key-action
                        onClick={(e) => {
                          e.stopPropagation()
                          handleCopy(apiKey.key, `apiKey-${apiKey.id}`)
                        }}
                        className="cursor-pointer rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground shrink-0"
                        title={t('Copy key')}
                      >
                        {copiedField === `apiKey-${apiKey.id}` ? (
                          <Check className="h-3.5 w-3.5 text-emerald-500" />
                        ) : (
                          <Copy className="h-3.5 w-3.5" />
                        )}
                      </button>
                    </div>
                    <div className="flex w-full shrink-0 flex-wrap items-center justify-end gap-x-2 sm:w-auto sm:gap-x-3">
                      <span className="text-[12px] text-muted-foreground whitespace-nowrap hidden sm:inline">
                        {t('Created')}{' '}
                        <DateTooltip
                          date={apiKey.createdAt}
                          className="text-[12px] text-muted-foreground"
                        />
                      </span>
                      <span className="text-[12px] text-muted-foreground whitespace-nowrap hidden md:inline">
                        {t('Last used')}{' '}
                        {apiKey.lastUsed ? (
                          <DateTooltip
                            date={apiKey.lastUsed}
                            className="text-[12px] text-muted-foreground"
                          />
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                            {t('Never')}
                          </span>
                        )}
                      </span>
                      <span className="text-[12px] text-muted-foreground whitespace-nowrap hidden md:inline">
                        {apiKey.expire ? (
                          <>
                            {expirationStatus?.isExpired
                              ? t('Expired')
                              : t('Expires')}{' '}
                            <DateTooltip
                              date={apiKey.expire}
                              className="text-[12px] text-muted-foreground"
                            />
                          </>
                        ) : (
                          t('No expiration')
                        )}
                      </span>
                    </div>
                  </div>
                </div>
                {showActions && (onUpdate || onDelete) && (
                  <div data-api-key-action onClick={(e) => e.stopPropagation()}>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger
                          onClick={(e) => e.stopPropagation()}
                        />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        {onUpdate && (
                          <DropdownMenuItem
                            onSelect={() =>
                              openDialogAfterOverlayCloses(() =>
                                onUpdate(apiKey.id),
                              )
                            }
                          >
                            <MenuItemContent icon={Pencil}>
                              {t('Update')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                        )}
                        {onDelete && (
                          <DropdownMenuItem
                            onSelect={() =>
                              openDialogAfterOverlayCloses(() =>
                                onDelete(apiKey.id),
                              )
                            }
                          >
                            <MenuItemContent icon={Trash2}>
                              {t('Delete')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                )}
              </div>
            )
            if (projectId) {
              return (
                <ApiKeyContextMenu
                  key={apiKey.id}
                  projectId={projectId}
                  apiKey={{
                    id: apiKey.id,
                    name: apiKey.name,
                    key: apiKey.key,
                    scopes: apiKey.scopes,
                    expire: apiKey.expire}}
                  onUpdate={onUpdate}
                >
                  {row}
                </ApiKeyContextMenu>
              )
            }
            return <Fragment key={apiKey.id}>{row}</Fragment>
          })}
        </div>
      </div>

      {/* API Key View Modal */}
      <Dialog
        open={viewingKeyId !== null}
        onOpenChange={(open) => !open && setViewingKeyId(null)}
      >
        <DialogContent className="sm:max-w-[600px] p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{viewingKey?.name || t('API Key')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Copy the full API key below. Keep it secure and never share it publicly.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-0">
            {viewingKey && (
              <div className="mb-4 flex w-full flex-wrap items-center justify-end gap-x-2 sm:gap-x-3 text-[12px] text-muted-foreground">
                <span className="whitespace-nowrap">
                  {t('Created')}{' '}
                  <DateTooltip
                    date={viewingKey.createdAt}
                    className="text-[12px] text-muted-foreground"
                  />
                </span>
                <span className="whitespace-nowrap">
                  {t('Last used')}{' '}
                  {viewingKey.lastUsed ? (
                    <DateTooltip
                      date={viewingKey.lastUsed}
                      className="text-[12px] text-muted-foreground"
                    />
                  ) : (
                    <span className="text-[12px] text-muted-foreground">
                      {t('Never')}
                    </span>
                  )}
                </span>
                <span className="whitespace-nowrap">
                  {viewingKey.expire ? (
                    <>
                      {getExpirationStatus(viewingKey.expire)?.isExpired
                        ? t('Expired')
                        : t('Expires')}{' '}
                      <DateTooltip
                        date={viewingKey.expire}
                        className="text-[12px] text-muted-foreground"
                      />
                    </>
                  ) : (
                    t('No expiration')
                  )}
                </span>
              </div>
            )}
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground">
                {t('API Key')}
              </label>
              <textarea
                readOnly
                value={viewingKey?.key || ''}
                className="w-full min-h-[100px] rounded-md border border-border bg-muted px-3 py-2 font-mono text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                onClick={(e) => (e.target as HTMLTextAreaElement).select()}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => setViewingKeyId(null)}>
              {t('Close')}
            </Button>
            <Button
              variant="outline"
              onClick={() => {
                if (viewingKey?.key) {
                  handleCopy(viewingKey.key, 'apiKeyModal')
                }
              }}
              className="gap-2"
            >
              {copiedField === 'apiKeyModal' ? (
                <>
                  <Check className="h-4 w-4" />
                  {t('Copied')}
                </>
              ) : (
                <>
                  <Copy className="h-4 w-4" />
                  {t('Copy')}
                </>
              )}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
