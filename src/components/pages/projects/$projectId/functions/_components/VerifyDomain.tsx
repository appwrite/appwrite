import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  useVerifyDomain,
  useDeleteDomain,
} from '@/lib/react-query/hooks/project-domains'
import { VerifyDomainContent } from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

interface VerifyDomainProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  onVerifySuccess: () => void
  onReconfigure?: () => void
}

export function VerifyDomain({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  onVerifySuccess,
  onReconfigure,
}: VerifyDomainProps) {
  const verifyMutation = useVerifyDomain(projectId, region)
  const deleteMutation = useDeleteDomain(projectId, region)
  const [verificationError, setVerificationError] = useState<string | null>(
    null,
  )

  useEffect(() => {
    if (open) setVerificationError(null)
  }, [open])

  const handleChange = async () => {
    try {
      await deleteMutation.mutateAsync(rule.$id)
      onOpenChange(false)
      onReconfigure?.()
    } catch {
      toast.error('Failed to remove domain')
    }
  }

  const handleVerify = async () => {
    setVerificationError(null)
    try {
      const updated = await verifyMutation.mutateAsync(rule.$id)
      if (updated.status === 'verified') {
        toast.success('Domain verified')
        onOpenChange(false)
        onVerifySuccess()
      } else if (
        updated.status === 'created' ||
        updated.status === 'unverified'
      ) {
        setVerificationError('Verification failed. Check DNS and retry.')
      } else {
        toast.success('Verifying...')
        onOpenChange(false)
        onVerifySuccess()
      }
    } catch {
      setVerificationError('Failed to verify domain')
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl p-0">
        <DialogHeader className="px-6 pt-6 pb-4">
          <DialogTitle>Verify {rule.domain}</DialogTitle>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 max-h-[70dvh] overflow-y-auto">
          <VerifyDomainContent
            resourceType="function"
            rule={rule}
            region={region}
            noCard
            verificationError={verificationError}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end gap-2">
          {onReconfigure && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleChange}
              disabled={verifyMutation.isPending || deleteMutation.isPending}
            >
              Change
            </Button>
          )}
          <Button
            type="button"
            size="sm"
            onClick={handleVerify}
            disabled={verifyMutation.isPending}
          >
            Verify
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
