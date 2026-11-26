/** Verified email domains of an organization, lowercased for matching. */
export function getVerifiedEmailDomains(
  rules: ReadonlyArray<{ domain: string; status: string }>,
): Set<string> {
  return new Set(
    rules
      .filter((rule) => rule.status === 'verified')
      .map((rule) => rule.domain.toLowerCase()),
  )
}

/**
 * Whether an email is on one of the verified domains. Exact match: a subdomain
 * is its own domain and is verified on its own.
 */
export function isInternalEmail(
  email: string | null | undefined,
  verifiedDomains: ReadonlySet<string>,
): boolean {
  const at = email?.lastIndexOf('@') ?? -1
  if (!email || at < 0) return false
  return verifiedDomains.has(email.slice(at + 1).toLowerCase())
}
