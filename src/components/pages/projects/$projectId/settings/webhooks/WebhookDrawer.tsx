import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  useCreateWebhook,
  useUpdateWebhook,
  useProjectWebhook,
  useUpdateWebhookSecret,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import {
  Trash2,
  RefreshCw,
  ExternalLink,
  Copy,
  Check,
  Info,
} from 'lucide-react'
import { EventSelector } from './EventSelector'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

function getWebhookSecret(webhook: Models.Webhook | null | undefined) {
  if (!webhook) return ''
  const record = webhook as unknown as Record<string, unknown>
  return String(record.secret || '')
}

function CopyableSecret({ value }: { value: string }) {
  const t = useT()
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    await navigator.clipboard.writeText(value)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="flex min-w-0 items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2">
      <code className="min-w-0 flex-1 break-all font-mono text-[12px] leading-5 text-foreground">
        {value}
      </code>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="h-7 w-7 shrink-0"
        onClick={handleCopy}
        aria-label={t('Copy secret')}
>
        {copied ? (
          <Check className="h-3.5 w-3.5 text-emerald-500" />
        ) : (
          <Copy className="h-3.5 w-3.5" />
        )}
      </Button>
    </div>
  )
}

interface WebhookDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  webhook?: Models.Webhook | null
  onSuccess?: () => void
  onDelete?: (webhook: Models.Webhook) => void
}

