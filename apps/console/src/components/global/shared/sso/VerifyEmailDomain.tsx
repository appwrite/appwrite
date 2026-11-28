import { useRef, useState } from 'react'
import { toast } from 'sonner'
import {
  DnsBannerAlert,
  type DnsBanner,
} from '@/components/global/shared/dns/DnsBannerAlert'
import { DnsCheckMeta } from '@/components/global/shared/dns/DnsCheckMeta'
import {
  DnsRecordsTable,
  type DnsRecord,
} from '@/components/global/shared/dns/DnsRecordsTable'
import {
  DomainVerificationAlert,
  type DomainVerificationError,
} from '@/components/global/shared/dns/DomainVerificationAlert'
import { RecordSection } from '@/components/global/shared/dns/RecordSection'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import type { ProxyRuleRecord } from '@/lib/appwrite/proxy-rules'
import type { SSODomain } from '@/lib/appwrite/team-sso'
import { useT } from '@/lib/i18n/translate'
import { useSSODomains, useVerifySSODomain } from '@/lib/react-query/hooks'
import { getSSODomainRecord, type SSOTarget } from '@/lib/sso-target'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import {
  getClaimRecord,
  getMisplacedName,
  getRecordName,
  getZone,
} from '@/lib/utils/proxy-domains'
import { getLaggingRegions } from '@/lib/utils/status-badge'
import {
  focusOnOpen,
  SSOLockedTooltip,
  useSSOAddonErrorHandler,
  useSSOAddonGate,
} from './SSOCardHelpers'

/**
 * TXT record that proves an organization owns an email domain: `team:<orgId>`
 * at `_appwrite`. Only for a domain another organization holds, which has no
 * domain of this organization's to read the record from yet.
 */
export function getEmailDomainRecord(domain: string, organizationId: string) {
  return getClaimRecord(domain, `team:${organizationId}`)
}

/**
 * The domain's TXT row with its last check. The check reads as the record's,
 * at the name the record belongs at: where a misplaced record was found
 * follows from it, whichever of the two the server names.
 */
function getRecord(domain: SSODomain): DnsRecord {
  const record = getSSODomainRecord(domain)
  const { check } = domain
  if (!check) return record
  const name = domain.recordName.trim().toLowerCase()
  const result: ProxyRuleRecord = {
    ...check,
    $id: domain.$id,
    $createdAt: domain.$createdAt,
    $updatedAt: check.checkedAt || domain.$updatedAt,
    name: name.endsWith('.') ? name.slice(0, -1) : name,
    required: true,
  }
  return { ...record, check: result }
}

/**
 * What the last check asks of the owner: the fix for a record that is wrong,
 * or that it only needs time. Nothing checks on its own, so each one ends
 * with verifying again.
 */
function getBanners(
  t: (text: string) => string,
  check: ProxyRuleRecord,
): DnsBanner[] {
  const prefix = t('{type} record').replace('{type}', 'TXT')
  const regions = check.regions ?? []
  if (check.status === 'verified') return []
  // Some regions already see the record: it only needs time.
  if (getLaggingRegions(check).length > 0) {
    return [
      {
        tone: 'warning',
        prefix: t('DNS is still propagating'),
        text: `${t('{n} of {total} regions see your new record.')
          .replace(
            '{n}',
            String(regions.filter((r) => r.status === 'verified').length),
          )
          .replace('{total}', String(regions.length))} ${t(
          'This usually finishes within an hour. Verify again in a few minutes.',
        )}`,
      },
    ]
  }
  switch (check.status) {
    case 'missing':
      return [
        {
          tone: 'warning',
          prefix,
          text: t(
            'No TXT record was found at {name} yet. Add the record below, then verify again.',
          ).replace('{name}', check.name),
        },
      ]
    case 'mismatch': {
      const more =
        check.current.length > 1
          ? ` ${t('and {n} more').replace('{n}', String(check.current.length - 1))}`
          : ''
      return [
        {
          tone: 'error',
          prefix,
          text: t('It holds {found}. Change it to {value}.')
            .replace('{found}', `${check.current[0] ?? ''}${more}`)
            .replace('{value}', check.expected),
        },
      ]
    }
    case 'misplaced':
      return [
        {
          tone: 'error',
          prefix,
          text: t(
            'It was added as {found}. In your DNS provider, enter only {name} as the host.',
          )
            .replace('{found}', getMisplacedName(check.name))
            .replace('{name}', getRecordName(check.name)),
        },
      ]
    default:
      return [
        {
          tone: 'warning',
          prefix: t('DNS lookup'),
          text: t("We couldn't check DNS. Try again in a few minutes."),
        },
      ]
  }
}

