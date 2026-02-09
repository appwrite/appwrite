import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useVerifyDomain } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { VerifyDomainContent } from './VerifyDomainContent'

interface RetryDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  onRetrySuccess: () => void
}

export function RetryDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  onRetrySuccess,
}: RetryDomainDialogProps) {
  const verifyDomainMutation = useVerifyDomain(projectId, region)

  const handleRetry = async () => {
    try {
      const updatedRule = await verifyDomainMutation.mutateAsync(rule.$id)
      if (updatedRule.status === 'created') {
        toast.error(
          'Domain verification failed. Please check your domain settings or try again later.',
        )
      } else if (updatedRule.status === 'verified') {
        toast.success(`${rule.domain} has been verified`)
        onRetrySuccess()
      } else {
        toast.success('Verification in progress')
        onRetrySuccess()
      }
    } catch (error: unknown) {
      toast.error(error.message || 'Failed to retry verification')
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Retry verification</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Retry domain verification for {rule.domain}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <VerifyDomainContent rule={rule} />
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={verifyDomainMutation.isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={handleRetry}
            disabled={verifyDomainMutation.isPending}
          >
            Retry
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
