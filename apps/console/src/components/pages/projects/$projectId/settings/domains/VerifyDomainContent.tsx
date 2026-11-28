import { useState, useMemo } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import type { ProxyRuleRecord } from '@/lib/appwrite/proxy-rules'
import {
  getLaggingRegions,
  isDnsUnverified,
  isWaitingForDns,
} from '@/lib/utils/status-badge'
import {
  getMisplacedName,
  getRecordName,
  getVerificationMethods,
  getZone,
  isCloud as isCloudEndpoint,
  type VerificationMethod,
} from '@/lib/utils/proxy-domains'
import type { Models } from '@appwrite.io/console'
import { Loader2, ExternalLink, AlertCircle } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import {
  DNS_PROVIDERS_LINK,
  DnsBannerAlert,
  type DnsBanner,
} from '@/components/global/shared/dns/DnsBannerAlert'
import { DnsCheckMeta } from '@/components/global/shared/dns/DnsCheckMeta'
import {
  ALIAS_TYPE,
  DnsRecordsTable,
  type DnsRecord,
} from '@/components/global/shared/dns/DnsRecordsTable'
import {
  DomainVerificationAlert,
  type DomainVerificationError,
} from '@/components/global/shared/dns/DomainVerificationAlert'
import {
  RecordSection,
  SECTION_BADGE,
} from '@/components/global/shared/dns/RecordSection'
import { useT } from '@/lib/i18n/translate'
import { RuleStatusBadge } from './RuleStatusBadge'

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
 * either is, otherwise the one that asks the most of the developer. It keeps
 * no current values or message: they are the addresses the domain resolves to,
 * never the target the developer set.
 */