export function VerifyEmailDomain({
  rule,
  target,
  onOpenChange,
  onVerified,
  onCloseAutoFocus,
}: {
  rule: SSODomain | null
  target: SSOTarget
  onOpenChange: (open: boolean) => void
  onVerified?: (rule: SSODomain) => void
  /** Where focus goes on close: the dialog opens from state, not a trigger. */
  onCloseAutoFocus?: (event: Event) => void
}) {
  const t = useT()
  const later = useRef<HTMLButtonElement>(null)
  const [error, setError] = useState<DomainVerificationError | null>(null)
  const verify = useVerifySSODomain(target)
  // A verify refetches the list, so the domain there has the newest check.
  const { domains } = useSSODomains(target)
  const domain = (rule && domains.find((d) => d.$id === rule.$id)) ?? rule
  // Checking the record needs the Single sign-on add-on.
  const gate = useSSOAddonGate(target)
  const onAddonError = useSSOAddonErrorHandler(target)

  const close = (open: boolean) => {
    if (!open) setError(null)
    onOpenChange(open)
  }

  const notFound = () => ({
    title: t('Domain not verified yet'),
    message: t(
      'The TXT record was not found yet. DNS changes can take a few minutes to propagate.',
    ),
    pending: true,
  })

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
        setError(notFound())
      },
      onError: (failure: Error) => {
        if (onAddonError(failure)) return
        // A failed check is the record not being right yet. The domain's
        // check says what DNS had; an older server sends none, and its
        // message says it instead (no record yet, a wrong value and which,
        // or one it could not confirm), meant for the owner as it is.
        const info = getAppwriteErrorInfo(failure)
        if (info.type === 'rule_verification_failed') {
          setError(
            info.message
              ? { ...notFound(), message: info.message }
              : notFound(),
          )
          return
        }
        setError({
          title: t('Verification failed'),
          message: getErrorMessage(failure) || notFound().message,
        })
      },
    })
  }

  const record = domain ? getRecord(domain) : null
  const check = record?.check
  const banners = check ? getBanners(t, check) : []
  // The banners say what the check found, so they replace a pending alert.
  const shownError = banners.length > 0 && error?.pending ? null : error
  // Nothing checks on its own, so a record is never "not detected yet"; one
  // some regions already see only needs time, and its pill lists them.
  const propagating = !!check && getLaggingRegions(check).length > 0
  // Providers append the zone to the host, so a full domain there lands one
  // zone too deep. An apex is entered as @, which needs no note.
  const hostNote = check && record?.name !== '@' && (
    <p className="text-[13px] text-muted-foreground">
      {t(
        "Enter only {name} in your DNS provider's Host or Name field. Most providers add {apex} for you.",
      )
        .replace('{name}', record?.name ?? '')
        .replace('{apex}', getZone(check.name))}
    </p>
  )

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
            {domain?.domain}
          </p>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Add this TXT record at your DNS provider, then verify. It can take a few minutes to propagate.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-3 px-6 py-4">
          {domain && record && check ? (
            <div className="space-y-6">
              <DnsCheckMeta
                createdAt={domain.$createdAt}
                checkedAt={check.$updatedAt}
                regionCount={check.regions?.length ?? 0}
              />
              {/* Only the banners are live: the times above tick. */}
              <div role="status" aria-live="polite" className="space-y-3">
                {banners.map((banner) => (
                  <DnsBannerAlert
                    key={`${banner.tone}-${banner.prefix ?? ''}-${banner.text}`}
                    banner={banner}
                  />
                ))}
              </div>
              <RecordSection
                title={t('Prove ownership')}
                badge={t('Required')}
                records={[record]}
                waiting={propagating}
              >
                {hostNote}
              </RecordSection>
            </div>
          ) : record ? (
            <div className="overflow-x-auto">
              <DnsRecordsTable records={[record]} showTtl={true} fullValues />
            </div>
          ) : null}
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Until the domain is verified, nobody can sign in with single sign-on on it.',
            )}
          </p>
          <DomainVerificationAlert error={shownError} />
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
