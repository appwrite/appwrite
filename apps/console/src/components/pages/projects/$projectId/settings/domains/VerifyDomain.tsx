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
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import {
  VerifyDomainContent,
  dnsPendingVerificationError,
  type DomainVerificationError,
} from './VerifyDomainContent'

interface VerifyDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  organizationDomainId?: string
  onVerifySuccess: () => void
  onReconfigure?: (domain: string) => void
}

export function VerifyDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  organizationDomainId,
  onVerifySuccess,
  onReconfigure,
}: VerifyDomainDialogProps) {
  const t = useT()
  const verifyDomainMutation = useVerifyDomain(projectId, region)
  const deleteDomainMutation = useDeleteDomain(projectId, region)
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)

  useEffect(() => {
    if (open) setVerificationError(null)
  }, [open])

  const handleChange = async () => {
    try {
      await deleteDomainMutation.mutateAsync(rule.$id)
      onOpenChange(false)
      onReconfigure?.(rule.domain)
    } catch {
      toast.error(t('Failed to remove domain'))
    }
  }

  const handleVerify = async () => {
    setVerificationError(null)
    try {
      const updatedRule = await verifyDomainMutation.mutateAsync({
        ruleId: rule.$id,
        organizationDomainId,
      })
      if (
        updatedRule.status === 'created' ||
        updatedRule.status === 'unverified'
      ) {
        setVerificationError(dnsPendingVerificationError(t))
      } else if (updatedRule.status === 'verified') {
        toast.success(t('Domain added successfully'))
        onVerifySuccess()
      } else {
        toast.success(t('Verification in progress'))
        onVerifySuccess()
      }
    } catch {
      setVerificationError(dnsPendingVerificationError(t))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl p-0">
        <DialogHeader className="px-6 pt-6 pb-4">
          <DialogTitle>
            {t('Verify')} {rule.domain}
          </DialogTitle>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 max-h-[70dvh] overflow-y-auto">
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
                verifyDomainMutation.isPending || deleteDomainMutation.isPending
              }
            >
              {t('Change')}
            </Button>
          )}
          <Button
            type="button"
            size="sm"
            onClick={handleVerify}
            disabled={verifyDomainMutation.isPending}
          >
            {t('Verify')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
