import { useState, useMemo } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import type { ProxyRuleRecord } from '@/lib/appwrite/proxy-rules'
import {
  getRecordName,
  getVerificationMethods,
  isCloud as isCloudEndpoint,
  isDomainClaimError,
  type VerificationMethod,
} from '@/lib/utils/proxy-domains'
import { getErrorMessage } from '@/lib/utils/error-formatting'
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

export const DNS_PROVIDERS_LINK = '/docs/advanced/platform/custom-domains'

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
 * claim keeps the amber pending style; the claim step's own copy and record
 * table explain what to add, so the API's message is not repeated. Anything
 * else is a hard failure.
 */
export function domainVerificationError(
  t: (text: string) => string,
  error: unknown,
): DomainVerificationError {
  if (isDomainClaimError(error)) {
    return dnsPendingVerificationError(t)
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
  /** What DNS had for this record at the last check. */
  check?: ProxyRuleRecord
}

export function DnsRecordsTable({
  records,
  showTtl,
  showPriority = false,
  compact = false,
}: {
  records: DnsRecord[]
  showTtl: boolean
  showPriority?: boolean
  /** Hide the TTL column, e.g. in modals */
  compact?: boolean
}) {
  const t = useT()
  const ttl = showTtl && !compact
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
          {ttl && (
            <TableHead className="hidden sm:table-cell px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
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
            {ttl && (
              <TableCell className="hidden sm:table-cell px-4 py-3 text-end text-[12px] text-muted-foreground">
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
  const records = (rule as Models.ProxyRule & { records?: ProxyRuleRecord[] })
    .records
  const checks = records?.length ? records : null

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

  // The server's check for the record of this type and value. There is at
  // most one CAA check, so it matches on type alone.
  const findCheck = (type: string, value: string) =>
    checks?.find(
      (check) =>
        check.type === type && (type === 'CAA' || check.value === value),
    )

  const getRecord = (type: string, value: string): DnsRecord => {
    return {
      type,
      name: getRecordName(rule.domain),
      value,
      ttl: 3600,
    }
  }

  const getPointingRecord = (method: VerificationMethod): DnsRecord | null => {
    switch (method) {
      case 'cname':
        return cname ? getRecord('CNAME', cname) : null
      case 'a':
        return a ? getRecord('A', a) : null
      case 'aaaa':
        return aaaa ? getRecord('AAAA', aaaa) : null
      default:
        return null
    }
  }

  const selectedTab =
    activeTab && tabOptions.some((tab) => tab.id === activeTab)
      ? activeTab
      : (tabOptions[0]?.id ?? 'cname')

  if (isLoading) {
    return (
      <div className="flex min-h-[180px] items-center justify-center">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (error || tabOptions.length === 0) {
    return (
      <Alert variant="default" className="border-red-500/30 bg-red-500/10">
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

  // The CAA row: recommended from the console variables, required once the
  // server checks it.
  const caaCheck = findCheck('CAA', '')
  const caaRecord: DnsRecord | null =
    hasCaa || caaCheck
      ? {
          type: 'CAA',
          name: '@',
          value: hasCaa
            ? caa!
            : caaCheck!.value.includes(' ')
              ? caaCheck!.value
              : `0 issue "${caaCheck!.value}"`,
          ttl: 3600,
          badge: caaCheck?.required ? 'Required' : 'Recommended',
          check: caaCheck,
        }
      : null

  const getNameserverRecords = (): DnsRecord[] =>
    nameservers.map((ns) => ({ type: 'NS', name: '@', value: ns, ttl: null }))

  const methodContent = (method: VerificationMethod) => {
    if (method === 'nameservers') {
      return (
        <DnsRecordsTable records={getNameserverRecords()} showTtl={false} />
      )
    }
    const record = getPointingRecord(method)
    const rows = [
      ...(record ? [record] : []),
      ...(caaRecord ? [caaRecord] : []),
    ]
    return (
      <div className="space-y-3">
        <DnsRecordsTable records={rows} showTtl={true} compact={compact} />
      </div>
    )
  }

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
            {methodContent(tabOptions[0].id)}
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
                  {methodContent(tab.id)}
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
