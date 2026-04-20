import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
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
  useDeleteWebhook,
  useProjectWebhook,
  useUpdateWebhookSecret,
} from '@/lib/react-query/hooks'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { toast } from 'sonner'
import { Trash2, RefreshCw, ExternalLink } from 'lucide-react'
import { EventSelector } from './EventSelector'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'

interface WebhookDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  webhook?: Models.Webhook | null
  onSuccess?: () => void
}

export function WebhookDrawer({
  open,
  onOpenChange,
  projectId,
  webhook,
  onSuccess,
}: WebhookDrawerProps) {
  const isEditing = !!webhook

  const createMutation = useCreateWebhook(projectId)
  const updateMutation = useUpdateWebhook(projectId)
  const deleteMutation = useDeleteWebhook(projectId)
  const regenerateSignatureMutation = useUpdateWebhookSecret(projectId)
  const { webhook: fullWebhook } = useProjectWebhook(
    projectId,
    isEditing ? webhook?.$id : null,
  )
  const isPending =
    createMutation.isPending ||
    updateMutation.isPending ||
    deleteMutation.isPending ||
    regenerateSignatureMutation.isPending

  const [name, setName] = useState('')
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [url, setUrl] = useState('')
  const [events, setEvents] = useState<string[]>([])
  const [enabled, setEnabled] = useState(true)
  const [httpUser, setHttpUser] = useState('')
  const [httpPass, setHttpPass] = useState('')
  const [security, setSecurity] = useState(true)
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    if (!open) {
      setName('')
      setUrl('')
      setEvents([])
      setEnabled(true)
      setHttpUser('')
      setHttpPass('')
      setSecurity(true)
      setErrors({})
      setDeleteConfirmOpen(false)
      return
    }
    const source = fullWebhook ?? webhook
    if (source) {
      setName(source.name || '')
      setUrl(source.url || '')
      setEvents(source.events || [])
      setEnabled(source.enabled ?? true)
      setHttpUser(source.authUsername || '')
      setHttpPass('')
      setSecurity(source.tls ?? true)
      setErrors({})
    }
  }, [open, webhook, fullWebhook])

  const canSubmit =
    name.trim() && url.trim() && events.length > 0 && events.length <= 100

  const handleOpenChange = (newOpen: boolean) => {
    if (!isPending) {
      onOpenChange(newOpen)
      if (!newOpen) setDeleteConfirmOpen(false)
    }
  }

  const handleRegenerateSignature = () => {
    if (!webhook) return
    regenerateSignatureMutation.mutate(webhook.$id, {
      onSuccess: () => {
        toast.success('Signature key has been regenerated')
      },
      onError: (error: Error) => {
        toast.error(
          getErrorMessage(error) || 'Failed to regenerate signature key',
        )
      },
    })
  }

  const handleDelete = () => {
    if (!webhook) return
    deleteMutation.mutate(webhook.$id, {
      onSuccess: () => {
        toast.success('Webhook has been deleted')
        setDeleteConfirmOpen(false)
        handleOpenChange(false)
        onSuccess?.()
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete webhook')
      },
    })
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}
    if (!name.trim()) newErrors.name = 'Name is required'
    if (!url.trim()) newErrors.url = 'URL is required'
    if (events.length === 0) newErrors.events = 'Select at least one event'
    if (events.length > 100) newErrors.events = 'Maximum 100 events allowed'
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
          tls: security,
          enabled,
          authUsername: httpUser.trim() || undefined,
          authPassword: httpPass.trim() || undefined,
        },
        {
          onSuccess: () => {
            toast.success('Webhook has been updated')
            handleOpenChange(false)
            onSuccess?.()
          },
          onError: (error: Error) => {
            toast.error(getErrorMessage(error) || 'Failed to update webhook')
          },
        },
      )
    } else {
      createMutation.mutate(
        {
          name: name.trim(),
          url: url.trim(),
          events,
          tls: security,
          enabled: true,
          authUsername: httpUser.trim() || undefined,
          authPassword: httpPass.trim() || undefined,
        },
        {
          onSuccess: () => {
            toast.success('Webhook has been created')
            handleOpenChange(false)
            onSuccess?.()
          },
          onError: (error: Error) => {
            toast.error(getErrorMessage(error) || 'Failed to create webhook')
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
        title={isEditing ? 'Update webhook' : 'Create webhook'}
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
                    Name <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="webhook-name"
                    placeholder="Enter webhook name"
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
                    POST URL <span className="text-destructive">*</span>
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
                    Events <span className="text-destructive">*</span>
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
                      Enabled
                    </Label>
                  </div>
                )}

                <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                  <div className="px-6 py-4">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      Security
                    </h3>
                  </div>
                  <div className="border-t border-border" />
                  <div className="px-6 py-4 space-y-4">
                    <div className="space-y-2">
                      <Label
                        htmlFor="webhook-httpUser"
                        className="text-[12px] font-medium text-muted-foreground"
                      >
                        User
                      </Label>
                      <Input
                        id="webhook-httpUser"
                        placeholder="Enter username"
                        value={httpUser}
                        onChange={(e) => setHttpUser(e.target.value)}
                        disabled={isPending}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label
                        htmlFor="webhook-httpPass"
                        className="text-[12px] font-medium text-muted-foreground"
                      >
                        Password
                      </Label>
                      <Input
                        id="webhook-httpPass"
                        type="password"
                        placeholder={
                          isEditing
                            ? 'Leave blank to keep existing'
                            : 'Enter password'
                        }
                        value={httpPass}
                        onChange={(e) => setHttpPass(e.target.value)}
                        disabled={isPending}
                      />
                    </div>
                    <div className="flex items-center space-x-2">
                      <Checkbox
                        id="webhook-security"
                        checked={security}
                        onCheckedChange={(checked) =>
                          setSecurity(checked === true)
                        }
                        disabled={isPending}
                      />
                      <Label
                        htmlFor="webhook-security"
                        className="text-[13px] font-normal leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                      >
                        Certificate verification (SSL/TLS)
                      </Label>
                    </div>
                    <p className="text-[13px] text-muted-foreground">
                      Set an optional basic HTTP authentication username and
                      password to protect your endpoint from unauthorized
                      access.
                    </p>
                  </div>
                </div>

                {isEditing && (
                  <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                    <div className="px-6 py-4">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        Verification
                      </h3>
                    </div>
                    <div className="border-t border-border" />
                    <div className="px-6 py-4 space-y-4">
                      <div className="space-y-2">
                        <Label className="text-[12px] font-medium text-muted-foreground">
                          Signature key
                        </Label>
                        {fullWebhook?.secret ? (
                          <div className="flex items-center gap-2">
                            <CopyableId
                              id={fullWebhook.secret}
                              size="sm"
                              maxWidth={240}
                            />
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              className="h-9 text-[13px] shrink-0"
                              onClick={handleRegenerateSignature}
                              disabled={regenerateSignatureMutation.isPending}
                            >
                              <RefreshCw
                                className={`mr-1.5 h-4 w-4 ${regenerateSignatureMutation.isPending ? 'animate-spin' : ''}`}
                              />
                              Regenerate
                            </Button>
                          </div>
                        ) : (
                          <p className="text-[13px] text-muted-foreground">
                            Loading…
                          </p>
                        )}
                      </div>
                      <p className="text-[13px] text-muted-foreground">
                        Use this key to verify the authenticity of webhook
                        payloads via the X-Appwrite-Webhook-Signature header
                        (HMAC-SHA1).{' '}
                        <a
                          href="https://appwrite.io/docs/advanced/platform/webhooks#verification"
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-primary hover:underline inline-flex items-center gap-1"
                        >
                          Learn more
                          <ExternalLink className="h-3 w-3 shrink-0" />
                        </a>
                      </p>
                    </div>
                  </div>
                )}

                {isEditing && (
                  <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden mt-6">
                    <div className="px-6 py-4">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        Delete webhook
                      </h3>
                    </div>
                    <div className="border-t border-destructive/20" />
                    <div className="px-6 py-4">
                      <p className="text-[13px] text-muted-foreground">
                        Permanently delete this webhook. It will stop receiving
                        events immediately. This action cannot be undone.
                      </p>
                    </div>
                    <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                      <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={() => setDeleteConfirmOpen(true)}
                        disabled={isPending}
                      >
                        <Trash2 className="mr-1.5 h-4 w-4" />
                        Delete webhook
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            </div>

            <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
              <Button type="submit" disabled={!canSubmit || isPending}>
                {isEditing ? 'Update' : 'Create webhook'}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => handleOpenChange(false)}
                disabled={isPending}
              >
                Cancel
              </Button>
            </div>
          </form>
        </>
      </BaseDrawer>

      <Dialog open={deleteConfirmOpen} onOpenChange={setDeleteConfirmOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete webhook</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete{' '}
              <strong>{webhook?.name || 'this webhook'}</strong>? It will stop
              receiving events immediately. This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDeleteConfirmOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
