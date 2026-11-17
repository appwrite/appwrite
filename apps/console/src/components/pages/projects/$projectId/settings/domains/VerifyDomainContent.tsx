import { Fragment, useState, useMemo, type ReactNode } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import type { ProxyRuleRecord } from '@/lib/appwrite/proxy-rules'
import { isWaitingForDns } from '@/lib/utils/status-badge'
import {
  getRecordName,
  getVerificationMethods,
  isCloud as isCloudEndpoint,
  isDomainClaimError,
  type VerificationMethod,
} from '@/lib/utils/proxy-domains'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'
import {
  Loader2,
  ExternalLink,
  Info,
  AlertCircle,
  CheckCircle2,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
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
import { RuleStatusBadge } from './RuleStatusBadge'

export const DNS_PROVIDERS_LINK = '/docs/advanced/platform/custom-domains'

/**
 * An apex record that names a host, as a CNAME does. Providers call it ALIAS,
 * ANAME or CNAME flattening and answer it with the host's addresses.
 */
const ALIAS_TYPE = 'ALIAS / ANAME'

const TAB_LABELS: Record<VerificationMethod, string> = {
  cname: 'CNAME',
  alias: 'ALIAS',
  nameservers: 'Nameservers',
  a: 'A',
  aaaa: 'AAAA',
}

/** The tab of each record type that points the domain at Appwrite. */
const POINTING_METHODS: Record<string, VerificationMethod> = {
  CNAME: 'cname',
  A: 'a',
  AAAA: 'aaaa',
}

/**
 * The check of an ALIAS record. DNS answers an ALIAS with the target's
 * addresses, so the server's A and AAAA checks are its check: verified when
 * either is, otherwise the one that asks the most of the developer, with the
 * addresses both found.
 */
function getAliasCheck(
  checks: ProxyRuleRecord[] | null,
  value: string,
): ProxyRuleRecord | undefined {
  const addresses =
    checks?.filter((check) => check.type === 'A' || check.type === 'AAAA') ?? []
  const check =
    addresses.find((c) => c.status === 'verified') ??
    addresses.find((c) => c.status === 'mismatch') ??
    addresses.find((c) => c.status === 'failed') ??
    addresses[0]
  if (!check) return undefined
  return {
    ...check,
    type: ALIAS_TYPE,
    value,
    found: [...new Set(addresses.flatMap((c) => c.found))],
  }
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
  /** The record type that already verifies the domain, when this one is an unused alternative. */
  notNeeded?: string
}

/** A section label, outlined so it never reads as a record status. */
const SECTION_BADGE =
  'border border-border bg-transparent text-[10px] shrink-0 font-medium text-muted-foreground'

const TABLE_HEAD =
  'px-4 py-2.5 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/** The status pill of one record at the last check. */
function RecordStatus({
  record,
  waiting,
}: {
  record: DnsRecord
  /** Still inside the automatic check window. */
  waiting: boolean
}) {
  const t = useT()
  const { check, notNeeded } = record
  if (!check) return <span className="text-muted-foreground">-</span>
  const pill = 'text-[10px] shrink-0'
  if (check.status !== 'verified' && notNeeded) {
    return (
      <Badge
        variant="inactive"
        className={pill}
        title={t('Not needed. Your {type} record is set.').replace(
          '{type}',
          notNeeded,
        )}
      >
        {t('Not needed')}
      </Badge>
    )
  }
  const title = check.message || undefined
  switch (check.status) {
    case 'verified':
      return (
        <Badge variant="success" className={pill}>
          {t('Verified')}
        </Badge>
      )
    case 'missing':
      return (
        <Badge variant="info" className={pill} title={title}>
          {waiting ? t('Not detected yet') : t('Not detected')}
        </Badge>
      )
    case 'mismatch':
      return (
        <Badge variant="error" className={pill} title={title}>
          {check.type === 'CAA' ? t('Blocked') : t('Wrong value')}
        </Badge>
      )
    default:
      return (
        <Badge variant="warning" className={pill} title={title}>
          {t("Couldn't check")}
        </Badge>
      )
  }
}

/** One group of records: a heading, a badge, then the records with their status. */
function RecordSection({
  title,
  badge,
  records,
  waiting,
  children,
}: {
  title: string
  badge: string
  records: DnsRecord[]
  waiting: boolean
  children?: ReactNode
}) {
  const t = useT()
  return (
    <section className="space-y-3">
      <div className="flex items-center gap-2">
        <h4 className="text-[14px] font-semibold text-foreground">{title}</h4>
        <Badge variant="outline" className={SECTION_BADGE}>
          {badge}
        </Badge>
      </div>
      <div className="overflow-hidden rounded-lg border border-border">
        <Table className="table-fixed">
          <TableHeader className="bg-muted/40">
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className={`w-[88px] ${TABLE_HEAD}`}>
                {t('Type')}
              </TableHead>
              <TableHead className={`w-[22%] ${TABLE_HEAD}`}>
                {t('Name')}
              </TableHead>
              <TableHead className={TABLE_HEAD}>{t('Value')}</TableHead>
              <TableHead
                className={`hidden sm:table-cell w-[72px] ${TABLE_HEAD}`}
              >
                TTL
              </TableHead>
              <TableHead className={`w-[156px] ${TABLE_HEAD} text-end`}>
                {t('Status')}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {records.map((r) => {
              const found =
                r.check?.status === 'mismatch' && !r.notNeeded
                  ? r.check.found
                  : []
              return (
                <Fragment key={`${r.type}-${r.value}`}>
                  <TableRow className={found.length ? 'border-b-0' : undefined}>
                    <TableCell className="whitespace-normal px-4 py-3 font-mono text-[12px]">
                      {r.type}
                    </TableCell>
                    <TableCell
                      className="px-4 py-3 font-mono text-[12px]"
                      title={r.name || undefined}
                    >
                      <CopyableId
                        id={r.name}
                        variant="inline"
                        size="md"
                        constrainToContainer
                        showCopyOnHover={false}
                        className="-my-1.5 -ms-2.5"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3" title={r.value}>
                      <CopyableId
                        id={r.value}
                        variant="inline"
                        size="md"
                        constrainToContainer
                        showCopyOnHover={false}
                        className="-my-1.5 -ms-2.5"
                      />
                    </TableCell>
                    <TableCell className="hidden sm:table-cell px-4 py-3 text-[12px] text-muted-foreground">
                      {r.ttl ?? '-'}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <RecordStatus record={r} waiting={waiting} />
                    </TableCell>
                  </TableRow>
                  {found.length > 0 && (
                    <TableRow className="hover:bg-transparent">
                      <TableCell colSpan={2} className="pt-0 pb-3" />
                      <TableCell
                        colSpan={3}
                        className="whitespace-normal px-4 pt-0 pb-3"
                      >
                        <p className="text-[12px] text-muted-foreground break-words">
                          {t('Found:')}{' '}
                          {found.map((value, i) => (
                            <Fragment key={value}>
                              {i > 0 && ', '}
                              <span
                                className={
                                  r.type === 'CAA'
                                    ? 'font-mono text-foreground'
                                    : 'font-mono text-foreground underline decoration-wavy decoration-red-500 underline-offset-4'
                                }
                              >
                                {value}
                              </span>
                            </Fragment>
                          ))}
                        </p>
                      </TableCell>
                    </TableRow>
                  )}
                </Fragment>
              )
            })}
          </TableBody>
        </Table>
      </div>
      {children}
    </section>
  )
}

type DnsBannerTone = 'info' | 'success'

const DNS_BANNER_TONES: Record<
  DnsBannerTone,
  { className: string; Icon: typeof Info }
> = {
  info: {
    className:
      'border-blue-500/30 bg-blue-500/5 [&>svg]:text-blue-600 dark:[&>svg]:text-blue-400',
    Icon: Info,
  },
  success: {
    className:
      'border-emerald-500/30 bg-emerald-500/5 [&>svg]:text-emerald-600 dark:[&>svg]:text-emerald-400',
    Icon: CheckCircle2,
  },
}

type DnsBanner = {
  tone: DnsBannerTone
  /** Bold lead-in, e.g. "CNAME record" */
  prefix?: string
  text: string
}

/** One problem, or the next step, above the records. */
function DnsBannerAlert({ banner }: { banner: DnsBanner }) {
  const { className, Icon } = DNS_BANNER_TONES[banner.tone]
  return (
    <Alert variant="default" className={className}>
      <Icon className="h-4 w-4" />
      <AlertDescription className="flex w-full items-start justify-between gap-4 text-[13px]">
        <p className="min-w-0 break-words text-muted-foreground">
          {banner.prefix && (
            <span className="font-medium text-foreground">
              {banner.prefix}:{' '}
            </span>
          )}
          {banner.text}
        </p>
      </AlertDescription>
    </Alert>
  )
}

function getDnsBanners(
  t: (text: string) => string,
  pointing: ProxyRuleRecord[],
): DnsBanner[] {
  const verified = pointing.find((check) => check.status === 'verified')
  if (!verified) {
    return [
      {
        tone: 'info',
        text: t(
          'Add the records below at your DNS provider. Appwrite checks again automatically for 3 days.',
        ),
      },
    ]
  }
  return [
    {
      tone: 'success',
      prefix: t('DNS is set up'),
      text: t('Appwrite will issue the SSL certificate next.'),
    },
  ]
}

/** Added · Status · Last check, above the records. */
function DnsCheckMeta({
  rule,
  checkedAt,
}: {
  rule: Models.ProxyRule
  checkedAt: string
}) {
  const t = useT()
  const label = 'text-[12px] text-muted-foreground'
  const value = 'mt-0.5 flex h-6 items-center text-[13px] text-foreground'
  return (
    <dl className="grid grid-cols-3 gap-4">
      <div className="min-w-0">
        <dt className={label}>{t('Added')}</dt>
        <dd className={value}>
          <DateTooltip date={rule.$createdAt} />
        </dd>
      </div>
      <div className="min-w-0">
        <dt className={label}>{t('Status')}</dt>
        <dd className={value}>
          <RuleStatusBadge rule={rule} />
        </dd>
      </div>
      <div className="min-w-0">
        <dt className={label}>{t('Last check')}</dt>
        <dd className={value}>
          <DateTooltip date={checkedAt} live />
        </dd>
      </div>
    </dl>
  )
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
          <Fragment key={`${r.type}-${i}`}>
            <TableRow>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                <span className="inline-flex flex-wrap items-center gap-1.5">
                  {r.type}
                  {r.badge ? (
                    <Badge
                      variant={
                        r.check?.required || r.badge === 'Required'
                          ? 'warning'
                          : 'success'
                      }
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
          </Fragment>
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
  // The records the last DNS check saved. Older servers and rules nobody
  // checked have none, and the screen shows only the records to add.
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

  const tabOptions = getVerificationMethods({
    domain: rule.domain,
    cloud: isCloud,
    cname,
    a,
    aaaa,
    nameservers,
  }).map((id) => ({ id, label: TAB_LABELS[id] }))

  const normalize = (value: string) => {
    const trimmed = value.trim().toLowerCase()
    return trimmed.endsWith('.') ? trimmed.slice(0, -1) : trimmed
  }

  // The server's check for the record of this type and value. There is at
  // most one CAA check, so it matches on type alone.
  const findCheck = (type: string, value: string) =>
    checks?.find(
      (check) =>
        check.type === type &&
        (type === 'CAA' || normalize(check.value) === normalize(value)),
    )

  // A function domain accepts more than one CNAME target. When DNS points at
  // another accepted one, that target is the one shown, with its check.
  const verifiedCname = checks?.find(
    (check) => check.type === 'CNAME' && check.status === 'verified',
  )?.value

  // Once one record points the domain at Appwrite, the others are not needed.
  const verifiedType = checks?.find(
    (check) => check.type in POINTING_METHODS && check.status === 'verified',
  )?.type

  const getRecord = (type: string, value: string): DnsRecord => {
    const check = findCheck(type, value)
    return {
      type,
      name: getRecordName(rule.domain),
      value,
      ttl: 3600,
      check,
      notNeeded:
        check && check.status !== 'verified' ? verifiedType : undefined,
    }
  }

  // The record each method asks for, with the status of its last check.
  const getPointingRecord = (method: VerificationMethod): DnsRecord | null => {
    switch (method) {
      case 'cname':
        return cname ? getRecord('CNAME', verifiedCname ?? cname) : null
      case 'alias':
        return cname
          ? {
              type: ALIAS_TYPE,
              name: '@',
              value: cname,
              ttl: 3600,
              check: getAliasCheck(checks, cname),
            }
          : null
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

  const aliasNote = (
    <p className="text-[13px] text-muted-foreground">
      {t(
        'Called CNAME flattening on Cloudflare (set it to DNS only) and Alias on Route 53. Some providers name it ANAME.',
      )}
    </p>
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

  const getNote = (method: VerificationMethod) =>
    method === 'nameservers' ? nameserverNote : recordNote

  const getNameserverRecords = (): DnsRecord[] =>
    nameservers.map((ns) => ({ type: 'NS', name: '@', value: ns, ttl: null }))

  // Without stored checks, each tab lists its record and the CAA record.
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
        {method === 'alias' && aliasNote}
      </div>
    )
  }

  // With stored checks, the records are grouped by what they do, each with
  // the status of its last check. The pointing records are alternatives.
  const pointingRecords = tabOptions.flatMap((tab) => {
    const record = getPointingRecord(tab.id)
    return record ? [record] : []
  })
  const pointingChecks = [
    ...pointingRecords.flatMap((record) =>
      record.check ? [record.check] : [],
    ),
  ]
  const offersNameservers = tabOptions.some((tab) => tab.id === 'nameservers')
  const banners = checks ? getDnsBanners(t, pointingChecks) : []

  const checkedRecords = checks ? (
    <div className="space-y-6">
      <DnsCheckMeta rule={rule} checkedAt={rule.$updatedAt} />
      <div className="space-y-3">
        {banners.map((banner) => (
          <DnsBannerAlert
            key={`${banner.tone}-${banner.prefix ?? ''}-${banner.text}`}
            banner={banner}
          />
        ))}
      </div>
      {pointingRecords.length > 0 && (
        <RecordSection
          title={t('Point your domain')}
          badge={
            pointingRecords.length > 1 ? t('Required · any one') : t('Required')
          }
          records={pointingRecords}
          waiting={isWaitingForDns(rule)}
        >
          {tabOptions.some((tab) => tab.id === 'alias') && aliasNote}
        </RecordSection>
      )}
      {caaRecord && (
        <RecordSection
          title={t('Allow the SSL certificate')}
          badge={caaCheck?.required ? t('Required') : t('Recommended')}
          records={[caaRecord]}
          waiting={isWaitingForDns(rule)}
        />
      )}
      {offersNameservers && (
        <section className="space-y-3">
          <div className="flex items-center gap-2">
            <h4 className="text-[14px] font-semibold text-foreground">
              {t('Nameservers')}
            </h4>
            <Badge variant="outline" className={SECTION_BADGE}>
              {t('Alternative')}
            </Badge>
          </div>
          <p className="text-[13px] text-muted-foreground">{nameserverNote}</p>
          <DnsRecordsTable records={getNameserverRecords()} showTtl={false} />
        </section>
      )}
    </div>
  ) : null

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
        {checkedRecords ??
          (tabOptions.length === 1 ? (
            <>
              <p className="pb-3 text-[13px] text-muted-foreground">
                {getNote(tabOptions[0].id)}
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
                      {getNote(tab.id)}
                    </p>
                    {methodContent(tab.id)}
                  </div>
                </TabsContent>
              ))}
            </Tabs>
          ))}
      </div>
      {!checks && (
        <>
          {!noCard && <div className="border-t border-border" />}
          <div className={noCard ? '' : `${padX} ${padY}`}>
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
        </>
      )}
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
