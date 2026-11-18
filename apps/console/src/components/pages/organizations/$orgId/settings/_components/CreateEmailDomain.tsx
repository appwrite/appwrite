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
import type { TeamRule } from '@/lib/appwrite/team-rules'
import { useT } from '@/lib/i18n/translate'
import { useCreateOrganizationEmailDomain } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  domainFieldError,
  getDomainClaimReason,
} from '@/lib/utils/proxy-domains'
import { EmailDomainRoles } from './EmailDomainRoles'
import { getEmailDomainRecord } from './VerifyEmailDomain'

export function CreateEmailDomain({
  open,
  onOpenChange,
  organizationId,
  onCreated,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  onCreated: (rule: TeamRule) => void
}) {
  const t = useT()
  const [domain, setDomain] = useState('')
  const [touched, setTouched] = useState(false)
  const [roles, setRoles] = useState<string[]>(['developer'])
  // Another organization holds the domain: the TXT record takes it over.
  const [claimDomain, setClaimDomain] = useState<string | null>(null)
  const create = useCreateOrganizationEmailDomain(organizationId)

  const domainError = domainFieldError(domain, touched, t)
  const canSubmit =
    domain.trim() !== '' &&
    !domainError &&
    roles.length > 0 &&
    !create.isPending

  const reset = () => {
    setDomain('')
    setTouched(false)
    setRoles(['developer'])
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
    create.mutate(
      { domain, roles },
      {
        onSuccess: (rule) => {
          toast.success(t('Email domain added'))
          close(false)
          onCreated(rule)
        },
        onError: (failure: Error) => {
          if (getDomainClaimReason(failure) === 'held') {
            setClaimDomain(domain.trim().toLowerCase())
            return
          }
          toast.error(
            getErrorMessage(failure) || t('Failed to add email domain'),
          )
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="sm:max-w-xl p-0">
        <form onSubmit={handleSubmit}>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Add email domain')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'People who sign up with a verified email on this domain join this organization.',
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
                disabled={create.isPending}
              />
              {domainError ? (
                <p className="text-[12px] text-destructive">{domainError}</p>
              ) : (
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    'Only the domain, without @. Subdomains need their own entry.',
                  )}
                </p>
              )}
            </div>
            <div className="space-y-2">
              <Label className="text-[13px] font-medium">{t('Roles')}</Label>
              <EmailDomainRoles
                value={roles}
                onChange={setRoles}
                disabled={create.isPending}
              />
              <p className="text-[12px] text-muted-foreground">
                {roles.length === 0
                  ? t('Pick at least one role.')
                  : t(
                      'Roles people get when they join. Same roles as Invite Members.',
                    )}
              </p>
            </div>
            {claimDomain ? (
              <Alert>
                <AlertDescription className="space-y-3 text-[13px]">
                  <p>
                    {t(
                      'Another organization claimed this domain. Add this TXT record to prove it is yours, then add it again.',
                    )}
                  </p>
                  <DnsRecordsTable
                    records={[
                      getEmailDomainRecord(claimDomain, organizationId),
                    ]}
                    showTtl={true}
                  />
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
