import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  AlertTriangle,
  Check,
  Copy,
  Key,
  Loader2,
  Lock,
  Plus,
  Shield,
  Terminal,
  Trash2} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import {
  useCreateOrganizationAppSecret,
  useDeleteOrganizationAppSecret,
  useOrganizationApp,
  useOrganizationAppSecrets} from '@/lib/react-query/hooks'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

function maskClientSecret(hint: string) {
  return `client_secret_${'•'.repeat(18)}${hint}`
}

export function View() {
  const t = useT()
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  const { secrets, isLoading } = useOrganizationAppSecrets(appId)
  const createSecretMutation = useCreateOrganizationAppSecret(appId)
  const deleteSecretMutation = useDeleteOrganizationAppSecret(appId)
  const [newSecretPlaintext, setNewSecretPlaintext] = useState<string | null>(
    null,
  )
  const [copiedNewSecret, setCopiedNewSecret] = useState(false)
  const [deleteTargetId, setDeleteTargetId] = useState<string | null>(null)

  if (!app || !orgId) return null

  if (app.type === 'public') {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-start gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/50">
              <Shield className="h-4 w-4 text-muted-foreground" />
            </div>
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Public client')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                {t(
                  'Public clients use PKCE and do not require OAuth secrets. Switch to a confidential client on the OAuth client tab if you need server-side secret authentication.',
                )}
              </p>
            </div>
          </div>
        </div>
      </div>
    )
  }

  const handleCreateSecret = async () => {
    try {
      const created = await createSecretMutation.mutateAsync()
      setNewSecretPlaintext(created.secret)
      toast.success(t('OAuth secret created'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create OAuth secret')))
    }
  }

  const handleDeleteSecret = async () => {
    if (!deleteTargetId) return
    try {
      await deleteSecretMutation.mutateAsync(deleteTargetId)
      toast.success(t('OAuth secret deleted'))
      setDeleteTargetId(null)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete OAuth secret')))
    }
  }

  const handleCopyNewSecret = async () => {
    if (!newSecretPlaintext) return
    await copyToClipboard('OAuth secret', newSecretPlaintext)
    setCopiedNewSecret(true)
    setTimeout(() => setCopiedNewSecret(false), 2000)
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4 flex items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('OAuth secrets')}
              </h3>
              {!isLoading && secrets.length > 0 && (
                <Badge variant="info" className="text-[10px] shrink-0">
                  {secrets.length} {t('active')}
                </Badge>
              )}
            </div>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Confidential clients authenticate token exchanges with a secret. Rotate regularly and store values in a secrets manager.',
              )}
            </p>
          </div>
          <Button
            size="sm"
            className="h-9 text-[13px] shrink-0"
            disabled={createSecretMutation.isPending}
            onClick={handleCreateSecret}
          >
            <Plus className="me-1.5 h-3.5 w-3.5" />
            {t('Create secret')}
          </Button>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <Alert className="border-border bg-muted/30">
            <Lock className="h-4 w-4 text-muted-foreground" />
            <AlertTitle className="text-[13px] font-medium text-foreground">
              {t('Server-side only')}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-muted-foreground">
              {t(
                'Never embed OAuth secrets in mobile apps, SPAs, or public repositories. Use environment variables such as',
              )}{' '}
              <span className="whitespace-nowrap">
                <code className="rounded bg-muted px-1 py-0.5 font-mono text-[11px] text-foreground">
                  OAUTH_CLIENT_SECRET
                </code>
                .
              </span>
            </AlertDescription>
          </Alert>

          {isLoading ? (
            <div className="flex items-center justify-center py-10">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : secrets.length === 0 ? (
            <EmptyState
              icon={Key}
              title={t('No OAuth secrets')}
              description={t(
                'Create a secret for confidential OAuth flows such as authorization code with server-side token exchange.',
              )}
              variant="card"
            />
          ) : (
            <div className="overflow-hidden rounded-lg border border-border">
              <div className="divide-y divide-border">
                {secrets.map((secret) => (
                  <div
                    key={secret.$id}
                    className="flex items-center justify-between gap-3 p-4"
                  >
                    <div className="min-w-0 flex-1 overflow-hidden">
                      <div className="flex items-center gap-2 min-w-0">
                        <Key className="h-4 w-4 shrink-0 text-muted-foreground" />
                        <p className="text-[13px] font-medium text-foreground truncate min-w-0 font-mono">
                          secret_{secret.hint}
                        </p>
                        <Badge variant="success" className="text-[10px] shrink-0">
                          {t('Active')}
                        </Badge>
                      </div>
                      <div className="mt-2 flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                        <code
                          className="rounded border border-border bg-muted/50 px-2.5 py-1 font-mono text-[12px] text-muted-foreground truncate max-w-full"
                        >
                          {maskClientSecret(secret.hint)}
                        </code>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted-foreground">
                          <span className="whitespace-nowrap">
                            {t('Created')}{' '}
                            <DateTooltip
                              date={secret.$createdAt}
                              className="text-[12px] text-muted-foreground"
                            />
                          </span>
                          <CopyableId
                            id={secret.$id}
                            copyLabel="Secret ID"
                            copyToastLabel="Secret ID"
                            variant="inline"
                            size="xs"
                          />
                        </div>
                      </div>
                    </div>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          onClick={() => setDeleteTargetId(secret.$id)}
                        >
                          <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      <Dialog
        open={newSecretPlaintext !== null}
        onOpenChange={(open) => {
          if (!open) setNewSecretPlaintext(null)
        }}
      >
        <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] flex flex-col overflow-hidden">
          <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('OAuth secret created')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Copy this value now. For security, the full secret cannot be retrieved after you close this dialog.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="shrink-0 border-t border-border" />
          <div className="flex-1 min-h-0 overflow-y-auto px-6 py-4 space-y-4">
            <Alert className="border-amber-500/20 bg-amber-500/10 text-foreground">
              <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
              <AlertTitle className="text-[13px] font-medium">
                {t('One-time display')}
              </AlertTitle>
              <AlertDescription className="text-[12px] text-muted-foreground">
                {t(
                  'Store this secret in your deployment environment before continuing. Active sessions using a deleted secret will fail token refresh immediately.',
                )}
              </AlertDescription>
            </Alert>

            <div className="min-w-0 overflow-hidden rounded-lg border border-border bg-muted/20">
              <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-3 py-2">
                <div className="flex min-w-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  <Terminal className="h-3.5 w-3.5 shrink-0" />
                  {t('Secret value')}
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 shrink-0 px-2 text-[12px]"
                  onClick={() => void handleCopyNewSecret()}
                >
                  {copiedNewSecret ? (
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
                  'selection:bg-primary/20',
                )}
              >
                {newSecretPlaintext}
              </pre>
            </div>
          </div>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
            <Button onClick={() => setNewSecretPlaintext(null)}>{t('Done')}</Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog
        open={deleteTargetId !== null}
        onOpenChange={(open) => {
          if (!open) setDeleteTargetId(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Delete OAuth secret')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Token refresh and authorization flows using this secret will stop working immediately. This cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            {deleteTargetId && (
              <div className="rounded-lg border border-border bg-muted/30 px-4 py-3">
                <p className="text-[12px] font-medium uppercase tracking-wider text-muted-foreground">
                  {t('Secret ID')}
                </p>
                <p className="mt-1 font-mono text-[13px] text-foreground break-all">
                  {deleteTargetId}
                </p>
              </div>
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteTargetId(null)}
              disabled={deleteSecretMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              disabled={deleteSecretMutation.isPending}
              onClick={() => void handleDeleteSecret()}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
