/**
 * Proxy domain utilities for Add domain flows (API, Functions, Sites)
 */

import { parse } from 'tldts'
import type { Models } from '@appwrite.io/console'

/**
 * Whether a rule offers its logs. A rule that is not verified always does. A
 * verified rule offers them once a certificate check has written any.
 */
export function canViewRuleLogs(rule: {
  status: string
  logs?: unknown
}): boolean {
  if (rule.status !== 'verified') return true
  if (typeof rule.logs === 'string') return rule.logs.length > 0
  if (Array.isArray(rule.logs)) return rule.logs.length > 0
  return false
}

/**
 * Whether a rule can be retried: its verification has not succeeded, or it is
 * verified and its certificate needs action, as its status badge shows: the
 * renewal failed or is blocked, or the certificate expired.
 */
export function canRetryRule(
  rule: { status: string; expireAt?: string; certificateStatus?: string },
  now: Date = new Date(),
): boolean {
  if (rule.status === 'created' || rule.status === 'unverified') return true
  if (rule.status !== 'verified') return false
  if (['failed', 'blocked'].includes(rule.certificateStatus ?? '')) return true
  return !!rule.expireAt && new Date(rule.expireAt).getTime() < now.getTime()
}

/**
 * Whether a deployment-type proxy rule routes to the resource's active deployment.
 * Matches Domains tab "Active deployment" rules (not branch-pinned, not redirect).
 */
export function proxyRuleServesActiveDeployment(
  rule: Models.ProxyRule,
  activeDeploymentId: string | undefined,
): boolean {
  if (!activeDeploymentId || rule.type !== 'deployment') return false
  if (rule.redirectUrl) return false

  if (rule.deploymentVcsProviderBranch) {
    return rule.deploymentId === activeDeploymentId
  }

  return true
}

import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import { isCloudEndpointUrl } from '@/lib/debug-endpoint'
import { getAppwriteErrorInfo } from '@/lib/utils/error-formatting'
import { consoleVariablesQueryOptions } from '@/lib/react-query/hooks/console-variables'
import { queryClient } from '@/lib/react-query/query-client'

/** Domain validation regex - allows subdomains and apex domains */
export const DOMAIN_REGEX =
  /^(?!-)[A-Za-z0-9-]+([\-.]{1}[a-z0-9]+)*\.[A-Za-z]{2,18}$/

/**
 * Validate domain format
 */
export function isValidDomain(domain: string): boolean {
  if (!domain || !domain.trim()) return false
  const normalized = domain.trim().toLowerCase()
  return DOMAIN_REGEX.test(normalized)
}

type DomainFormatError =
  | { code: 'url'; suggestion: string | null }
  | { code: 'wildcard' }
  | { code: 'trailing-dot' }
  | { code: 'spaces' }
  | { code: 'invalid' }

function looksLikeUrl(value: string): boolean {
  return value.includes('://') || value.includes('/')
}

/** Hostname of a pasted URL, without a trailing dot, or null when it has none. */
function bareHost(value: string): string | null {
  try {
    const url =
      value.includes('://') || value.startsWith('//')
        ? new URL(value.startsWith('//') ? `https:${value}` : value)
        : new URL(`https://${value}`)
    let host = url.hostname.toLowerCase()
    while (host.endsWith('.')) host = host.slice(0, -1)
    return host || null
  } catch {
    return null
  }
}

function hasWhitespace(value: string): boolean {
  return (
    value.includes(' ') ||
    value.includes('\t') ||
    value.includes('\n') ||
    value.includes('\r')
  )
}

/**
 * Why a custom domain is rejected, or null when it is empty or acceptable.
 * Acceptance stays `isValidDomain`, so uppercase is still valid (callers
 * lowercase it on save) and a wildcard or a trailing dot stays rejected,
 * matching Utopia's Domain validator.
 */
function getDomainFormatError(value: string): DomainFormatError | null {
  const trimmed = value.trim()
  if (!trimmed || isValidDomain(trimmed)) return null

  if (looksLikeUrl(trimmed)) {
    const host = bareHost(trimmed)
    if (host?.includes('*') || (!host && trimmed.includes('*'))) {
      return { code: 'wildcard' }
    }
    if (host && isValidDomain(host)) return { code: 'url', suggestion: host }
    return { code: 'url', suggestion: null }
  }

  if (trimmed.includes('*')) return { code: 'wildcard' }
  if (hasWhitespace(trimmed)) return { code: 'spaces' }
  if (trimmed.endsWith('.')) return { code: 'trailing-dot' }
  return { code: 'invalid' }
}

