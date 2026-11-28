/**
 * Single sign-on URLs a client builds from its own configuration. The routes
 * themselves are the Console SDK's `teams.*SSODomain`, `teams.*SSOProvider`,
 * `teams.updateSSORequired` and `account.listSSOProviders`.
 */

import type { Client } from '@appwrite.io/console'

import { ssoSignInURL } from '@/lib/sso-shared-url'

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

/**
 * Redirect URI to register at the identity provider: the single sign-on
 * callback of the project `client` is for (the Console's for `console`).
 */
export function ssoRedirectURL(client: Client): string {
  return ssoCallbackURL(client.config.endpoint, client.config.project)
}

/**
 * Where the browser goes to sign in through `providerId` on `client`'s
 * project. Without `success`, the API lands the person in the provider's
 * organization.
 */
export function ssoProviderSignInURL(
  client: Client,
  providerId: string,
  success: string | undefined,
  failure: string,
): string {
  return ssoSignInURL(
    client.config.endpoint,
    client.config.project,
    providerId,
    success,
    failure,
  )
}
