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

interface VerifyDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  onVerifySuccess: () => void
}

export function VerifyDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  onVerifySuccess,
}: VerifyDomainDialogProps) {
  const verifyDomainMutation = useVerifyDomain(projectId, region)

  const handleVerify = async () => {
    try {
      const updatedRule = await verifyDomainMutation.mutateAsync(rule.$id)
      if (updatedRule.status === 'created') {
        toast.error(
          'Domain verification failed. Please check your domain settings or try again later.',
        )
      } else if (updatedRule.status === 'verified') {
        toast.success('Domain added successfully')
        onVerifySuccess()
      } else {
        toast.success('Verification in progress')
        onVerifySuccess()
      }
    } catch (error: unknown) {
      toast.error(error.message || 'Failed to verify domain')
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Add domain</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Verify domain ownership for {rule.domain}
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
            onClick={handleVerify}
            disabled={verifyDomainMutation.isPending}
          >
            Verify
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
