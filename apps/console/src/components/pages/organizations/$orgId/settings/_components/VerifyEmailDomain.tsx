import { useState } from 'react'
import { toast } from 'sonner'
import { DnsRecordsTable } from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import type { TeamRule } from '@/lib/appwrite/team-rules'
import { useT } from '@/lib/i18n/translate'
import { useVerifyOrganizationEmailDomain } from '@/lib/react-query/hooks'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import { getClaimRecord } from '@/lib/utils/proxy-domains'

/** TXT record that proves an organization owns an email domain: `team:<orgId>` at `_appwrite`. */
export function getEmailDomainRecord(domain: string, organizationId: string) {
  return getClaimRecord(domain, `team:${organizationId}`)
}

export function VerifyEmailDomain({
  rule,
  organizationId,
  onOpenChange,
}: {
  rule: TeamRule | null
  organizationId: string
  onOpenChange: (open: boolean) => void
}) {
  const t = useT()
  const [error, setError] = useState<string | null>(null)
  const verify = useVerifyOrganizationEmailDomain(organizationId)

  const close = (open: boolean) => {
    if (!open) setError(null)
    onOpenChange(open)
  }

  const handleVerify = () => {
    if (!rule) return
    setError(null)
    verify.mutate(rule.$id, {
      onSuccess: (updated) => {
        if (updated.status === 'verified') {
          toast.success(t('Domain verified'))
          close(false)
          return
        }
        setError(
          t(
            'The TXT record was not found yet. DNS changes can take a few minutes to propagate.',
          ),
        )
      },
      onError: (failure: Error) => {
        // A missing record is the expected case; the server's DNS detail names
        // internal resolvers, so it is not shown.
        const notFound =
          getAppwriteErrorInfo(failure).type === 'rule_verification_failed'
        setError(
          notFound
            ? t(
                'The TXT record was not found yet. DNS changes can take a few minutes to propagate.',
              )
            : getErrorMessage(failure) ||
                t(
                  'The TXT record was not found yet. DNS changes can take a few minutes to propagate.',
                ),
        )
      },
    })
  }

  return (
    <Dialog open={rule !== null} onOpenChange={close}>
      <DialogContent className="sm:max-w-2xl p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Verify domain')}</DialogTitle>
          <p className="mt-1 font-mono text-[13px] text-foreground">
            {rule?.domain}
          </p>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Add this TXT record at your DNS provider, then verify. It can take a few minutes to propagate.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-3 px-6 py-4">
          {rule ? (
            <div className="overflow-x-auto">
              <DnsRecordsTable
                records={[getEmailDomainRecord(rule.domain, organizationId)]}
                showTtl={true}
              />
            </div>
          ) : null}
          <p className="text-[12px] text-muted-foreground">
            {t('Until the domain is verified, nobody joins automatically.')}
          </p>
          {error ? (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          ) : null}
        </div>
        <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => close(false)}>
            {t('Later')}
          </Button>
          <Button onClick={handleVerify} disabled={verify.isPending}>
            {verify.isPending ? t('Verifying...') : t('Verify')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