export function WebhookDrawer({
  open,
  onOpenChange,
  projectId,
  webhook,
  onSuccess,
  onDelete,
}: WebhookDrawerProps) {
  const t = useT()
  const isEditing = !!webhook

  const createMutation = useCreateWebhook(projectId)
  const updateMutation = useUpdateWebhook(projectId)
  const regenerateSignatureMutation = useUpdateWebhookSecret(projectId)
  const { webhook: fullWebhook } = useProjectWebhook(
    projectId,
    isEditing ? webhook?.$id : null,
  )
  const isPending =
    createMutation.isPending ||
    updateMutation.isPending ||
    regenerateSignatureMutation.isPending

  const [name, setName] = useState('')
  const [url, setUrl] = useState('')
  const [events, setEvents] = useState<string[]>([])
  const [enabled, setEnabled] = useState(true)
  const [authUsername, setAuthUsername] = useState('')
  const [authPassword, setAuthPassword] = useState('')
  const [tls, setTls] = useState(true)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [secretDialogOpen, setSecretDialogOpen] = useState(false)
  const [secretDialogMode, setSecretDialogMode] = useState<'create' | 'rotate'>(
    'rotate',
  )
  const [customSecret, setCustomSecret] = useState('')
  const [revealedSecret, setRevealedSecret] = useState('')

  useEffect(() => {
    if (!open) {
      setName('')
      setUrl('')
      setEvents([])
      setEnabled(true)
      setAuthUsername('')
      setAuthPassword('')
      setTls(true)
      setErrors({})
      setCustomSecret('')
      return
    }
    const source = fullWebhook ?? webhook
    if (source) {
      setName(source.name || '')
      setUrl(source.url || '')
      setEvents(source.events || [])
      setEnabled(source.enabled ?? true)
      setAuthUsername(source.authUsername || '')
      setAuthPassword('')
      setTls(source.tls ?? true)
      setErrors({})
      setCustomSecret('')
    }
  }, [open, webhook, fullWebhook])

  const canSubmit = !!name.trim() && !!url.trim()
  const submitDisabledReason = !name.trim()
    ? t('Enter a webhook name.')
    : !url.trim()
      ? t('Enter a webhook URL.')
      : undefined

  const handleOpenChange = (newOpen: boolean) => {
    if (!isPending) {
      onOpenChange(newOpen)
    }
  }

  const openRotateSecretDialog = () => {
    if (!webhook) return
    setSecretDialogMode('rotate')
    setCustomSecret('')
    setRevealedSecret('')
    setSecretDialogOpen(true)
  }

  const handleRotateSecret = async () => {
    if (!webhook) return

    try {
      const updatedWebhook = await regenerateSignatureMutation.mutateAsync({
        webhookId: webhook.$id,
        secret: customSecret,
      })
      const secret = getWebhookSecret(updatedWebhook)
      setRevealedSecret(secret)
      toast.success(
        customSecret.trim()
          ? t('Webhook secret updated.')
          : t('Webhook secret rotated.'),
      )
    } catch (error) {
      toast.error(getErrorMessage(error as Error, t('Failed to rotate secret')))
    }
  }

  const handleSecretContinue = () => {
    setSecretDialogOpen(false)
    setCustomSecret('')
    setRevealedSecret('')

    if (secretDialogMode === 'create') {
      handleOpenChange(false)
      onSuccess?.()
    }
  }

  const handleRequestDelete = () => {
    if (!webhook || !onDelete || isPending) return
    handleOpenChange(false)
    openDialogAfterOverlayCloses(() => onDelete(webhook))
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}
    if (!name.trim()) newErrors.name = t('Name is required')
    if (!url.trim()) newErrors.url = t('URL is required')
    if (events.length === 0) newErrors.events = t('Select at least one event')
    if (events.length > 100) newErrors.events = t('Maximum 100 events allowed')
    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!validate() || !canSubmit) return

    if (isEditing && webhook) {
      updateMutation.mutate(
        {
          webhookId: webhook.$id,
          name: name.trim(),
          url: url.trim(),
          events,
          tls,
          enabled,
          authUsername: authUsername.trim() || undefined,
          authPassword: authPassword.trim() || undefined,
        },
        {
          onSuccess: () => {
            toast.success(t('Webhook has been updated'))
            handleOpenChange(false)
            onSuccess?.()
          },
          onError: (error: Error) => {
            toast.error(getErrorMessage(error) || t('Failed to update webhook'))
          },
        },
      )
    } else {
      createMutation.mutate(
        {
          name: name.trim(),
          url: url.trim(),
          events,
          tls,
          enabled: true,
          authUsername: authUsername.trim() || undefined,
          authPassword: authPassword.trim() || undefined,
        },
        {
          onSuccess: (createdWebhook) => {
            const secret = getWebhookSecret(createdWebhook)
            toast.success(
              secret
                ? t('Webhook created. Secret ready to copy.')
                : t('Webhook has been created'),
            )

            if (secret) {
              setSecretDialogMode('create')
              setCustomSecret('')
              setRevealedSecret(secret)
              onOpenChange(false)
              openDialogAfterOverlayCloses(() => setSecretDialogOpen(true))
              return
            }

            handleOpenChange(false)
            onSuccess?.()
          },
          onError: (error: Error) => {
            toast.error(getErrorMessage(error) || t('Failed to create webhook'))
          },
        },
      )
    }
  }

  return (
    <>
      <BaseDrawer
        open={open}
        onOpenChange={handleOpenChange}
        title={isEditing ? t('Update webhook') : t('Create webhook')}
        maxWidth="sm:max-w-2xl"
>
        <>
          <div className="border-t border-border shrink-0" />

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col min-h-0"
>
            <div className="flex-1 overflow-y-auto">
              <div className="px-6 py-6 space-y-5">
                <div className="space-y-2">
                  <Label
                    htmlFor="webhook-name"
                    className="text-[12px] font-medium"
>
                    {t('Name')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="webhook-name"
                    placeholder={t('Enter webhook name')}
                    value={name}
                    onChange={(e) => {
                      setName(e.target.value)
                      if (errors.name)
                        setErrors((prev) => ({ ...prev, name: '' }))
                    }}
                    disabled={isPending}
                    className={errors.name ? 'border-destructive' : ''}
                  />
                  {errors.name && (
                    <p className="text-[12px] text-destructive">
                      {errors.name}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label
                    htmlFor="webhook-url"
                    className="text-[12px] font-medium"
>
                    {t('POST URL')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="webhook-url"
                    type="url"
                    placeholder="https://example.com/callback"
                    value={url}
                    onChange={(e) => {
                      setUrl(e.target.value)
                      if (errors.url)
                        setErrors((prev) => ({ ...prev, url: '' }))
                    }}
                    disabled={isPending}
                    className={errors.url ? 'border-destructive' : ''}
                  />
                  {errors.url && (
                    <p className="text-[12px] text-destructive">{errors.url}</p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label className="text-[12px] font-medium">
                    {t('Events')} <span className="text-destructive">*</span>
                  </Label>
                  <EventSelector
                    projectId={projectId}
                    selectedEvents={events}
                    onEventsChange={(e) => {
                      setEvents(e)
                      if (errors.events)
                        setErrors((prev) => ({ ...prev, events: '' }))
                    }}
                    maxEvents={100}
                  />
                  {errors.events && (
                    <p className="text-[12px] text-destructive">
                      {errors.events}
                    </p>
                  )}
                </div>

                {isEditing && (
                  <div className="flex items-center space-x-2">
                    <Checkbox
                      id="webhook-enabled"
                      checked={enabled}
                      onCheckedChange={(checked) =>
                        setEnabled(checked === true)
                      }
                      disabled={isPending}
                    />
                    <Label
                      htmlFor="webhook-enabled"
                      className="text-[13px] font-normal leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
>
                      {t('Enabled')}
                    </Label>
                  </div>
                )}

                <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                  <div className="px-6 py-4">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      {t('Security')}
                    </h3>
                  </div>
                  <div className="border-t border-border" />
                  <div className="px-6 py-4 space-y-4">
                    <div className="space-y-2">
                      <Label
                        htmlFor="webhook-authUsername"
                        className="text-[12px] font-medium text-muted-foreground"
>
                        {t('User')}
                      </Label>
                      <Input
                        id="webhook-authUsername"
                        placeholder={t('Enter username')}
                        value={authUsername}
                        onChange={(e) => setAuthUsername(e.target.value)}
                        disabled={isPending}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label
                        htmlFor="webhook-authPassword"
                        className="text-[12px] font-medium text-muted-foreground"
>
                        {t('Password')}
                      </Label>
                      <Input
                        id="webhook-authPassword"
                        type="password"
                        placeholder={
                          isEditing
                            ? t('Leave blank to keep existing')
                            : t('Enter password')
                        }
                        value={authPassword}
                        onChange={(e) => setAuthPassword(e.target.value)}
                        disabled={isPending}
                      />
                    </div>
                    <div className="flex items-center space-x-2">
                      <Checkbox
                        id="webhook-tls"
                        checked={tls}
                        onCheckedChange={(checked) => setTls(checked === true)}
                        disabled={isPending}
                      />
                      <Label
                        htmlFor="webhook-tls"
                        className="text-[13px] font-normal leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
>
                        {t('Certificate verification (SSL/TLS)')}
                      </Label>
                    </div>
                    <p className="text-[13px] text-muted-foreground">
                      {t(
                        'Set an optional basic HTTP authentication username and password to protect your endpoint from unauthorized access.',
                      )}
                    </p>
                  </div>
                </div>

                {isEditing && (
                  <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                    <div className="px-6 py-4">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        {t('Webhook secret')}
                      </h3>
                      <p className="mt-2 text-[13px] text-muted-foreground">
                        {t(
                          'Used to validate incoming webhook payloads with the X-Appwrite-Webhook-Signature header.', // pragma: allowlist secret
                        )}{' '}
                        <DocsRouteLink className="link-neutral inline-flex items-center gap-1" href="/docs/advanced/platform/webhooks#verification">
                          {t('Learn more')}
                          <ExternalLink className="h-3 w-3 shrink-0" />
                        </DocsRouteLink>
                      </p>
                    </div>
                    <div className="border-t border-border" />
                    <div className="px-6 py-4">
                      <div className="flex min-w-0 items-start gap-3 rounded-md border border-border bg-muted/20 px-3 py-2.5 text-[13px] text-muted-foreground">
                        <Info className="mt-0.5 h-4 w-4 shrink-0" />
                        <p>
                          {t(
                            'This secret is only shown once after webhook creation or secret rotation.',
                          )}
                        </p>
                      </div>
                    </div>
                    <div className="flex justify-end px-6 py-4 border-t border-border bg-muted/30">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={openRotateSecretDialog}
                        disabled={regenerateSignatureMutation.isPending}
>
                        <RefreshCw
                          className={`me-1.5 h-4 w-4 ${regenerateSignatureMutation.isPending ? 'animate-spin' : ''}`}
                        />
                        {t('Rotate secret')}
                      </Button>
                    </div>
                  </div>
                )}

                {isEditing && onDelete && (
                  <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden mt-6">
                    <div className="px-6 py-4">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        {t('Delete webhook')}
                      </h3>
                    </div>
                    <div className="border-t border-destructive/20" />
                    <div className="px-6 py-4">
                      <p className="text-[13px] text-muted-foreground">
                        {t(
                          'Permanently delete this webhook. It will stop receiving events immediately. This action cannot be undone.',
                        )}
                      </p>
                    </div>
                    <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                      <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={handleRequestDelete}
                        disabled={isPending}
>
                        <Trash2 className="me-1.5 h-4 w-4" />
                        {t('Delete webhook')}
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            </div>

            <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
              {!canSubmit && submitDisabledReason ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">
                      <Button
                        type="submit"
                        disabled
                        aria-describedby="webhook-submit-disabled-reason"
>
                        {isEditing ? t('Update') : t('Create webhook')}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent
                    id="webhook-submit-disabled-reason"
                    side="top"
>
                    {submitDisabledReason}
                  </TooltipContent>
                </Tooltip>
              ) : (
                <Button
                  type="submit"
                  disabled={isPending}
>
                  {isEditing ? t('Update') : t('Create webhook')}
                </Button>
              )}
              <Button
                type="button"
                variant="outline"
                onClick={() => handleOpenChange(false)}
                disabled={isPending}
>
                {t('Cancel')}
              </Button>
            </div>
          </form>
        </>
      </BaseDrawer>

      <Dialog
        open={secretDialogOpen}
        onOpenChange={(nextOpen) => {
          if (!regenerateSignatureMutation.isPending) {
            setSecretDialogOpen(nextOpen)
          }
        }}
>
        <DialogContent
          className="sm:max-w-md p-0 z-[130] overflow-hidden"
          overlayClassName="z-[130]"
>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>
              {revealedSecret
                ? secretDialogMode === 'create'
                  ? t('Webhook created')
                  : t('Webhook secret rotated')
                : t('Rotate webhook secret')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {revealedSecret
                ? t(
                    'This secret is only shown once after webhook creation or secret rotation. Copy it now.',
                  )
                : t(
                    'Leave this empty to rotate the webhook secret automatically, or enter a value to set a custom secret.',
                  )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="min-w-0 px-6 py-4">
            {revealedSecret ? (
              <CopyableSecret value={revealedSecret} />
            ) : (
              <div className="space-y-2">
                <Label
                  htmlFor="webhook-secret"
                  className="text-[12px] font-medium"
>
                  {t('Secret')}
                </Label>
                <Input
                  id="webhook-secret"
                  type="password"
                  placeholder={t('Leave empty to auto-generate')}
                  value={customSecret}
                  onChange={(e) => setCustomSecret(e.target.value)}
                  disabled={regenerateSignatureMutation.isPending}
                  autoComplete="new-password"
                />
                <p className="text-[12px] text-muted-foreground">
                  {t('Used to validate incoming webhook payloads.')}
                </p>
              </div>
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            {revealedSecret ? (
              <Button
                type="button"
                size="sm"
                className="h-9 text-[13px]"
                onClick={handleSecretContinue}
>
                {t('Continue')}
              </Button>
            ) : (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setSecretDialogOpen(false)}
                  disabled={regenerateSignatureMutation.isPending}
>
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleRotateSecret}
                  disabled={regenerateSignatureMutation.isPending}
>
                  {customSecret.trim() ? t('Set custom secret') : t('Rotate secret')}
                </Button>
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
