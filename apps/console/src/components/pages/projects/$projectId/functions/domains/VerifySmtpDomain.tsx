import { useEffect, useState } from 'react'
import { Loader2 } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useVerifyDomain } from '@/lib/react-query/hooks'
import type { DomainVerificationError } from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import type { SmtpProxyRule } from '@/lib/function-smtp-domain'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import {
  VerifySmtpDomainContent,
  dnsPendingVerificationError,
} from './VerifySmtpDomainContent'

interface VerifySmtpDomainProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: SmtpProxyRule
  onVerifySuccess: () => void
}

/** Verify dialog for an email (SMTP) row in the function's domains table. */
export function VerifySmtpDomain({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  onVerifySuccess,
}: VerifySmtpDomainProps) {
  const t = useT()
  const verifyMutation = useVerifyDomain(projectId, region)
  const [current, setCurrent] = useState(rule)
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)

  useEffect(() => {
    if (open) {
      setCurrent(rule)
      setVerificationError(null)
    }
  }, [open, rule])

  const handleVerify = async () => {
    setVerificationError(null)
    try {
      const updated = (await verifyMutation.mutateAsync(
        current.$id,
      )) as SmtpProxyRule
      if (updated.status === 'verified') {
        toast.success(t('Email domain verified'))
        onOpenChange(false)
        onVerifySuccess()
      } else {
        setCurrent(updated)
        setVerificationError(dnsPendingVerificationError(t))
      }
    } catch {
      setVerificationError(dnsPendingVerificationError(t))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl p-0 gap-0 overflow-hidden">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>
            {t('Verify')} {current.domain}
          </DialogTitle>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 max-h-[70dvh] overflow-y-auto">
          <VerifySmtpDomainContent
            rule={current}
            region={region}
            noCard
            verificationError={verificationError}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end gap-2">
          <Button
            type="button"
            size="sm"
            onClick={handleVerify}
            disabled={verifyMutation.isPending}
          >
            {verifyMutation.isPending ? (
              <Loader2 className="h-3.5 w-3.5 animate-spin me-1.5" />
            ) : null}
            {t('Verify')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
