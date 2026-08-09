import { useState, useMemo } from 'react'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import { getApexDomain } from '@/lib/utils/proxy-domains'
import type { Models } from '@appwrite.io/console'
import { Copy, Check, Loader2, ExternalLink, Info, AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { toast } from 'sonner'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

const DNS_PROVIDERS_LINK =
  '/docs/advanced/platform/custom-domains'

export type DomainVerificationError = {
  title: string
  message: string
  /** DNS not ready yet - show amber guidance instead of a hard error */
  pending?: boolean
}

/** Shared alert when proxy-rule DNS is not confirmed yet. */
export function dnsPendingVerificationError(
  t: (text: string) => string,
): DomainVerificationError {
  return {
    title: t('Domain not verified yet'),
    message: t(
      'DNS changes can take up to 48 hours to propagate. Confirm the records below at your DNS provider, wait a bit, then try again.',
    ),
    pending: true,
  }
}

interface VerifyDomainContentProps {
  rule: Models.ProxyRule
  region?: string
  /** Compact layout for modals */
  compact?: boolean
  /** Omit card wrapper (e.g. when used inside a modal that provides its own container) */
  noCard?: boolean
  /**
   * Which resource the custom domain points to.
   * - 'api' → use `_APP_DOMAIN_TARGET_CNAME` from console variables (project endpoint host).
   * - 'function' / 'site' → when the active profile has `edgeNetwork` enabled,
   *   use the edge network CNAME (`appwrite.network`) instead of the project
   *   endpoint host. Falls back to console variables when edge network is off.
   * Defaults to 'api' for backward compatibility.
   */
  resourceType?: 'api' | 'function' | 'site'
  onChange?: () => void
  onVerify?: () => void
  isVerifying?: boolean
  isChanging?: boolean
  /** Inline verification error (string or structured alert) */
  verificationError?: DomainVerificationError | string | null
}

type DnsRecord = {
  type: string
  name: string
  value: string
  ttl: number | null
  badge?: string
}

function DnsRecordsTable({
  records,
  onCopy,
  copiedField,
  showTtl,
}: {
  records: DnsRecord[]
  onCopy: (text: string, field: string) => void
  copiedField: string | null
  showTtl: boolean
}) {
  const t = useT()
  return (
    <Table>
      <TableHeader>
        <TableRow className="hover:bg-transparent border-b border-border">
          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
            {t('Type')}
          </TableHead>
          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
            {t('Name')}
          </TableHead>
          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
            {t('Value')}
          </TableHead>
          {showTtl && (
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
              TTL
            </TableHead>
          )}
          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[60px]" />
        </TableRow>
      </TableHeader>
      <TableBody>
        {records.map((r, i) => (
          <TableRow key={`${r.type}-${i}`}>
            <TableCell className="px-4 py-3 font-mono text-[12px]">
              <span className="inline-flex items-center gap-1.5">
                {r.type}
                {r.badge ? (
                  <Badge
                    variant="outline"
                    className="text-[10px] shrink-0 font-sans font-medium normal-case tracking-normal"
                  >
                    {t(r.badge)}
                  </Badge>
                ) : null}
              </span>
            </TableCell>
            <TableCell
              className="px-4 py-3 font-mono text-[12px] truncate max-w-[180px]"
              title={r.name || '-'}
            >
              {r.name || '-'}
            </TableCell>
            <TableCell
              className="px-4 py-3 font-mono text-[12px] truncate max-w-[200px]"
              title={r.value}
            >
              {r.value}
            </TableCell>
            {showTtl && (
              <TableCell className="px-4 py-3 text-end text-[12px] text-muted-foreground">
                {r.ttl ?? '-'}
              </TableCell>
            )}
            <TableCell className="px-4 py-3 text-end">
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0"
                onClick={() => onCopy(r.value, `${r.type}-${i}`)}
              >
                {copiedField === `${r.type}-${i}` ? (
                  <Check className="h-3.5 w-3.5" />
                ) : (
                  <Copy className="h-3.5 w-3.5" />
                )}
              </Button>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}

export function VerifyDomainContent({
  rule,
  region,
  compact,
  noCard,
  resourceType = 'api',
  onChange,
  onVerify,
  isVerifying,
  isChanging,
  verificationError,
}: VerifyDomainContentProps) {
  const t = useT()
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<string | null>(null)
  const {
    cname: rawCname,
    a,
    aaaa,
    caa,
    nameservers,
    isLoading,
    error,
  } = useConsoleVariables(region)

  const isCloud = useMemo(() => {
    try {
      return getBaseEndpoint().includes('cloud.appwrite.io')
    } catch {
      return false
    }
  }, [])

  const { features } = useConsoleProfile()
  const edgeNetworkEnabled = features.edgeNetwork

  // For Function and Site custom domains, when the active profile has the edge
  // network enabled the CNAME must target the edge network host
  // (`appwrite.network`) - not the project endpoint host returned by
  // `_APP_DOMAIN_TARGET_CNAME` (which only applies to custom API domains).
  // For region-pinned routing users can use `<region>.appwrite.run`.
  const cname = useMemo(() => {
    if (
      edgeNetworkEnabled &&
      (resourceType === 'function' || resourceType === 'site')
    ) {
      return 'appwrite.network'
    }
    return rawCname
  }, [edgeNetworkEnabled, resourceType, rawCname])

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    toast.success(t('Copied'))
    setTimeout(() => setCopiedField(null), 2000)
  }

  const hasCname = !!cname
  const hasNameservers = isCloud && nameservers.length > 0
  const hasA = !isCloud && !!a
  const hasAaaa = !isCloud && !!aaaa
  const hasCaa = !!caa

  const tabOptions = [
    ...(hasCname ? [{ id: 'cname' as const, label: 'CNAME' }] : []),
    ...(hasNameservers
      ? [{ id: 'nameservers' as const, label: 'Nameservers' }]
      : []),
    ...(hasA ? [{ id: 'a' as const, label: 'A' }] : []),
    ...(hasAaaa ? [{ id: 'aaaa' as const, label: 'AAAA' }] : []),
  ]

  const selectedTab =
    activeTab && tabOptions.some((tab) => tab.id === activeTab)
      ? activeTab
      : (tabOptions[0]?.id ?? 'cname')

  // Must run before any early return so hook count stays stable across renders.
  const isApex = useMemo(() => {
    const apex = getApexDomain(rule.domain)
    return !!apex && apex === rule.domain.trim().toLowerCase()
  }, [rule.domain])

  if (isLoading) {
    return (
      <div className="flex min-h-[180px] items-center justify-center">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (error || tabOptions.length === 0) {
    return (
      <Alert
        variant="default"
        className="border-red-500/30 bg-red-500/10"
      >
        <AlertCircle className="h-4 w-4 text-red-600 dark:text-red-400" />
        <AlertDescription className="text-[13px] text-red-600 dark:text-red-400">
          {t('Failed to load DNS instructions. Please try again.')}
        </AlertDescription>
      </Alert>
    )
  }

  const recordNote = t(
    'Add the following record(s) to your DNS provider. Note that DNS changes may take up to 48 hours to propagate fully.',
  )
  const nameserverNote = t(
    'Add the following nameservers on your DNS provider. Note that DNS changes may take up to 48 hours to propagate fully.',
  )

  const apexCnameNote = (
    <Alert
      variant="default"
      className="border-border bg-muted/30 [&>svg]:text-muted-foreground"
    >
      <Info className="h-4 w-4" />
      <AlertDescription className="text-[13px] text-muted-foreground">
        <p className="leading-relaxed">
          {t('Since')}{' '}
          <code className="rounded bg-muted px-1 py-0.5 font-mono text-foreground">
            {rule.domain}
          </code>{' '}
          {t(
            "is an apex domain, CNAME record is only supported by certain providers. If yours doesn't, please verify using",
          )}{' '}
          {hasNameservers ? (
            <button
              type="button"
              className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
              onClick={() => setActiveTab('nameservers')}
            >
              {t('nameservers')}
            </button>
          ) : (
            <span className="font-medium text-foreground">
              {t('an A or AAAA record')}
            </span>
          )}{' '}
          {t(
            "instead. If you're using Cloudflare or another CDN, make sure the proxy is disabled (set to DNS only) for this record, since Appwrite serves your domain through its own CDN.",
          )}
        </p>
      </AlertDescription>
    </Alert>
  )

  const getCnameRecordName = (domain: string): string => {
    const parts = domain.split('.')
    return parts.length > 2 ? parts[0] : '@'
  }

  const getCnameRecords = (): DnsRecord[] => {
    const rows: DnsRecord[] = [
      {
        type: 'CNAME',
        name: getCnameRecordName(rule.domain),
        value: cname!,
        ttl: 3600,
      },
    ]
    if (hasCaa) {
      rows.push({
        type: 'CAA',
        name: '@',
        value: caa!,
        ttl: 3600,
        badge: 'Recommended',
      })
    }
    return rows
  }

  const getNameserverRecords = (): DnsRecord[] =>
    nameservers.map((ns) => ({ type: 'NS', name: '', value: ns, ttl: null }))

  const cardClassName =
    'rounded-xl border border-border bg-card/50 overflow-hidden'
  const padX = compact ? 'px-4' : 'px-6'
  const padY = compact ? 'py-3' : 'py-4'

  const innerContent = (
    <>
      {!noCard && (
        <>
          <div className={`${padX} ${padY}`}>
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Verification')}
            </h3>
            <p className="text-[13px] text-muted-foreground font-mono mt-2">
              {rule.domain}
            </p>
          </div>
          <div className="border-t border-border" />
        </>
      )}
      <div className={noCard ? 'space-y-4' : `${padX} ${padY}`}>
        {tabOptions.length === 1 ? (
          <>
            <p className="pb-3 text-[13px] text-muted-foreground">
              {tabOptions[0].id === 'nameservers' ? nameserverNote : recordNote}
            </p>
            {tabOptions[0].id === 'cname' && (
              <div className="space-y-3">
                <DnsRecordsTable
                  records={getCnameRecords()}
                  onCopy={handleCopy}
                  copiedField={copiedField}
                  showTtl={true}
                />
                {isApex && apexCnameNote}
              </div>
            )}
            {tabOptions[0].id === 'nameservers' && (
              <DnsRecordsTable
                records={getNameserverRecords()}
                onCopy={handleCopy}
                copiedField={copiedField}
                showTtl={false}
              />
            )}
            {tabOptions[0].id === 'a' && (
              <DnsRecordsTable
                records={[
                  { type: 'A', name: rule.domain, value: a!, ttl: 3600 },
                ]}
                onCopy={handleCopy}
                copiedField={copiedField}
                showTtl={true}
              />
            )}
            {tabOptions[0].id === 'aaaa' && (
              <DnsRecordsTable
                records={[
                  { type: 'AAAA', name: rule.domain, value: aaaa!, ttl: 3600 },
                ]}
                onCopy={handleCopy}
                copiedField={copiedField}
                showTtl={true}
              />
            )}
          </>
        ) : (
          <Tabs
            value={selectedTab}
            onValueChange={setActiveTab}
            className="w-full"
          >
            <div className="pb-4">
              <TabsList>
                {tabOptions.map((tab) => (
                  <TabsTrigger key={tab.id} value={tab.id}>
                    {tab.label}
                  </TabsTrigger>
                ))}
              </TabsList>
            </div>
            {tabOptions.map((tab) => (
              <TabsContent key={tab.id} value={tab.id} className="mt-0">
                <div className="pb-4">
                  <p className="pb-3 text-[13px] text-muted-foreground">
                    {tab.id === 'nameservers' ? nameserverNote : recordNote}
                  </p>
                  {tab.id === 'cname' && (
                    <div className="space-y-3">
                      <DnsRecordsTable
                        records={getCnameRecords()}
                        onCopy={handleCopy}
                        copiedField={copiedField}
                        showTtl={true}
                      />
                      {isApex && apexCnameNote}
                    </div>
                  )}
                  {tab.id === 'nameservers' && (
                    <DnsRecordsTable
                      records={getNameserverRecords()}
                      onCopy={handleCopy}
                      copiedField={copiedField}
                      showTtl={false}
                    />
                  )}
                  {tab.id === 'a' && (
                    <DnsRecordsTable
                      records={[
                        { type: 'A', name: rule.domain, value: a!, ttl: 3600 },
                      ]}
                      onCopy={handleCopy}
                      copiedField={copiedField}
                      showTtl={true}
                    />
                  )}
                  {tab.id === 'aaaa' && (
                    <DnsRecordsTable
                      records={[
                        {
                          type: 'AAAA',
                          name: rule.domain,
                          value: aaaa!,
                          ttl: 3600,
                        },
                      ]}
                      onCopy={handleCopy}
                      copiedField={copiedField}
                      showTtl={true}
                    />
                  )}
                </div>
              </TabsContent>
            ))}
          </Tabs>
        )}
      </div>
      {!noCard && <div className="border-t border-border" />}
      <div className={noCard ? '' : `${padX} ${padY}`}>
        <p className="text-[13px] text-muted-foreground">
          {t('A list of domain providers and their DNS settings is available')}{' '}
          <DocsRouteLink className="inline-flex items-center gap-0.5 font-medium text-foreground underline hover:no-underline" href={DNS_PROVIDERS_LINK}>
            {t('here')}
            <ExternalLink className="h-3 w-3 shrink-0" />
          </DocsRouteLink>
          .
        </p>
      </div>
    </>
  )

  const structuredError =
    verificationError && typeof verificationError !== 'string'
      ? verificationError
      : null
  const plainError =
    typeof verificationError === 'string' ? verificationError : null
  const errorPending = structuredError?.pending ?? false
  const errorTitle =
    structuredError?.title ??
    (plainError ? t('Verification failed') : null)
  const errorMessage = structuredError?.message ?? plainError

  return (
    <div className={compact ? 'space-y-4' : 'space-y-6'}>
      {errorMessage && errorTitle && (
        <Alert
          variant="default"
          className={
            errorPending
              ? 'border-amber-500/30 bg-amber-500/10 [&>svg]:text-amber-600 dark:[&>svg]:text-amber-400'
              : 'border-red-500/30 bg-red-500/10 [&>svg]:text-red-600 dark:[&>svg]:text-red-400'
          }
        >
          {errorPending ? (
            <Info className="h-4 w-4" />
          ) : (
            <AlertCircle className="h-4 w-4" />
          )}
          <AlertTitle
            className={
              errorPending
                ? 'text-[13px] font-medium text-amber-700 dark:text-amber-400'
                : 'text-[13px] font-medium text-red-600 dark:text-red-400'
            }
          >
            {errorTitle}
          </AlertTitle>
          <AlertDescription
            className={
              errorPending
                ? 'text-[13px] text-amber-700/90 dark:text-amber-400/90'
                : 'text-[13px] text-red-600 dark:text-red-400'
            }
          >
            {errorMessage}
          </AlertDescription>
        </Alert>
      )}
      {rule.status === 'verifying' && (
        <Alert variant="default" className="border-blue-500/30 bg-blue-500/5">
          <AlertDescription className="text-[13px] text-muted-foreground">
            {t(
              'SSL certificate is being issued. This usually takes a couple of minutes - no action needed on your end.',
            )}
          </AlertDescription>
        </Alert>
      )}
      {noCard ? (
        <div className="space-y-4">{innerContent}</div>
      ) : (
        <div className={cardClassName}>{innerContent}</div>
      )}
      {onVerify && (
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {onChange && (
            <Button
              variant="outline"
              size="sm"
              onClick={onChange}
              disabled={isVerifying || isChanging}
            >
              {t('Change')}
            </Button>
          )}
          <Button
            size="sm"
            onClick={onVerify}
            disabled={isVerifying || isChanging}
          >
            {t('Verify')}
          </Button>
        </div>
      )}
    </div>
  )
}