function getAliasCheck(
  checks: ProxyRuleRecord[] | null,
  value: string,
): ProxyRuleRecord | undefined {
  const addresses =
    checks?.filter((check) => check.type === 'A' || check.type === 'AAAA') ?? []
  const check =
    addresses.find((c) => c.status === 'verified') ??
    addresses.find((c) => getLaggingRegions(c).length > 0) ??
    addresses.find((c) => c.status === 'mismatch') ??
    addresses.find((c) => c.status === 'failed') ??
    addresses[0]
  if (!check) return undefined
  return {
    ...check,
    type: ALIAS_TYPE,
    expected: value,
    current: [],
    message: '',
    regions: check.regions?.map((region) => ({ ...region, current: [] })),
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

/**
 * What the stored checks ask of the developer: one banner per problem, or the
 * next step when nothing is wrong. CNAME, A and AAAA are alternatives, so a
 * wrong one stops mattering once another is verified.
 */
function getDnsBanners(
  t: (text: string) => string,
  rule: Models.ProxyRule,
  pointing: ProxyRuleRecord[],
  caa: ProxyRuleRecord | undefined,
  conflict: ProxyRuleRecord | undefined,
): DnsBanner[] {
  const verified = pointing.find((check) => check.status === 'verified')
  const banners: DnsBanner[] = []
  // A CAA record some regions already accept is still propagating, not wrong
  if (caa?.status === 'mismatch' && getLaggingRegions(caa).length === 0) {
    banners.push({
      tone: 'error',
      prefix: t('CAA record'),
      text: t(
        'A CAA record on your domain blocks the certificate. Add the CAA record below next to it (recommended), or delete your existing CAA records.',
      ),
    })
  }
  if (!verified && conflict) {
    banners.push({
      tone: 'error',
      prefix: t('{type} record').replace('{type}', conflict.type),
      text: t(
        'Your domain has an {type} record ({found}) instead of a CNAME, often a URL redirect or parking record at your DNS provider. Delete it, then add the CNAME below.',
      )
        .replace('{type}', conflict.type)
        .replace('{found}', conflict.current[0] ?? ''),
    })
  }
  if (!verified) {
    for (const check of pointing) {
      // A record some regions already see only needs time.
      if (getLaggingRegions(check).length > 0) continue
      if (check.status === 'misplaced') {
        banners.push({
          tone: 'error',
          prefix: t('{type} record').replace('{type}', check.type),
          text: t(
            'It was added as {found}. In your DNS provider, enter only {name} as the host.',
          )
            .replace('{found}', getMisplacedName(check.name))
            .replace('{name}', getRecordName(check.name)),
        })
        continue
      }
      if (check.status !== 'mismatch') continue
      // DNS shows no ALIAS target, only the addresses it resolves to.
      if (check.type === ALIAS_TYPE) {
        banners.push({
          tone: 'error',
          prefix: t('{type} record').replace('{type}', t('ALIAS or ANAME')),
          text: t(
            'Your domain points somewhere else. Set this record to {value}.',
          ).replace('{value}', check.expected),
        })
        continue
      }
      const more =
        check.current.length > 1
          ? ` ${t('and {n} more').replace('{n}', String(check.current.length - 1))}`
          : ''
      banners.push({
        tone: 'error',
        prefix: t('{type} record').replace('{type}', check.type),
        text: t('It points to {found}. Change it to {value}.')
          .replace('{found}', `${check.current[0] ?? ''}${more}`)
          .replace('{value}', check.expected),
      })
    }
  }
  if (banners.length > 0) return banners

  // Some regions see the record and others do not yet: nothing to fix, and
  // not a failure on Appwrite's side either.
  // Only while Appwrite still checks on its own: past that window it needs fixing.
  const propagating =
    verified || !isWaitingForDns(rule)
      ? undefined
      : pointing.find((check) => getLaggingRegions(check).length > 0)
  if (propagating) {
    const regions = propagating.regions ?? []
    const seen = regions.filter((r) => r.status === 'verified').length
    return [
      {
        tone: 'warning',
        prefix: t('DNS is still propagating'),
        text: `${t('{n} of {total} regions see your new record.')
          .replace('{n}', String(seen))
          .replace('{total}', String(regions.length))} ${t(
          "This usually finishes within an hour, and Appwrite keeps checking. You don't need to change anything.",
        )}`,
      },
    ]
  }
  if (!verified && pointing.some((check) => check.status === 'failed')) {
    return [
      {
        tone: 'warning',
        prefix: t('DNS lookup'),
        text: t(
          "We couldn't check DNS. This is on our side; Appwrite will try again.",
        ),
      },
    ]
  }
  if (!verified) {
    return [
      isDnsUnverified(rule) && !isWaitingForDns(rule)
        ? {
            tone: 'error',
            prefix: t('Domain verification'),
            text: t(
              'Automatic checks have stopped. Fix the records below, then verify again.',
            ),
          }
        : {
            tone: 'info',
            text: t(
              'Add the records below at your DNS provider. Appwrite checks again automatically for 3 days.',
            ),
          },
    ]
  }
  // A failed lookup says nothing about the CAA record, so it asks for no change.
  if (caa?.status === 'failed') {
    return [
      {
        tone: 'warning',
        prefix: t('CAA record'),
        text: t(
          "We couldn't check the CAA record. This is on our side; Appwrite will try again.",
        ),
      },
    ]
  }
  if (caa?.required && caa.status !== 'verified') {
    return [
      {
        tone: 'info',
        prefix: t('CAA record'),
        text: t(
          'Your {type} record is set. Add the CAA record below to finish.',
        ).replace('{type}', verified.type),
      },
    ]
  }
  // The certificate can still be stuck, as the status badge shows.
  if (
    rule.status === 'verifying' &&
    rule.certificateStatus === 'blocked' &&
    !!rule.logs?.length
  ) {
    return [
      {
        tone: 'warning',
        prefix: t('DNS is set up'),
        text: t(
          'The certificate is waiting on a DNS record. Add the record shown in View logs.',
        ),
        dnsSetUp: true,
      },
    ]
  }
  // Nothing retries an unverified rule, so the next step is Retry.
  if (rule.status === 'unverified' && rule.certificateStatus) {
    return [
      {
        tone: 'warning',
        prefix: t('DNS is set up'),
        text:
          rule.certificateStatus === 'blocked'
            ? t(
                'The certificate is waiting on a DNS record. Add the record shown in View logs, then Retry.',
              )
            : t(
                'The SSL certificate could not be issued. Check View logs, then Retry.',
              ),
        dnsSetUp: true,
      },
    ]
  }
  return [
    {
      tone: 'success',
      prefix: t('DNS is set up'),
      text:
        rule.status === 'verified'
          ? t('All records are verified.')
          : rule.status === 'verifying'
            ? t(
                'SSL certificate is being issued. This usually takes a couple of minutes.',
              )
            : t('Appwrite will issue the SSL certificate next.'),
      dnsSetUp: true,
    },
  ]
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
  const records: ProxyRuleRecord[] | null | undefined = rule.records
  const checks = records?.length ? records : null
  // A subdomain points with its CNAME, so an A or AAAA record found where the
  // CNAME is missing answers in its place: a provider's URL redirect or parking
  // record, which never reaches Appwrite.
  const conflict =
    getRecordName(rule.domain) !== '@' &&
    checks?.some(
      (check) =>
        check.type === 'CNAME' &&
        check.status === 'missing' &&
        getLaggingRegions(check).length === 0,
    )
      ? checks.find(
          (check) =>
            (check.type === 'A' || check.type === 'AAAA') &&
            check.status === 'mismatch' &&
            check.current.length > 0 &&
            getLaggingRegions(check).length === 0,
        )
      : undefined

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
        (type === 'CAA' || normalize(check.expected) === normalize(value)),
    )

  // A function domain accepts more than one CNAME target. When DNS points at
  // another accepted one, that target is the one shown, with its check.
  const verifiedCname = checks?.find(
    (check) => check.type === 'CNAME' && check.status === 'verified',
  )?.expected

  // Once one record points the domain at Appwrite, the others are not needed.
  // A record some regions already see counts too: it is only propagating.
  const verifiedType =
    checks?.find(
      (check) => check.type in POINTING_METHODS && check.status === 'verified',
    )?.type ??
    checks?.find(
      (check) =>
        check.type in POINTING_METHODS && getLaggingRegions(check).length > 0,
    )?.type

  const getRecord = (type: string, value: string): DnsRecord => {
    const check = findCheck(type, value)
    return {
      type,
      name: getRecordName(rule.domain),
      value,
      ttl: 3600,
      check,
      // The propagating record is the one that points the domain, so it is
      // never an unused alternative of itself.
      notNeeded:
        check &&
        check.status !== 'verified' &&
        !(check.type === verifiedType && getLaggingRegions(check).length > 0)
          ? verifiedType
          : undefined,
    }
  }

  // The record each method asks for, with the status of its last check.
  const getPointingRecord = (method: VerificationMethod): DnsRecord | null => {
    switch (method) {
      case 'cname': {
        if (!cname) return null
        const record = getRecord('CNAME', verifiedCname ?? cname)
        return record.check?.status === 'missing' && conflict
          ? { ...record, conflict }
          : record
      }
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

  // The pointing records are alternatives: any one found verifies the domain.
  const isMethodVerified = (method: VerificationMethod) =>
    getPointingRecord(method)?.check?.status === 'verified'

  // The tab whose record verifies the domain opens first.
  const verifiedMethod = tabOptions.find((tab) => isMethodVerified(tab.id))?.id

  const selectedTab =
    activeTab && tabOptions.some((tab) => tab.id === activeTab)
      ? (activeTab as VerificationMethod)
      : (verifiedMethod ?? tabOptions[0]?.id ?? 'cname')

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

  // Providers append the zone to the host, so a full domain there lands one
  // zone too deep. An apex is entered as @, which needs no note.
  const recordName = getRecordName(rule.domain)
  const hostNote = recordName !== '@' && (
    <p className="text-[13px] text-muted-foreground">
      {t(
        "Enter only {name} in your DNS provider's Host or Name field. Most providers add {apex} for you.",
      )
        .replace('{name}', recordName)
        .replace('{apex}', getZone(rule.domain))}
    </p>
  )

  // The CAA row: recommended from the console variables, required once the
  // server checks it.
  const caaCheck = findCheck('CAA', '')
  const caaRecord: DnsRecord | null =
    hasCaa || caaCheck
      ? {
          type: 'CAA',
          // A blocking CAA record can sit on a parent of the domain, and the
          // new one goes next to it.
          name:
            caaCheck?.status === 'mismatch'
              ? getRecordName(caaCheck.name)
              : '@',
          value: hasCaa
            ? caa!
            : caaCheck!.expected.includes(' ')
              ? caaCheck!.expected
              : `0 issue "${caaCheck!.expected}"`,
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
  // A record that verifies the domain counts even when this screen does not
  // ask for it, e.g. an A record on a subdomain.
  const pointingChecks = [
    ...pointingRecords.flatMap((record) =>
      record.check ? [record.check] : [],
    ),
    ...(checks ?? []).filter(
      (check) => check.type in POINTING_METHODS && check.status === 'verified',
    ),
  ]
  const offersNameservers = tabOptions.some((tab) => tab.id === 'nameservers')
  const banners = checks
    ? getDnsBanners(t, rule, pointingChecks, caaCheck, conflict)
    : []
  // The banners above the records already say what to do next, so they
  // replace the generic "wait and try again" alert. The rule updates live, so
  // that alert also goes once its DNS has passed.
  const dnsSetUp = banners.some((banner) => banner.dnsSetUp)
  const shownError =
    (banners.length > 0 || !isDnsUnverified(rule)) &&
    typeof verificationError === 'object' &&
    verificationError?.pending
      ? null
      : verificationError

  // Each record's update time is its last check, so the newest is the domain's
  const checkedAt =
    checks?.reduce(
      (last, check) => (check.$updatedAt > last ? check.$updatedAt : last),
      '',
    ) || rule.$updatedAt

  const checkedRecords = checks ? (
    <div className="space-y-6">
      <DnsCheckMeta
        createdAt={rule.$createdAt}
        status={<RuleStatusBadge rule={rule} />}
        checkedAt={checkedAt}
        regionCount={Math.max(
          0,
          ...checks.map((check) => check.regions?.length ?? 0),
        )}
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
          {hostNote}
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
      <DomainVerificationAlert error={shownError} />
      {rule.status === 'verifying' && !dnsSetUp && (
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
