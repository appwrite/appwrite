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
 * Whether verification of a rule can be retried
 */
export function canRetryRule(status: string): boolean {
  return status === 'created' || status === 'unverified'
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
  options?: { skipForSites?: boolean },
): Promise<void> {
  if (!isCloud() || !teamId || !domain?.trim()) return
  if (options?.skipForSites && isBuiltInSiteSubdomain(domain)) return

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
