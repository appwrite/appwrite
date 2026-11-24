import { useRef, useState } from 'react'
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
import type { SSODomain } from '@/lib/appwrite/team-sso'
import { useT } from '@/lib/i18n/translate'
import { useVerifyOrganizationEmailDomain } from '@/lib/react-query/hooks'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import { getClaimRecord } from '@/lib/utils/proxy-domains'
import {
  focusOnOpen,
  SSOLockedTooltip,
  SSO_ALERT_ERROR_TEXT,
  useSSOAddonErrorHandler,
  useSSOAddonGate,
} from './SSOCardHelpers'

/** TXT record that proves an organization owns an email domain: `team:<orgId>` at `_appwrite`. */
export function getEmailDomainRecord(domain: string, organizationId: string) {
  return getClaimRecord(domain, `team:${organizationId}`)
}

export function VerifyEmailDomain({
  rule,
  organizationId,
  onOpenChange,
  onVerified,
  onCloseAutoFocus,
}: {
  rule: SSODomain | null
  organizationId: string
  onOpenChange: (open: boolean) => void
  onVerified?: (rule: SSODomain) => void
  /** Where focus goes on close: the dialog opens from state, not a trigger. */
  onCloseAutoFocus?: (event: Event) => void
}) {
  const t = useT()
  const later = useRef<HTMLButtonElement>(null)
  const [error, setError] = useState<string | null>(null)
  const verify = useVerifyOrganizationEmailDomain(organizationId)
  // Checking the record needs the Single sign-on add-on.
  const gate = useSSOAddonGate(organizationId)
  const onAddonError = useSSOAddonErrorHandler(organizationId)

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
          onVerified?.(rule)
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
        if (onAddonError(failure)) return
        // For an organization's domain the server says what it found: no
        // record yet, a record with the wrong value (and which), or one it
        // could not confirm. Its words are meant for the owner, so they are
        // shown as they are; the sentence below is only for when it gives none.
        const info = getAppwriteErrorInfo(failure)
        setError(
          (info.type === 'rule_verification_failed'
            ? info.message
            : getErrorMessage(failure)) ||
            t(
              'The TXT record was not found yet. DNS changes can take a few minutes to propagate.',
            ),
        )
      },
    })
  }

  return (
    <Dialog open={rule !== null} onOpenChange={close}>
      <DialogContent
        className="sm:max-w-3xl p-0"
        onOpenAutoFocus={focusOnOpen(() => later.current)}
        onCloseAutoFocus={onCloseAutoFocus}
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Verify domain')}</DialogTitle>
          <p dir="ltr" className="mt-1 font-mono text-[13px] text-foreground">
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
                fullValues
              />
            </div>
          ) : null}
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Until the domain is verified, nobody can sign in with single sign-on on it.',
            )}
          </p>
          {error ? (
            <Alert variant="destructive">
              <AlertDescription
                className={`text-[13px] ${SSO_ALERT_ERROR_TEXT}`}
              >
                {error}
              </AlertDescription>
            </Alert>
          ) : null}
        </div>
        <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
          <Button ref={later} variant="outline" onClick={() => close(false)}>
            {t('Later')}
          </Button>
          <SSOLockedTooltip locked={gate.locked}>
            <Button
              onClick={handleVerify}
              disabled={verify.isPending || gate.locked}
            >
              {verify.isPending ? t('Verifying...') : t('Verify')}
            </Button>
          </SSOLockedTooltip>
        </div>
      </DialogContent>
    </Dialog>
  )
}
