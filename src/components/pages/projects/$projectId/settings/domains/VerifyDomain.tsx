import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useVerifyDomain, useDeleteDomain } from '@/lib/react-query/hooks'
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
  onReconfigure?: (domain: string) => void
}

export function VerifyDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  onVerifySuccess,
  onReconfigure,
}: VerifyDomainDialogProps) {
  const verifyDomainMutation = useVerifyDomain(projectId, region)
  const deleteDomainMutation = useDeleteDomain(projectId, region)
  const [verificationError, setVerificationError] = useState<string | null>(null)

  useEffect(() => {
    if (open) setVerificationError(null)
  }, [open])

  const handleChange = async () => {
    try {
      await deleteDomainMutation.mutateAsync(rule.$id)
      onOpenChange(false)
      onReconfigure?.(rule.domain)
    } catch {
      toast.error('Failed to remove domain')
    }
  }

  const handleVerify = async () => {
    setVerificationError(null)
    try {
      const updatedRule = await verifyDomainMutation.mutateAsync(rule.$id)
      if (updatedRule.status === 'created') {
        setVerificationError(
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
      setVerificationError(
        (error instanceof Error ? error.message : null) || 'Failed to verify domain',
      )
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl p-0">
        <DialogHeader className="px-6 pt-6 pb-4">
          <DialogTitle>Verify {rule.domain}</DialogTitle>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 max-h-[70vh] overflow-y-auto">
          <VerifyDomainContent
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
              disabled={
                verifyDomainMutation.isPending ||
                deleteDomainMutation.isPending
              }
            >
              Change
            </Button>
          )}
          <Button
            type="button"
            size="sm"
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