function domainFormatErrorMessage(
  error: DomainFormatError,
  t: (text: string) => string,
): string {
  switch (error.code) {
    case 'url':
      return error.suggestion
        ? `${t('Enter the hostname only:')} ${error.suggestion}`
        : t('Enter the hostname only, without https:// or a path.')
    case 'wildcard':
      return t('Wildcard domains are not supported. Enter a specific hostname.')
    case 'trailing-dot':
      return t('Remove the trailing dot.')
    case 'spaces':
      return t('Remove the spaces.')
    case 'invalid':
      return t('Please enter a valid domain')
  }
}

/**
 * Inline error for a custom-domain field.
 *
 * A non-empty invalid value is reported as it changes, so Add stays disabled
 * with the reason in view. Blur (`touched`) reports an empty field. Callers
 * still trim and lowercase a valid value before saving.
 */
export function domainFieldError(
  value: string,
  touched: boolean,
  t: (text: string) => string = (text) => text,
): string | null {
  if (!value.trim()) return touched ? t('Required') : null
  const error = getDomainFormatError(value)
  if (!error) return null
  return domainFormatErrorMessage(error, t)
}

/**
 * Get apex/root domain from a full domain (e.g. api.example.com → example.com)
 */
export function getApexDomain(domain: string): string | null {
  if (!domain?.trim()) return null
  const result = parse(domain.trim().toLowerCase())
  return result.domain ?? null
}

/**
 * DNS provider "Name" for a domain: `@` for an apex domain, otherwise the
 * labels in front of the registrable domain (`mail` for `mail.example.com`).
 * Providers append the zone themselves, so a full hostname here would end up
 * as `mail.example.com.example.com`.
 */
export function getRecordName(domain: string): string {
  const normalized = domain.trim().toLowerCase()
  const apex = getApexDomain(normalized)
  if (!apex || apex === normalized) return '@'
  return normalized.slice(0, -(apex.length + 1))
}

export type VerificationMethod = 'cname' | 'nameservers' | 'a' | 'aaaa'

/**
 * Ways a rule's domain can be pointed at Appwrite, the one to show first
 * leading. Most providers refuse a CNAME on an apex domain, so an apex leads
 * with the nameservers (Cloud only), then the A and AAAA records the server
 * also accepts, and keeps the CNAME last for providers that flatten it.
 */
export function getVerificationMethods(input: {
  domain: string
  cloud: boolean
  cname?: string
  a?: string
  aaaa?: string
  nameservers: string[]
}): VerificationMethod[] {
  const apex = getRecordName(input.domain) === '@'
  const methods: VerificationMethod[] = []
  if (input.cname && !apex) methods.push('cname')
  if (input.cloud && input.nameservers.length > 0) methods.push('nameservers')
  if (input.a && (!input.cloud || apex)) methods.push('a')
  if (input.aaaa && (!input.cloud || apex)) methods.push('aaaa')
  if (input.cname && apex) methods.push('cname')
  return methods
}

/**
 * TXT record that proves a project owns a domain: the project ID at
 * `_appwrite.<domain>`, named relative to the registrable domain.
 */
export function getClaimRecord(domain: string, projectId: string) {
  const name = getRecordName(domain)
  return {
    type: 'TXT' as const,
    name: name === '@' ? '_appwrite' : `_appwrite.${name}`,
    value: projectId,
    ttl: 3600,
  }
}

export type DomainClaimReason = 'pointed' | 'held' | 'organization'

/**
 * Why a rule create was refused until the project proves it owns the domain
 * with the claim record, or null when it was refused for another reason.
 * Cloud answers 400 `rule_verification_failed` for a domain that already
 * points to Appwrite (self-hosted never does on a create), 409
 * `rule_claim_required` for one another project holds, and 403
 * `domain_owned_by_different_organization` for one in a zone another
 * organization manages.
 */
export function getDomainClaimReason(error: unknown): DomainClaimReason | null {
  switch (getAppwriteErrorInfo(error).type) {
    case 'rule_verification_failed':
      return 'pointed'
    case 'rule_claim_required':
      return 'held'
    case 'domain_owned_by_different_organization':
      return 'organization'
    default:
      return null
  }
}

