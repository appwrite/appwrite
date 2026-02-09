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
import { Textarea } from '@/components/ui/textarea'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useCreateMigrationKey } from '@/lib/react-query/hooks'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'

interface ExportDataDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
}

export function ExportDataDialog({
  open,
  onOpenChange,
  projectId,
}: ExportDataDialogProps) {
  const createKeyMutation = useCreateMigrationKey(projectId)
  const [endpointUrl, setEndpointUrl] = useState('')
  const [feedback, setFeedback] = useState('')
  const [isRedirecting, setIsRedirecting] = useState(false)

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setEndpointUrl('')
      setFeedback('')
      setIsRedirecting(false)
    }
  }, [open])

  const validateEndpoint = (url: string): boolean => {
    if (!url.trim()) return false
    try {
      const parsed = new URL(url)
      // Must have protocol and hostname
      if (!parsed.protocol || !parsed.hostname) return false
      // No path except root, no query string
      if (parsed.pathname !== '/' && parsed.pathname !== '') return false
      if (parsed.search) return false
      return true
    } catch {
      return false
    }
  }

  const isValidEndpoint = validateEndpoint(endpointUrl)

  const handleContinue = async () => {
    if (!isValidEndpoint) {
      toast.error('Please enter a valid endpoint URL')
      return
    }

    setIsRedirecting(true)

    try {
      // Submit feedback (non-blocking)
      if (feedback.trim()) {
        // TODO: Submit feedback to API
        console.log('Feedback:', feedback)
      }

      // Create API key
      const keyResponse = await createKeyMutation.mutateAsync()

      // Get current project endpoint (centralized in SDK)
      const endpoint = getProjectApiEndpoint(projectId)

      // Generate migration data
      const migrationData = {
        endpoint,
        projectId,
        apiKey: keyResponse.secret,
      }

      // Encode and redirect
      const encodedData = encodeURIComponent(JSON.stringify(migrationData))
      const targetUrl = `${endpointUrl.replace(/\/$/, '')}/?migrate=${encodedData}`

      window.location.href = targetUrl
    } catch (error: unknown) {
      toast.error(error.message || 'Failed to create migration key')
      setIsRedirecting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Export to self-hosted instance</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Export your project data to a self-hosted Appwrite instance
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0 space-y-4">
          <Alert>
            <AlertDescription className="text-[13px]">
              API key creation - By initiating the transfer, an API key will be
              automatically generated in the background, which you can delete
              after completion
            </AlertDescription>
          </Alert>

          <div className="space-y-2">
            <Label htmlFor="endpoint" className="text-[12px] font-medium">
              Endpoint self-hosted instance{' '}
              <span className="text-destructive">*</span>
            </Label>
            <Input
              id="endpoint"
              type="url"
              placeholder="https://<YOUR_APPWRITE_HOSTNAME>"
              value={endpointUrl}
              onChange={(e) => setEndpointUrl(e.target.value)}
              disabled={isRedirecting}
            />
            {endpointUrl && !isValidEndpoint && (
              <p className="text-[12px] text-destructive">
                Please enter a valid URL with protocol and hostname (no path or
                query string)
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="feedback" className="text-[12px] font-medium">
              Your feedback
            </Label>
            <Textarea
              id="feedback"
              placeholder="Type here..."
              value={feedback}
              onChange={(e) => setFeedback(e.target.value)}
              disabled={isRedirecting}
              rows={3}
            />
            <p className="text-[13px] text-muted-foreground">
              Share your feedback: why our self-hosted solution works better for
              you
            </p>
          </div>

          <p className="text-[13px] text-muted-foreground">
            You will be redirected to your self-hosted instance
          </p>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isRedirecting}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={handleContinue}
            disabled={!isValidEndpoint || isRedirecting}
          >
            Continue
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
