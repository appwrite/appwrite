import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Loader2, ExternalLink, Info, AlertCircle } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { toast } from 'sonner'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import {
  DNS_PROVIDERS_LINK,
  DnsRecordsTable,
  DomainVerificationAlert,
  dnsPendingVerificationError,
  type DomainVerificationError,
} from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import {
  getSmtpDnsRecords,
  type SmtpProxyRule,
} from '@/lib/function-smtp-domain'

interface VerifySmtpDomainContentProps {
  rule: SmtpProxyRule
  region?: string
  verificationError?: DomainVerificationError | string | null
  /** Omit the card wrapper, for a dialog that provides its own container. */
  noCard?: boolean
}

/**
 * MX + ownership TXT instructions for custom inbound email domains. Same
 * layout as the HTTP VerifyDomainContent (CNAME / nameservers / TLS).
 */
export function VerifySmtpDomainContent({
  rule,
  region,
  verificationError,
  noCard,
}: VerifySmtpDomainContentProps) {
  const t = useT()
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const { smtpTarget, isLoading } = useConsoleVariables(region)
  const { projectId } = useParams({ strict: false })
  const mxTarget = typeof smtpTarget === 'string' ? smtpTarget.trim() : ''

  const records = projectId
    ? getSmtpDnsRecords({ domain: rule.domain, projectId, mxTarget })
    : []

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    toast.success(t('Copied'))
    setTimeout(() => setCopiedField(null), 2000)
  }

  if (isLoading) {
    return (
      <div className="flex min-h-[180px] items-center justify-center">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const instructions = (
    <>
      <p className="text-[13px] text-muted-foreground">
        {t(
          'Add the following record(s) to your DNS provider. Note that DNS changes may take up to 48 hours to propagate fully.',
        )}
      </p>
      {!mxTarget ? (
        <Alert
          variant="default"
          className="border-red-500/30 bg-red-500/10 [&>svg]:text-red-600 dark:[&>svg]:text-red-400"
        >
          <AlertCircle className="h-4 w-4" />
          <AlertDescription className="text-[13px] text-red-600 dark:text-red-400">
            {t(
              'This region does not report its mail server yet, so the MX record cannot be shown. Add the TXT record now and come back for the MX record later.',
            )}
          </AlertDescription>
        </Alert>
      ) : null}
      {records.length > 0 ? (
        <DnsRecordsTable
          records={records}
          onCopy={handleCopy}
          copiedField={copiedField}
          showTtl={true}
          showPriority={!!mxTarget}
        />
      ) : null}
      <Alert
        variant="default"
        className="border-border bg-muted/30 [&>svg]:text-muted-foreground"
      >
        <Info className="h-4 w-4" />
        <AlertDescription className="text-[13px] text-muted-foreground">
          {t(
            'Mail is delivered to Appwrite on port 25, so the MX record must point straight at the value above, not at a proxied hostname.',
          )}
        </AlertDescription>
      </Alert>
    </>
  )

  const providers = (
    <p className="text-[13px] text-muted-foreground">
      {t('A list of domain providers and their DNS settings is available')}{' '}
      <DocsRouteLink
        className="inline-flex items-center gap-0.5 font-medium text-foreground underline hover:no-underline"
        href={DNS_PROVIDERS_LINK}
      >
        {t('here')}
        <ExternalLink className="h-3 w-3 shrink-0" />
      </DocsRouteLink>
      .
    </p>
  )

  if (noCard) {
    return (
      <div className="space-y-4">
        <DomainVerificationAlert error={verificationError} />
        {instructions}
        {providers}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <DomainVerificationAlert error={verificationError} />
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Verification')}
          </h3>
          <p className="text-[13px] text-muted-foreground font-mono mt-2">
            {rule.domain}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-3">{instructions}</div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">{providers}</div>
      </div>
    </div>
  )
}

export { dnsPendingVerificationError }