/** Whether a rule create was refused until the claim record is added. */
export function isDomainClaimError(error: unknown): boolean {
  return getDomainClaimReason(error) !== null
}

/**
 * Check if domain is Appwrite Cloud (isCloud)
 */
export function isCloud(): boolean {
  try {
    return isCloudEndpointUrl(getBaseEndpoint())
  } catch {
    return false
  }
}

/**
 * Zones Appwrite serves itself. A hostname in one of these is not the
 * organization's to register. Regional zones from console variables are
 * passed in alongside these, so the check holds when the variables fail to
 * load and still picks up any other zone they carry.
 */
const APPWRITE_SERVED_ZONES = [
  'appwrite.network',
  'appwrite.run',
  'appwrite.site',
  'appwrite.global',
]

export function isAppwriteServedDomain(
  domain: string,
  extraZones: readonly string[] = [],
): boolean {
  const normalized = domain.trim().toLowerCase()
  if (!normalized) return false
  return [...APPWRITE_SERVED_ZONES, ...extraZones].some(
    (zone) =>
      !!zone && (normalized === zone || normalized.endsWith(`.${zone}`)),
  )
}

/**
 * Whether adding this hostname would register a new organization apex while
 * the organization is already at its Cloud domain cap. A subdomain of an apex
 * the organization already has does not POST, and neither does a zone
 * Appwrite serves. Unknown membership does not block: the create still runs,
 * and the 402 toast stays the fallback.
 */
export function blocksNewOrganizationApex(input: {
  cloud: boolean
  atLimit: boolean
  domain: string
  extraZones?: readonly string[]
  /** False once the apex lookup confirms it is missing. Null while unknown. */
  apexRegistered: boolean | null
}): boolean {
  if (!input.cloud || !input.atLimit) return false
  const normalized = input.domain.trim().toLowerCase()
  if (!isValidDomain(normalized)) return false
  if (isAppwriteServedDomain(normalized, input.extraZones)) return false
  if (!getApexDomain(normalized)) return false
  return input.apexRegistered === false
}

/**
 * Ensure apex domain exists in organization (Cloud only).
 * Call before creating proxy rules for custom domains.
 * Ignores domain_already_exists and domain_owned_by_different_organization
 * as non-fatal.
 */
export async function ensureApexDomainInOrganization(
  teamId: string,
  domain: string,
  options?: { region?: string },
): Promise<void> {
  if (!isCloud() || !teamId || !domain?.trim()) return

  // A zone Appwrite serves is not the organization's to register, as the
  // server's isAppwriteOwned holds. Cloud's zones are listed so the check holds
  // when the variables fail to load, and the variables, which carry only the
  // first sites and functions zone of the project's region, add any other.
  const extraZones: string[] = []
  try {
    const variables = await queryClient.fetchQuery(
      consoleVariablesQueryOptions(options?.region),
    )
    extraZones.push(
      variables._APP_DOMAIN_SITES ?? '',
      variables._APP_DOMAIN_FUNCTIONS ?? '',
    )
  } catch {
    // Without the variables, still register a custom domain.
  }
  if (isAppwriteServedDomain(domain, extraZones)) return

  const apex = getApexDomain(domain)
  if (!apex) return

  try {
    const { domains } = await sdk.forConsole.domains.list({
      queries: [Query.equal('teamId', teamId), Query.equal('domain', apex)],
    })
    if (domains && domains.length > 0) return // Already in org
  } catch {
    // Ignore list errors
  }

  try {
    await sdk.forConsole.domains.create({
      teamId,
      domain: apex,
    })
  } catch (err: unknown) {
    const e = err as { type?: string; message?: string }
    if (
      e?.type === 'domain_already_exists' ||
      e?.message?.includes('already exists')
    ) {
      return // Non-fatal
    }
    // Part of this domain belongs to another organization. The rule create
    // decides, and asks for the claim record when that organization manages it.
    if (e?.type === 'domain_owned_by_different_organization') return
    throw err
  }

  // The add wizards read this list with refetchOnMount: false. Without a
  // refresh, a subdomain of the apex just created still looks over the cap.
  await queryClient.invalidateQueries({
    queryKey: ['domains', 'organization', teamId],
  })
}
