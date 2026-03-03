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
import { ChevronRight, ChevronLeft } from 'lucide-react'
import { EventSelector } from './EventSelector'

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
  const [httpUser, setHttpUser] = useState('')
  const [httpPass, setHttpPass] = useState('')
  const [security, setSecurity] = useState(true)

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setCurrentStep('name-url')
      setName('')
      setUrl('')
      setEvents([])
      setHttpUser('')
      setHttpPass('')
      setSecurity(true)
    }
  }, [open])

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
        security,
        enabled: true,
        httpUser: httpUser.trim() || undefined,
        httpPass: httpPass.trim() || undefined,
      })
      toast.success('Webhook has been created')
      onCreateSuccess(webhook.$id)
    } catch (error: unknown) {
      toast.error(error.message || 'Failed to create webhook')
    }
  }

  return (
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
          {/* Step 1: Name and URL */}
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

          {/* Step 2: Events */}
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

          {/* Step 3: Security */}
          {currentStep === 'security' && (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="httpUser" className="text-[12px] font-medium">
                  User
                </Label>
                <Input
                  id="httpUser"
                  placeholder="Enter username"
                  value={httpUser}
                  onChange={(e) => setHttpUser(e.target.value)}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="httpPass" className="text-[12px] font-medium">
                  Password
                </Label>
                <Input
                  id="httpPass"
                  type="password"
                  placeholder="Enter password"
                  value={httpPass}
                  onChange={(e) => setHttpPass(e.target.value)}
                />
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="security"
                  checked={security}
                  onCheckedChange={(checked) => setSecurity(checked === true)}
                />
                <Label
                  htmlFor="security"
                  className="text-[13px] font-normal leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                >
                  Certificate verification (SSL/TLS)
                </Label>
              </div>
              <p className="text-[13px] text-muted-foreground">
                Set an optional basic HTTP authentication username and password
                to protect your endpoint from unauthorized access.
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
  )
}
