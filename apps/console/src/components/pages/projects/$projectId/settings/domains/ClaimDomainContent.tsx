import { ExternalLink } from 'lucide-react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'
import { getClaimRecord } from '@/lib/utils/proxy-domains'
import {
  DNS_PROVIDERS_LINK,
  DnsRecordsTable,
  DomainVerificationAlert,
  type DomainVerificationError,
} from './VerifyDomainContent'

interface ClaimDomainContentProps {
  domain: string
  projectId: string
  verificationError?: DomainVerificationError | null
  /** Another project holds the domain, rather than it only pointing to Appwrite */
  claimRequired?: boolean
}

/**
 * Ownership step for a rule create that was refused: the domain already
 * points to Appwrite or is used by another project, so the project proves it
 * is its own with a TXT record before the create is sent again.
 */
export function ClaimDomainContent({
  domain,
  projectId,
  verificationError,
  claimRequired = false,
}: ClaimDomainContentProps) {
  const t = useT()

  return (
    <div className="space-y-6">
      <DomainVerificationAlert error={verificationError} />
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Verification')}
          </h3>
          <p className="text-[13px] text-muted-foreground font-mono mt-2">
            {domain}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-3">
          <p className="text-[13px] text-muted-foreground">
            {claimRequired
              ? t(
                  'This domain is used by another project. Add this TXT record to prove it is yours, then verify to move it here.',
                )
              : t(
                  'This domain already points to Appwrite. Add this TXT record to prove it is yours, then verify.',
                )}
          </p>
          <DnsRecordsTable
            records={[getClaimRecord(domain, projectId)]}
            showTtl={true}
          />
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t(
              'A list of domain providers and their DNS settings is available',
            )}{' '}
            <DocsRouteLink
              className="inline-flex items-center gap-0.5 font-medium text-foreground underline hover:no-underline"
              href={DNS_PROVIDERS_LINK}
            >
              {t('here')}
              <ExternalLink className="h-3 w-3 shrink-0" />
            </DocsRouteLink>
            .
          </p>
        </div>
      </div>
    </div>
  )
}
