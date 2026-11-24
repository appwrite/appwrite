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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { SSODomain } from '@/lib/appwrite/team-sso'
import { useT } from '@/lib/i18n/translate'
import { useCreateOrganizationEmailDomain } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  domainFieldError,
  getDomainClaimReason,
} from '@/lib/utils/proxy-domains'
import {
  SSO_ERROR_TEXT,
  useSingleSubmit,
  useSSOAddonErrorHandler,
} from './SSOCardHelpers'
import { getEmailDomainRecord } from './VerifyEmailDomain'

export function CreateEmailDomain({
  open,
  onOpenChange,
  organizationId,
  onCreated,
  onCloseAutoFocus,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  onCreated: (rule: SSODomain) => void
  /** Where focus goes on close: the dialog opens from state, not a trigger. */
  onCloseAutoFocus?: (event: Event) => void
}) {
  const t = useT()
  const [domain, setDomain] = useState('')
  const [touched, setTouched] = useState(false)
  // Another organization holds the domain: the TXT record takes it over.
  const [claimDomain, setClaimDomain] = useState<string | null>(null)
  const create = useCreateOrganizationEmailDomain(organizationId)
  const onAddonError = useSSOAddonErrorHandler(organizationId)
  const submitOnce = useSingleSubmit()

  const domainError = domainFieldError(domain, touched, t)
  const canSubmit = domain.trim() !== '' && !domainError && !create.isPending

  const reset = () => {
    setDomain('')
    setTouched(false)
    setClaimDomain(null)
  }

  const close = (next: boolean) => {
    if (!next) reset()
    onOpenChange(next)
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    setTouched(true)
    if (!canSubmit) return
    submitOnce((settle) =>
      create.mutate(
        { domain },
        {
          onSettled: settle,
          onSuccess: (rule) => {
            toast.success(t('Domain added'))
            close(false)
            onCreated(rule)
          },
          onError: (failure: Error) => {
            if (onAddonError(failure)) {
              close(false)
              return
            }
            if (getDomainClaimReason(failure) === 'held') {
              setClaimDomain(domain.trim().toLowerCase())
              return
            }
            toast.error(getErrorMessage(failure) || t('Failed to add domain'))
          },
        },
      ),
    )
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent
        className={claimDomain ? 'sm:max-w-2xl p-0' : 'sm:max-w-xl p-0'}
        onCloseAutoFocus={onCloseAutoFocus}
      >
        <form onSubmit={handleSubmit}>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Add domain')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Prove your organization owns this domain with a DNS record. Your identity providers then sign in people with an email on it.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="space-y-4 px-6 py-4">
            <div className="space-y-2">
              <Label htmlFor="email-domain" className="text-[13px] font-medium">
                {t('Domain')}
              </Label>
              <Input
                id="email-domain"
                value={domain}
                placeholder="example.com"
                autoComplete="off"
                onChange={(event) => {
                  setDomain(event.target.value)
                  setClaimDomain(null)
                }}
                onBlur={() => setTouched(true)}
                aria-invalid={!!domainError}
                aria-describedby="email-domain-message"
                disabled={create.isPending}
              />
              {domainError ? (
                <p
                  id="email-domain-message"
                  className={`text-[12px] ${SSO_ERROR_TEXT}`}
                >
                  {domainError}
                </p>
              ) : (
                <p
                  id="email-domain-message"
                  className="text-[12px] text-muted-foreground"
                >
                  {t(
                    'Only the domain, without @. Subdomains need their own entry.',
                  )}
                </p>
              )}
            </div>
            {claimDomain ? (
              <Alert>
                <AlertDescription className="space-y-3 text-[13px]">
                  <p>
                    {t(
                      'Another organization claimed this domain. Add this TXT record to prove it is yours, then add it again.',
                    )}
                  </p>
                  <div className="w-full overflow-x-auto">
                    <DnsRecordsTable
                      records={[
                        getEmailDomainRecord(claimDomain, organizationId),
                      ]}
                      showTtl={false}
                      fullValues
                    />
                  </div>
                </AlertDescription>
              </Alert>
            ) : null}
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => close(false)}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={!canSubmit}>
              {create.isPending ? t('Adding...') : t('Add')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
