import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useVerifyDomain } from '@/lib/react-query/hooks/project-domains'
import {
  VerifyDomainContent,
  dnsPendingVerificationError,
  domainVerificationError,
  type DomainVerificationError,
} from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface VerifyDomainProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  organizationDomainId?: string
  onVerifySuccess: () => void
}

export function VerifyDomain({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  organizationDomainId,
  onVerifySuccess,
}: VerifyDomainProps) {
  const t = useT()
  const verifyMutation = useVerifyDomain(projectId, region)
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)

  useEffect(() => {
    if (open) setVerificationError(null)
  }, [open])

  const handleVerify = async () => {
    setVerificationError(null)
    try {
      const updated = await verifyMutation.mutateAsync({
        ruleId: rule.$id,
        organizationDomainId,
      })
      if (updated.status === 'verified') {
        toast.success(t('Domain verified'))
        onOpenChange(false)
        onVerifySuccess()
      } else if (
        updated.status === 'created' ||
        updated.status === 'unverified'
      ) {
        setVerificationError(dnsPendingVerificationError(t))
      } else {
        toast.success(t('Verifying...'))
        onOpenChange(false)
        onVerifySuccess()
      }
    } catch (err) {
      setVerificationError(domainVerificationError(t, err))
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
            resourceType="site"
            noCard
            verificationError={verificationError}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={verifyMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            onClick={handleVerify}
            disabled={verifyMutation.isPending}
          >
            {t('Verify')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
