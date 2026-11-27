import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  DnsRecordsTable,
  DomainVerificationAlert,
  dnsPendingVerificationError,
  getZone,
  type DomainVerificationError,
} from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import { useT } from '@/lib/i18n/translate'
import { getAppwriteErrorInfo } from '@/lib/utils/error-formatting'
import {
  domainFieldError,
  getOrganizationDomainClaimRecord,
  isOrganizationDomainClaimError,
  isValidDomain,
} from '@/lib/utils/proxy-domains'

interface CreateDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Rejects with the claim error when another organization holds the domain. */
  onCreate: (domain: string) => Promise<void>
  isLoading?: boolean
  organizationId: string
}

export function CreateDomainDialog({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
  organizationId,
}: CreateDomainDialogProps) {
  const t = useT()
  const [domain, setDomain] = useState('')
  const [domainTouched, setDomainTouched] = useState(false)
  // Another organization holds the domain: the TXT record takes it over.
  const [claimDomain, setClaimDomain] = useState<string | null>(null)
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)
  const domainError = domainFieldError(domain, domainTouched, t)

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setDomain('')
      setDomainTouched(false)
      setClaimDomain(null)
      setVerificationError(null)
    }
  }, [open])

  const create = async (name: string) => {
    try {
      await onCreate(name)
    } catch (error) {
      if (!isOrganizationDomainClaimError(error)) return
      if (claimDomain === null) {
        setClaimDomain(name)
        return
      }
      // The server says what it found at the record; the shared sentence is
      // only for when it gives none.
      const pending = dnsPendingVerificationError(t)
      setVerificationError({
        ...pending,
        message: getAppwriteErrorInfo(error).message || pending.message,
      })
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setDomainTouched(true)
    if (!isValidDomain(domain)) return
    void create(domain.trim().toLowerCase())
  }

  const handleClaim = (e: React.FormEvent) => {
    e.preventDefault()
    if (!claimDomain) return
    setVerificationError(null)
    void create(claimDomain)
  }

  const record = claimDomain
    ? getOrganizationDomainClaimRecord(claimDomain, organizationId)
    : null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={claimDomain ? 'sm:max-w-2xl p-0' : 'sm:max-w-md p-0'}
      >
        {claimDomain && record ? (
          <form onSubmit={handleClaim}>
            <DialogHeader className="px-6 pt-6 pb-4 text-start">
              <DialogTitle>{t('Claim domain ownership')}</DialogTitle>
              <p
                dir="ltr"
                className="mt-1 font-mono text-[13px] text-foreground"
              >
                {claimDomain}
              </p>
              <DialogDescription className="text-[13px] mt-2">
                {t(
                  'This domain is linked to another organization. Add this TXT record at your DNS provider, then verify to move it to this organization. You can remove the record after.',
                )}
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="space-y-3 px-6 py-4">
              <DomainVerificationAlert error={verificationError} />
              <div className="overflow-x-auto">
                <DnsRecordsTable records={[record]} showTtl={true} fullValues />
              </div>
              {/* Providers append the zone to the host. */}
              <p className="text-[12px] text-muted-foreground">
                {t(
                  "Enter only {name} in your DNS provider's Host or Name field. Most providers add {apex} for you.",
                )
                  .replace('{name}', record.name)
                  .replace('{apex}', getZone(claimDomain))}
              </p>
              <p className="text-[12px] text-muted-foreground">
                {t('DNS changes can take time to propagate.')}
              </p>
            </div>
            <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setClaimDomain(null)
                  setVerificationError(null)
                }}
                disabled={isLoading}
              >
                {t('Back')}
              </Button>
              <Button type="submit" disabled={isLoading}>
                {isLoading ? t('Verifying...') : t('Verify & claim')}
              </Button>
            </div>
          </form>
        ) : (
          <>
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Add Domain')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t(
                  'Enter the domain name you want to add to your organization.',
                )}
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />

            <form onSubmit={handleSubmit}>
              <div className="px-6 pb-4 pt-0">
                <div className="space-y-2">
                  <Label htmlFor="domain">{t('Domain')}</Label>
                  <Input
                    id="domain"
                    value={domain}
                    onChange={(e) => setDomain(e.target.value)}
                    onBlur={() => setDomainTouched(true)}
                    placeholder="example.com"
                    disabled={isLoading}
                    autoFocus
                    aria-invalid={domainError ? true : undefined}
                  />
                  {domainError ? (
                    <p className="text-[12px] text-destructive">
                      {domainError}
                    </p>
                  ) : (
                    <p className="text-[12px] text-muted-foreground">
                      {t(
                        'Enter the domain name without protocol (e.g., example.com)',
                      )}
                    </p>
                  )}
                </div>
              </div>

              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => onOpenChange(false)}
                  disabled={isLoading}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="submit"
                  disabled={isLoading || !isValidDomain(domain)}
                >
                  {t('Add Domain')}
                </Button>
              </div>
            </form>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
