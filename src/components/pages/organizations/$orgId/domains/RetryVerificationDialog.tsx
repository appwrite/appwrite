import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import type { Models } from '@appwrite.io/console'

interface RetryVerificationDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  domain: Models.Domain
  onRetry: (domainId: string) => void
  isLoading?: boolean
}

export function RetryVerificationDialog({
  open,
  onOpenChange,
  domain,
  onRetry,
  isLoading = false,
}: RetryVerificationDialogProps) {
  const handleRetry = () => {
    onRetry(domain.$id)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Retry Domain Verification</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            This will update the nameservers for <strong>{domain.domain}</strong>. Make sure you have updated your domain's nameservers to point to Appwrite before retrying.
          </DialogDescription>
        </DialogHeader>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            Cancel
          </Button>
          <Button onClick={handleRetry} disabled={isLoading}>
            Retry Verification
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

