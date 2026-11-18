import { useState, useEffect } from 'react'
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
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import { canRetryRule } from '@/lib/utils/proxy-domains'
import {
  VerifyDomainContent,
  dnsPendingVerificationError,
  domainVerificationError,
  type DomainVerificationError,
} from './VerifyDomainContent'

interface RetryDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  organizationDomainId?: string
  onRetrySuccess: () => void
}

export function RetryDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  organizationDomainId,
  onRetrySuccess,
}: RetryDomainDialogProps) {
  const t = useT()
  const verifyDomainMutation = useVerifyDomain(projectId, region)
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)

  useEffect(() => {
    if (open) setVerificationError(null)
  }, [open])

  const handleRetry = async () => {
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
        toast.success(
          rule.status === 'verified'
            ? t('Certificate renewal started')
            : `${rule.domain} ${t('has been verified')}`,
        )
        onRetrySuccess()
      } else {
        toast.success(t('Verification in progress'))
        onRetrySuccess()
      }
    } catch (err) {
      setVerificationError(domainVerificationError(t, err))
    }
  }

  // The rule updates live: once DNS passes, there is nothing to retry.
  const retryable = canRetryRule(rule)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          {retryable ? (
            <>
              <DialogTitle>{t('Retry verification')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Retry domain verification for')} {rule.domain}
              </DialogDescription>
            </>
          ) : (
            <>
              <DialogTitle>{t('DNS Records')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2 font-mono">
                {rule.domain}
              </DialogDescription>
            </>
          )}
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

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {retryable ? (
            <>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={verifyDomainMutation.isPending}
              >
                {t('Cancel')}
              </Button>
              <Button
                type="button"
                onClick={handleRetry}
                disabled={verifyDomainMutation.isPending}
              >
                {t('Retry')}
              </Button>
            </>
          ) : (
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t('Close')}
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
