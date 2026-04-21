import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { useCreateWebhook } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { ChevronRight, ChevronLeft, Copy, Check } from 'lucide-react'
import { EventSelector } from './EventSelector'
import { getErrorMessage } from '@/lib/utils/error-formatting'

function CopyableSecret({ value }: { value: string }) {
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    await navigator.clipboard.writeText(value)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2">
      <code className="min-w-0 flex-1 truncate font-mono text-[12px] text-foreground">
        {value}
      </code>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="h-7 w-7 shrink-0"
        onClick={handleCopy}
        aria-label="Copy secret"
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

function getWebhookSecret(webhook: unknown) {
  const record = webhook as Record<string, unknown>
  return String(record?.secret || '')
}

interface CreateWebhookDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  onCreateSuccess: (webhookId: string) => void
}

type WizardStep = 'name-url' | 'events' | 'security'

export function CreateWebhookDialog({
  open,
  onOpenChange,
  projectId,
  onCreateSuccess,
}: CreateWebhookDialogProps) {
  const createWebhookMutation = useCreateWebhook(projectId)
  const [currentStep, setCurrentStep] = useState<WizardStep>('name-url')
  const [name, setName] = useState('')
  const [url, setUrl] = useState('')
  const [events, setEvents] = useState<string[]>([])
  const [authUsername, setAuthUsername] = useState('')
  const [authPassword, setAuthPassword] = useState('')
  const [tls, setTls] = useState(true)
  const [createdWebhookId, setCreatedWebhookId] = useState('')
  const [createdSecret, setCreatedSecret] = useState('')
  const [showSecretDialog, setShowSecretDialog] = useState(false)

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setCurrentStep('name-url')
      setName('')
      setUrl('')
      setEvents([])
      setAuthUsername('')
      setAuthPassword('')
      setTls(true)
      setCreatedWebhookId('')
      setCreatedSecret('')
    }
  }, [open])

  const handleContinue = () => {
    setShowSecretDialog(false)
    if (createdWebhookId) {
      onCreateSuccess(createdWebhookId)
    }
  }

  const canProceedFromStep1 = name.trim() && url.trim()
  const canProceedFromStep2 = events.length > 0 && events.length <= 100
  const canCreate = canProceedFromStep1 && canProceedFromStep2

  const handleNext = () => {
    if (currentStep === 'name-url' && canProceedFromStep1) {
      setCurrentStep('events')
    } else if (currentStep === 'events' && canProceedFromStep2) {
      setCurrentStep('security')
    }
  }

  const handleBack = () => {
    if (currentStep === 'security') {
      setCurrentStep('events')
    } else if (currentStep === 'events') {
      setCurrentStep('name-url')
    }
  }

  const handleCreate = async () => {
    if (!canCreate) return

    try {
      const webhook = await createWebhookMutation.mutateAsync({
        name: name.trim(),
        url: url.trim(),
        events,
        tls,
        enabled: true,
        authUsername: authUsername.trim() || undefined,
        authPassword: authPassword.trim() || undefined,
      })
      const secret = getWebhookSecret(webhook)
      toast.success(
        secret
          ? 'Webhook created. Secret ready to copy.'
          : 'Webhook has been created',
      )

      if (secret) {
        setCreatedWebhookId(webhook.$id)
        setCreatedSecret(secret)
        setShowSecretDialog(true)
        return
      }

      onCreateSuccess(webhook.$id)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error as Error, 'Failed to create webhook'))
    }
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-2xl p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Create webhook</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {currentStep === 'name-url' && 'Enter the webhook name and URL'}
              {currentStep === 'events' &&
                'Select the events that will trigger your webhook'}
              {currentStep === 'security' &&
                'Configure security settings for your webhook'}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-0">
            {currentStep === 'name-url' && (
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="name" className="text-[12px] font-medium">
                    Name <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="name"
                    placeholder="Enter webhook name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    autoFocus
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="url" className="text-[12px] font-medium">
                    POST URL <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="url"
                    type="url"
                    placeholder="https://example.com/callback"
                    value={url}
                    onChange={(e) => setUrl(e.target.value)}
                  />
                </div>
              </div>
            )}

            {currentStep === 'events' && (
              <div className="space-y-4">
                <EventSelector
                  projectId={projectId}
                  selectedEvents={events}
                  onEventsChange={setEvents}
                  maxEvents={100}
                />
              </div>
            )}

            {currentStep === 'security' && (
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label
                    htmlFor="authUsername"
                    className="text-[12px] font-medium"
                  >
                    User
                  </Label>
                  <Input
                    id="authUsername"
                    placeholder="Enter username"
                    value={authUsername}
                    onChange={(e) => setAuthUsername(e.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <Label
                    htmlFor="authPassword"
                    className="text-[12px] font-medium"
                  >
                    Password
                  </Label>
                  <Input
                    id="authPassword"
                    type="password"
                    placeholder="Enter password"
                    value={authPassword}
                    onChange={(e) => setAuthPassword(e.target.value)}
                  />
                </div>
                <div className="flex items-center space-x-2">
                  <Checkbox
                    id="tls"
                    checked={tls}
                    onCheckedChange={(checked) => setTls(checked === true)}
                  />
                  <Label
                    htmlFor="tls"
                    className="text-[13px] font-normal leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                  >
                    Certificate verification (SSL/TLS)
                  </Label>
                </div>
                <p className="text-[13px] text-muted-foreground">
                  Set an optional basic HTTP authentication username and
                  password to protect your endpoint from unauthorized access.
                </p>
              </div>
            )}
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            {currentStep !== 'name-url' && (
              <Button
                type="button"
                variant="outline"
                onClick={handleBack}
                disabled={createWebhookMutation.isPending}
              >
                <ChevronLeft className="mr-1.5 h-4 w-4" />
                Back
              </Button>
            )}
            {currentStep !== 'security' ? (
              <Button
                type="button"
                onClick={handleNext}
                disabled={
                  (currentStep === 'name-url' && !canProceedFromStep1) ||
                  (currentStep === 'events' && !canProceedFromStep2)
                }
              >
                Next
                <ChevronRight className="ml-1.5 h-4 w-4" />
              </Button>
            ) : (
              <Button
                type="button"
                onClick={handleCreate}
                disabled={!canCreate || createWebhookMutation.isPending}
              >
                Create webhook
              </Button>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={showSecretDialog} onOpenChange={setShowSecretDialog}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Webhook created</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              This secret is only shown once after webhook creation or secret
              rotation. Copy it now.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <CopyableSecret value={createdSecret} />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleContinue}
            >
              Continue
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
