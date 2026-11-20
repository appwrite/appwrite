/**
 * Hand-written client for the Cloud single sign-on endpoints on Teams
 * (`teams.*SSODomain`, `teams.*SSOProvider`, `account.listSSOProviders`): they
 * have not shipped in a Console SDK release yet. Delete this folder once the
 * Console SDK carries them.
 */

import type { Client } from '@appwrite.io/console'

import type {
  OidcProviderParams,
  SSODomain,
  SSODomainList,
  SSOProvider,
  SSOProviderList,
  SSOSignInProviderList,
} from './models'

export class TeamSSO {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  listDomains(teamId: string): Promise<SSODomainList> {
    return this.call('get', `/teams/${this.id(teamId)}/sso/domains`)
  }

  /** Claim an email domain. It stays unverified until its TXT record is found. */
  createDomain(teamId: string, domain: string): Promise<SSODomain> {
    return this.call('post', `/teams/${this.id(teamId)}/sso/domains`, {
      domain,
    })
  }

  /** Check the TXT record; resolves with the domain, verified, or throws. */
  updateDomainStatus(teamId: string, domainId: string): Promise<SSODomain> {
    return this.call(
      'patch',
      `/teams/${this.id(teamId)}/sso/domains/${this.id(domainId)}/status`,
    )
  }

  /**
   * Require single sign-on for the organization, or stop requiring it. Members on
   * its verified domains then reach it only on a session from single sign-on;
   * owners are exempt. Requiring it needs a verified domain and an enabled
   * provider. Resolves with the organization.
   */
  updateRequired(
    teamId: string,
    required: boolean,
  ): Promise<{ $id: string; ssoRequired: boolean }> {
    return this.call('patch', `/teams/${this.id(teamId)}/sso/required`, {
      required,
    })
  }

  deleteDomain(teamId: string, domainId: string): Promise<unknown> {
    return this.call(
      'delete',
      `/teams/${this.id(teamId)}/sso/domains/${this.id(domainId)}`,
    )
  }

  listProviders(teamId: string): Promise<SSOProviderList> {
    return this.call('get', `/teams/${this.id(teamId)}/sso/providers`)
  }

  createOidcProvider(
    teamId: string,
    params: OidcProviderParams,
  ): Promise<SSOProvider> {
    return this.call(
      'post',
      `/teams/${this.id(teamId)}/sso/providers/oidc`,
      params,
    )
  }

  updateOidcProvider(
    teamId: string,
    providerId: string,
    params: OidcProviderParams,
  ): Promise<SSOProvider> {
    return this.call(
      'put',
      `/teams/${this.id(teamId)}/sso/providers/oidc/${this.id(providerId)}`,
      params,
    )
  }

  deleteProvider(teamId: string, providerId: string): Promise<unknown> {
    return this.call(
      'delete',
      `/teams/${this.id(teamId)}/sso/providers/${this.id(providerId)}`,
    )
  }

  /** The providers someone can sign in through, from the email they typed. */
  listSignInProviders(email: string): Promise<SSOSignInProviderList> {
    return this.call('get', '/account/sessions/sso/providers', { email })
  }

  /** Redirect URI to register at the identity provider: CE's OAuth2 callback for `sso`. */
  redirectURL(): string {
    return `${this.client.config.endpoint}/account/sessions/oauth2/callback/sso/${this.client.config.project}`
  }

  /**
   * Where the browser goes to sign in through `providerId`. Without `success`,
   * the API lands the person in the provider's organization.
   */
  signInURL(
    providerId: string,
    success: string | undefined,
    failure: string,
  ): string {
    const url = new URL(this.client.config.endpoint + '/account/sessions/sso')
    url.searchParams.set('project', this.client.config.project)
    url.searchParams.set('providerId', providerId)
    if (success) url.searchParams.set('success', success)
    url.searchParams.set('failure', failure)
    return url.toString()
  }

  private id(value: string): string {
    return encodeURIComponent(value)
  }

  private call<T>(
    method: 'get' | 'post' | 'put' | 'patch' | 'delete',
    apiPath: string,
    params: Record<string, unknown> = {},
  ): Promise<T> {
    return this.client.call(
      method,
      new URL(this.client.config.endpoint + apiPath),
      {
        'X-Appwrite-Project': this.client.config.project,
        'content-type': 'application/json',
        accept: 'application/json',
      },
      params,
    )
  }
}
