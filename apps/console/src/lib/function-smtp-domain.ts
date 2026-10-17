import { Query, type Models } from '@appwrite.io/console'
import { getApexDomain } from '@/lib/utils/proxy-domains'

/**
 * Cloud SMTP (inbound email) proxy rules, separate from HTTP function domains
 * and from outbound project SMTP / Messaging providers.
 *
 * Create: POST /v1/proxy/rules/smtp
 * Verify: MX → `_APP_DOMAIN_TARGET_SMTP`, TXT `_appwrite.{domain}` = project ID
 *   (the same ownership record HTTP domain claims use).
 */

export type SmtpProxyRule = Models.ProxyRule & {
  protocol?: string
}

export type SmtpDnsRecord = {
  type: 'MX' | 'TXT'
  name: string
  value: string
}

/** Absolute TXT hostname Cloud checks during SMTP verification. */
export function getSmtpTxtHost(domain: string): string {
  return `_appwrite.${domain.trim().toLowerCase()}`
}

/**
 * DNS provider "Name" for the MX record. Apex domains use `@`; otherwise the
 * full hostname (matches how HTTP domain verification labels CNAME names).
 */
export function getSmtpMxRecordName(domain: string): string {
  const normalized = domain.trim().toLowerCase()
  const apex = getApexDomain(normalized)
  if (apex && apex === normalized) return '@'
  return normalized
}

/**
 * Server-side `protocol` filter for the proxy rules list (Cloud only).
 *
 * Cloud overrides `listRules` to accept `protocol` in queries, and a schema
 * patch backfills existing rules to `protocol='http'` before this Console
 * feature ships. Filtering on the server keeps limit/offset and `total`
 * correct for each protocol. Self-hosted rules have no `protocol` attribute,
 * so callers must only send this on Cloud.
 */
export function proxyRuleProtocolQuery(protocol: 'http' | 'smtp'): string {
  return Query.equal('protocol', [protocol])
}

export function needsSmtpDnsVerification(
  rule: SmtpProxyRule | null | undefined,
): boolean {
  if (!rule) return false
  // Cloud may return `created` before normalizing to `unverified` in responses.
  const status = String(rule.status)
  return status === 'created' || status === 'unverified'
}

/**
 * Records the user must publish for a custom inbound email domain.
 * The MX record is left out while the region does not report its MX target
 * (the UI explains why); nothing is returned without a domain or project ID.
 */
export function getSmtpDnsRecords(params: {
  domain: string
  projectId: string
  mxTarget: string
}): SmtpDnsRecord[] {
  const domain = params.domain.trim().toLowerCase()
  const projectId = params.projectId.trim()
  const mxTarget = params.mxTarget.trim()
  if (!domain || !projectId) return []

  return [
    ...(mxTarget
      ? [
          {
            type: 'MX' as const,
            name: getSmtpMxRecordName(domain),
            value: mxTarget,
          },
        ]
      : []),
    {
      type: 'TXT',
      name: getSmtpTxtHost(domain),
      value: projectId,
    },
  ]
}
