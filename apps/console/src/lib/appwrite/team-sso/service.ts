/**
 * Hand-written client for the Cloud single sign-on endpoints on Teams
 * (`teams.*SSODomain`, `teams.*SSOProvider`, `account.listSSOProviders`) and
 * for enabling the Single sign-on add-ons of a project
 * (`projects.createSSOAddon`) and of an organization
 * (`organizations.createSSOAddon`): they have not shipped in a Console SDK
 * release yet. Delete this folder once the Console SDK carries them.
 */

import type { Client, Models } from '@appwrite.io/console'

import { ssoSignInURL } from '@/lib/sso-shared-url'
import type {
  OidcProviderParams,
  SSODomain,
  SSODomainList,
  SSOProvider,
  SSOProviderList,
  SSOSignInProviderList,
} from './models'

/**
 * The single sign-on callback an identity provider sends people back to. The
 * Console's is `/account/sessions/sso/callback`; a project's carries its ID,
 * `/account/sessions/sso/callback/<projectId>`, as its app's users sign in to
 * that project.
 */
export function ssoCallbackURL(endpoint: string, project: string): string {
  const callback = `${endpoint}/account/sessions/sso/callback`
  return !project || project === 'console'
    ? callback
    : `${callback}/${encodeURIComponent(project)}`
}

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
   * provider. Resolves with the organization. On a project's client the team
   * is one of the project's: its members on those domains keep their account
   * but lose the team's roles until they sign in through single sign-on.
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

  /**
   * Enable the project's Single sign-on add-on (`sso`), for single sign-on in
   * its teams. Like `projects.createPremiumGeoDBAddon`, it resolves with the
   * add-on, or with a payment to authenticate and confirm first. Takes the
   * parameters of the SDK's `projects.createSSOAddon`, to be swapped for it.
   */
  createProjectSSOAddon({
    projectId,
  }: {
    projectId: string
  }): Promise<Models.Addon | Models.PaymentAuthentication> {
    return this.call('post', `/projects/${this.id(projectId)}/addons/sso`)
  }

  /**
   * Enable the organization's Single sign-on add-on (`ssoOrg`), for single
   * sign-on into the Console. Like `organizations.createBaaAddon`, it resolves
   * with the add-on, or with a payment to authenticate and confirm first.
   * Takes the parameters of the SDK's `organizations.createSSOAddon`, to be
   * swapped for it.
   */
  createOrganizationSSOAddon({
    organizationId,
  }: {
    organizationId: string
  }): Promise<Models.Addon | Models.PaymentAuthentication> {
    return this.call(
      'post',
      `/organizations/${this.id(organizationId)}/addons/sso`,
    )
  }

  /**
   * Redirect URI to register at the identity provider: the single sign-on
   * callback of the project this client is for (the Console's for `console`).
   */
  redirectURL(): string {
    return ssoCallbackURL(
      this.client.config.endpoint,
      this.client.config.project,
    )
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
    return ssoSignInURL(
      this.client.config.endpoint,
      this.client.config.project,
      providerId,
      success,
      failure,
    )
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
