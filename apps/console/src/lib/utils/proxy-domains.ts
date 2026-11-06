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

/**
 * Whether a rule create was refused until the project proves it owns the
 * domain with the claim record. Cloud answers 400 `rule_verification_failed`
 * for a domain that already points to Appwrite (self-hosted never does on a
 * create), and 409 `rule_claim_required` for one another project holds.
 */
export function isDomainClaimError(error: unknown): boolean {
  const { type } = getAppwriteErrorInfo(error)
  return type === 'rule_verification_failed' || type === 'rule_claim_required'
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
 * Check if domain is a built-in site subdomain (e.g. *.appwrite.site)
 * Skip apex registration for these per spec
 */
export function isBuiltInSiteSubdomain(domain: string): boolean {
  if (!domain?.trim()) return false
  const d = domain.trim().toLowerCase()
  return d.endsWith('.appwrite.site') || d.includes('_app_domain_sites')
}

/**
 * Ensure apex domain exists in organization (Cloud only).
 * Call before creating proxy rules for custom domains.
 * Ignores domain_already_exists as non-fatal.
 */
export async function ensureApexDomainInOrganization(
  teamId: string,
  domain: string,
  options?: { skipForSites?: boolean; region?: string },
): Promise<void> {
  if (!isCloud() || !teamId || !domain?.trim()) return
  if (options?.skipForSites && isBuiltInSiteSubdomain(domain)) return

  // A zone Appwrite serves is not the organization's to register, as the
  // server's isAppwriteOwned holds. Cloud's zones are listed so the check holds
  // when the variables fail to load, and the variables, which carry only the
  // first sites and functions zone of the project's region, add any other.
  const owned = ['appwrite.network', 'appwrite.run', 'appwrite.site']
  try {
    const variables = await queryClient.fetchQuery(
      consoleVariablesQueryOptions(options?.region),
    )
    owned.push(
      variables._APP_DOMAIN_SITES ?? '',
      variables._APP_DOMAIN_FUNCTIONS ?? '',
    )
  } catch {
    // Without the variables, still register a custom domain.
  }
  const normalized = domain.trim().toLowerCase()
  if (
    owned.some(
      (zone) =>
        !!zone && (normalized === zone || normalized.endsWith(`.${zone}`)),
    )
  ) {
    return
  }

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
    throw err
  }
}
