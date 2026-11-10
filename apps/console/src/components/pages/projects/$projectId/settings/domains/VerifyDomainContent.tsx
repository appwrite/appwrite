import { useState, useMemo } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import {
  getApexDomain,
  getRecordName,
  getVerificationMethods,
  isCloud as isCloudEndpoint,
  isDomainClaimError,
  type VerificationMethod,
} from '@/lib/utils/proxy-domains'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'
import { Loader2, ExternalLink, Info, AlertCircle } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { CopyableId } from '@/components/global/shared/CopyableId'
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
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

export const DNS_PROVIDERS_LINK =
  '/docs/advanced/platform/custom-domains'

const TAB_LABELS: Record<VerificationMethod, string> = {
  cname: 'CNAME',
  nameservers: 'Nameservers',
  a: 'A',
  aaaa: 'AAAA',
}

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

/**
 * Alert for a failed verify call. A failed DNS check or a refused ownership
 * claim keeps the amber pending style and leads with the API's reason;
 * anything else is a hard failure.
 */
export function domainVerificationError(
  t: (text: string) => string,
  error: unknown,
): DomainVerificationError {
  const pending = dnsPendingVerificationError(t)
  if (isDomainClaimError(error)) {
    const { message } = getAppwriteErrorInfo(error)
    return message
      ? { ...pending, message: `${message} ${pending.message}` }
      : pending
  }
  return {
    title: t('Verification failed'),
    message: getErrorMessage(error, t('Failed to verify domain')),
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
   *   endpoint host. When edge network is off (self-hosted), functions use
   *   `_APP_DOMAIN_TARGET_CNAME` like API domains and sites use `_APP_DOMAIN_SITES`,
   *   matching the targets the server verifies against.
   * Defaults to 'api' for backward compatibility.
   */
  resourceType?: 'api' | 'function' | 'site'
  /** Inline verification error (string or structured alert) */
  verificationError?: DomainVerificationError | string | null
}

export type DnsRecord = {
  type: string
  name: string
  value: string
  ttl: number | null
  /** MX preference, shown when the table has a Priority column. */
  priority?: number
  badge?: string
}

export function DnsRecordsTable({
  records,
  showTtl,
  showPriority = false,
}: {
  records: DnsRecord[]
  showTtl: boolean
  showPriority?: boolean
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
          {showPriority && (
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
              {t('Priority')}
            </TableHead>
          )}
          {showTtl && (
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
              TTL
            </TableHead>
          )}
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
              className="px-4 py-3 font-mono text-[12px]"
              title={r.name || undefined}
            >
              {r.name ? (
                <CopyableId
                  id={r.name}
                  variant="inline"
                  size="md"
                  maxWidth={180}
                  showCopyOnHover={false}
                  className="-my-1.5 -ms-2.5"
                />
              ) : (
                '-'
              )}
            </TableCell>
            <TableCell className="px-4 py-3" title={r.value}>
              <CopyableId
                id={r.value}
                variant="inline"
                size="md"
                maxWidth={200}
                showCopyOnHover={false}
                className="-my-1.5 -ms-2.5"
              />
            </TableCell>
            {showPriority && (
              <TableCell className="px-4 py-3 text-end text-[12px] text-muted-foreground">
                {r.priority ?? '-'}
              </TableCell>
            )}
            {showTtl && (
              <TableCell className="px-4 py-3 text-end text-[12px] text-muted-foreground">
                {r.ttl ?? '-'}
              </TableCell>
            )}
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}

/** Amber "not verified yet" or red failure alert above the DNS records. */
export function DomainVerificationAlert({
  error,
}: {
  error?: DomainVerificationError | string | null
}) {
  const t = useT()
  const structuredError = error && typeof error !== 'string' ? error : null
  const plainError = typeof error === 'string' ? error : null
  const errorPending = structuredError?.pending ?? false
  const errorTitle =
    structuredError?.title ?? (plainError ? t('Verification failed') : null)
  const errorMessage = structuredError?.message ?? plainError

  if (!errorMessage || !errorTitle) return null

  return (
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
  )
}

export function VerifyDomainContent({
  rule,
  region,
  compact,
  noCard,
  resourceType = 'api',
  verificationError,
}: VerifyDomainContentProps) {
  const t = useT()
  const [activeTab, setActiveTab] = useState<string | null>(null)
  const {
    cname: rawCname,
    a,
    aaaa,
    caa,
    nameservers,
    sitesDomain,
    isLoading,
    error,
  } = useConsoleVariables(region)

  const isCloud = useMemo(() => isCloudEndpoint(), [])

  const { features } = useConsoleProfile()
  const edgeNetworkEnabled = features.edgeNetwork

  // For Function and Site custom domains, when the active profile has the edge
  // network enabled the CNAME must target the edge network host
  // (`appwrite.network`) - not the project endpoint host returned by
  // `_APP_DOMAIN_TARGET_CNAME` (which only applies to custom API domains).
  // For region-pinned routing users can use `<region>.appwrite.run`.
  // Self-hosted verifies function domains against `_APP_DOMAIN_TARGET_CNAME`
  // (same as API domains) and site domains against `_APP_DOMAIN_SITES`.
  const cname = useMemo(() => {
    if (resourceType === 'function' || resourceType === 'site') {
      if (edgeNetworkEnabled) {
        return 'appwrite.network'
      }
      if (resourceType === 'site') {
        return sitesDomain || rawCname
      }
    }
    return rawCname
  }, [edgeNetworkEnabled, resourceType, rawCname, sitesDomain])

  const hasNameservers = isCloud && nameservers.length > 0
  // Older servers send `0 issue ""` for an unset CAA target, a record that
  // would block every CA.
  const hasCaa = !!caa && caa !== '0 issue ""'

  // On the edge network the CNAME targets appwrite.network, while A and AAAA
  // name the region's address, so only the CNAME and nameservers are offered.
  const edge = edgeNetworkEnabled && resourceType !== 'api'
  const tabOptions = getVerificationMethods({
    domain: rule.domain,
    cloud: isCloud,
    cname,
    a: edge ? undefined : a,
    aaaa: edge ? undefined : aaaa,
    nameservers,
  }).map((id) => ({ id, label: TAB_LABELS[id] }))

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

  const getCnameRecords = (): DnsRecord[] => {
    const rows: DnsRecord[] = [
      {
        type: 'CNAME',
        name: getRecordName(rule.domain),
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
                  showTtl={true}
                />
                {isApex && apexCnameNote}
              </div>
            )}
            {tabOptions[0].id === 'nameservers' && (
              <DnsRecordsTable
                records={getNameserverRecords()}
                showTtl={false}
              />
            )}
            {tabOptions[0].id === 'a' && (
              <DnsRecordsTable
                records={[
                  {
                    type: 'A',
                    name: getRecordName(rule.domain),
                    value: a!,
                    ttl: 3600,
                  },
                ]}
                showTtl={true}
              />
            )}
            {tabOptions[0].id === 'aaaa' && (
              <DnsRecordsTable
                records={[
                  {
                    type: 'AAAA',
                    name: getRecordName(rule.domain),
                    value: aaaa!,
                    ttl: 3600,
                  },
                ]}
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
                        showTtl={true}
                      />
                      {isApex && apexCnameNote}
                    </div>
                  )}
                  {tab.id === 'nameservers' && (
                    <DnsRecordsTable
                      records={getNameserverRecords()}
                      showTtl={false}
                    />
                  )}
                  {tab.id === 'a' && (
                    <DnsRecordsTable
                      records={[
                        {
                          type: 'A',
                          name: getRecordName(rule.domain),
                          value: a!,
                          ttl: 3600,
                        },
                      ]}
                      showTtl={true}
                    />
                  )}
                  {tab.id === 'aaaa' && (
                    <DnsRecordsTable
                      records={[
                        {
                          type: 'AAAA',
                          name: getRecordName(rule.domain),
                          value: aaaa!,
                          ttl: 3600,
                        },
                      ]}
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

  return (
    <div className={compact ? 'space-y-4' : 'space-y-6'}>
      <DomainVerificationAlert error={verificationError} />
      {rule.status === 'verifying' && (
        <Alert variant="default" className="border-blue-500/30 bg-blue-500/5">
          <AlertDescription className="text-[13px] text-muted-foreground">
            {t(
              "SSL certificate is being issued. This usually takes a few minutes. If the logs ask for a DNS record, add it where your domain's DNS is managed.",
            )}
          </AlertDescription>
        </Alert>
      )}
      {noCard ? (
        <div className="space-y-4">{innerContent}</div>
      ) : (
        <div className={cardClassName}>{innerContent}</div>
      )}
    </div>
  )
}
