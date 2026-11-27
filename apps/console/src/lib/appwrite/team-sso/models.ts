import type { Models } from '@appwrite.io/console'

/** An email domain an organization claimed for single sign-on. */
export type SSODomain = Models.SsoDomain

export type SSODomainList = Models.SsoDomainList

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
