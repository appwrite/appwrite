import type { Models } from '@appwrite.io/console'
import type { ProxyRuleRecordRegion } from '@/lib/appwrite/proxy-rules'

/**
 * What DNS had for a domain's TXT record at its last check. The pinned Console
 * SDK's `Models.SsoDomain` has no `check` yet; move to the SDK model once the
 * pin carries it.
 */
export type SSODomainCheck = {
  type: 'TXT'
  /** The name it was looked up at, e.g. `_appwrite.example.com`. */
  name: string
  /** The value the record must hold, e.g. `team:<teamId>`. */
  expected: string
  /** The values found, at most 10. */
  current: string[]
  /** Per-region results, empty when only one region checks. */
  regions: ProxyRuleRecordRegion[]
  status: 'verified' | 'missing' | 'mismatch' | 'misplaced' | 'failed'
  /** Why the check did not pass. Empty when verified. */
  message: string
  checkedAt: string
}

/**
 * An email domain an organization claimed for single sign-on. `status` is its
 * ownership; `check` the last DNS check, absent on older servers and before
 * any check ran.
 */
export type SSODomain = Models.SsoDomain & {
  check?: SSODomainCheck | null
}

export type SSODomainList = Omit<Models.SsoDomainList, 'domains'> & {
  domains: SSODomain[]
}

/** An organization's identity provider. The client secret never leaves the server. */
export type SSOProvider = Models.SsoProvider

export type SSOProviderList = Models.SsoProviderList

/** One way to sign in, as offered for an email on the sign-in page. */
export type SSOSignInProvider = Models.SsoSignInProvider

export type SSOSignInProviderList = Models.SsoSignInProviderList

export type OidcProviderParams = {
  name: string
  roles: string[]
  clientId: string
  /** Omit on update to keep the stored secret. */
  clientSecret?: string
  wellKnownURL?: string
  authorizationURL?: string
  tokenURL?: string
  userInfoURL?: string
  enabled?: boolean
}
