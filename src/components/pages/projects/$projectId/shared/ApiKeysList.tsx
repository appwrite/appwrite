import { useState } from 'react'
import {
  Key,
  Eye,
  Copy,
  Check,
  MoreHorizontal,
  Calendar,
  Clock,
  CalendarClock,
} from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { cn } from '@/lib/utils'

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

function ApiKeyMetaStrip({
  createdAt,
  lastUsed,
  expire,
  className,
}: {
  createdAt: string
  lastUsed: string | null
  expire: string | null
  className?: string
}) {
  const expirationStatus = getExpirationStatus(expire)

  return (
    <div
      className={cn(
        'flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted-foreground',
        className,
      )}
    >
      <span className="inline-flex items-center gap-1" title="Created">
        <Calendar
          className="h-3.5 w-3.5 shrink-0 text-muted-foreground/50"
          aria-hidden
        />
        <span className="sr-only">Created </span>
        <DateTooltip
          date={createdAt}
          className="text-[11px] text-muted-foreground"
        />
      </span>
      <span
        className="text-muted-foreground/30 select-none"
        aria-hidden
      >
        ·
      </span>
      <span className="inline-flex items-center gap-1" title="Last used">
        <Clock
          className="h-3.5 w-3.5 shrink-0 text-muted-foreground/50"
          aria-hidden
        />
        <span className="sr-only">Last used </span>
        {lastUsed ? (
          <DateTooltip
            date={lastUsed}
            className="text-[11px] text-muted-foreground"
          />
        ) : (
          <span>Never</span>
        )}
      </span>
      <span
        className="text-muted-foreground/30 select-none"
        aria-hidden
      >
        ·
      </span>
      <span
        className="inline-flex items-center gap-1 min-w-0"
        title={expire ? 'Expiration' : 'No expiration date'}
      >
        <CalendarClock
          className="h-3.5 w-3.5 shrink-0 text-muted-foreground/50"
          aria-hidden
        />
        {expire ? (
          <>
            {expirationStatus?.isExpired ? (
              <span className="text-destructive/90">Expired </span>
            ) : (
              <span className="text-muted-foreground/75">Expires </span>
            )}
            <DateTooltip
              date={expire}
              className="text-[11px] text-muted-foreground"
            />
          </>
        ) : (
          <span>No expiration</span>
        )}
      </span>
    </div>
  )
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
}: ApiKeysListProps) {
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
        <p className="text-[13px] text-muted-foreground">Loading API keys...</p>
      </div>
    )
  }

  if (apiKeys.length === 0) {
    return (
      <EmptyState
        icon={Key}
        title="No API keys found"
        description="Create your first API key to authenticate your applications"
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
            return (
              <div
                key={apiKey.id}
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
                        Expired
                      </Badge>
                    ) : expirationStatus?.isExpiringSoon ? (
                      <Badge variant="warning" className="text-[10px] shrink-0">
                        Expires soon
                      </Badge>
                    ) : null}
                    <Badge variant="info" className="text-[10px] shrink-0">
                      {apiKey.scopes.length === 0
                        ? 'No scopes'
                        : `${apiKey.scopes.length} scope${apiKey.scopes.length !== 1 ? 's' : ''}`}
                    </Badge>
                  </div>
                  <div className="mt-1.5 flex items-center gap-2 min-w-0 overflow-hidden">
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
                      title="View key"
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
                      title="Copy key"
                    >
                      {copiedField === `apiKey-${apiKey.id}` ? (
                        <Check className="h-3.5 w-3.5 text-emerald-500" />
                      ) : (
                        <Copy className="h-3.5 w-3.5" />
                      )}
                    </button>
                  </div>
                  <ApiKeyMetaStrip
                    createdAt={apiKey.createdAt}
                    lastUsed={apiKey.lastUsed}
                    expire={apiKey.expire}
                    className="mt-2 pl-6"
                  />
                </div>
                {showActions && (onUpdate || onDelete) && (
                  <div data-api-key-action onClick={(e) => e.stopPropagation()}>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <button
                          type="button"
                          className="cursor-pointer rounded p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground shrink-0"
                        >
                          <MoreHorizontal className="h-4 w-4" />
                        </button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        {onUpdate && (
                          <DropdownMenuItem onClick={() => onUpdate(apiKey.id)}>
                            Update
                          </DropdownMenuItem>
                        )}
                        {onDelete && (
                          <DropdownMenuItem
                            onClick={() => onDelete(apiKey.id)}
                            className="text-destructive focus:text-destructive"
                          >
                            Delete
                          </DropdownMenuItem>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      </div>

      {/* API Key View Modal */}
      <Dialog
        open={viewingKeyId !== null}
        onOpenChange={(open) => !open && setViewingKeyId(null)}
      >
        <DialogContent className="sm:max-w-[600px] p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>{viewingKey?.name || 'API Key'}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Copy the full API key below. Keep it secure and never share it
              publicly.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-0">
            {viewingKey && (
              <ApiKeyMetaStrip
                createdAt={viewingKey.createdAt}
                lastUsed={viewingKey.lastUsed}
                expire={viewingKey.expire}
                className="mb-4"
              />
            )}
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground">
                API Key
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
              Close
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
                  Copied
                </>
              ) : (
                <>
                  <Copy className="h-4 w-4" />
                  Copy
                </>
              )}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
