/** An email domain an organization claimed for single sign-on. */
export type SSODomain = {
  $id: string
  $createdAt: string
  $updatedAt: string
  teamId: string
  domain: string
  /** `unverified` until the TXT record is found, then `verified`. */
  status: 'unverified' | 'verified'
  /** TXT record that proves the organization owns the domain. */
  recordName: string
  recordValue: string
}

export type SSODomainList = {
  total: number
  domains: SSODomain[]
}

/** An organization's identity provider. The client secret never leaves the server. */
export type SSOProvider = {
  $id: string
  $createdAt: string
  $updatedAt: string
  teamId: string
  type: 'oidc'
  name: string
  /** Roles people get when they join through the provider. */
  roles: string[]
  enabled: boolean
  clientId: string
  clientSecretSet: boolean
  wellKnownURL: string
  authorizationURL: string
  tokenURL: string
  userInfoURL: string
}

export type SSOProviderList = {
  total: number
  providers: SSOProvider[]
}

/** One way to sign in, as offered for an email on the sign-in page. */
export type SSOSignInProvider = {
  $id: string
  name: string
}

export type SSOSignInProviderList = {
  total: number
  providers: SSOSignInProvider[]
}

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
