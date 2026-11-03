import { Query, type Models } from '@appwrite.io/console'
import { isCloudProfile } from '@/lib/console-profiles'
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
  /** Host relative to the registrable domain, as DNS providers expect it. */
  name: string
  value: string
  ttl: number
  /** MX preference; Cloud only checks the target, so any value works. */
  priority?: number
}

/**
 * DNS provider "Name" for a domain: `@` for an apex domain, otherwise the
 * labels in front of the registrable domain (`mail` for `mail.example.com`).
 * Providers append the zone themselves, so a full hostname here would end up
 * as `mail.example.com.example.com`.
 */
export function getSmtpRecordName(domain: string): string {
  const normalized = domain.trim().toLowerCase()
  const apex = getApexDomain(normalized)
  if (!apex || apex === normalized) return '@'
  return normalized.slice(0, -(apex.length + 1))
}

/**
 * Extra list queries for views that only want web (HTTP) domains. Filtering
 * on the server keeps email rows from taking up the fetched page. Only sent on
 * Cloud: self-hosted rules have no `protocol` attribute to query.
 */
export function webDomainQueries(): string[] {
  return isCloudProfile() ? [Query.equal('protocol', ['http'])] : []
}

/** SMTP rules share the HTTP rules list; `protocol` tells them apart. */
export function isSmtpRule(rule: Models.ProxyRule): rule is SmtpProxyRule {
  return (rule as SmtpProxyRule).protocol === 'smtp'
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
  const name = getSmtpRecordName(domain)

  return [
    ...(mxTarget
      ? [
          {
            type: 'MX' as const,
            name,
            value: mxTarget,
            ttl: 3600,
            priority: 10,
          },
        ]
      : []),
    {
      type: 'TXT',
      name: name === '@' ? '_appwrite' : `_appwrite.${name}`,
      value: projectId,
      ttl: 3600,
    },
  ]
}
