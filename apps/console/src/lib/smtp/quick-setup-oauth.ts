/**
 * Appwrite side of the SMTP quick setup: authorize an email provider through
 * the console OAuth2 token flow and read the provider access token it leaves
 * behind.
 *
 * Token lifecycle (browser only):
 * 1. `startProviderAuthorization` redirects through `account.createOAuth2Token`
 *    and back to the page it was called from. That flow keeps the caller's
 *    console session (appwrite/appwrite#13602), so the provider can return
 *    straight to a guarded console route.
 * 2. Appwrite links the provider account to the signed-in user and writes a
 *    fresh provider access token onto that identity.
 * 3. `resolveProviderAccessToken` reads it back. Providers issue short-lived
 *    tokens (Resend 15 minutes), which covers the setup that follows; once one
 *    expires the user authorizes again.
 *
 * Refreshing is deliberately not wired up. Appwrite can only refresh a
 * *session's* provider token (`account.updateSession`), so holding on to a
 * refresh token would mean minting a provider-flavored console session on every
 * connect. Re-authorizing costs one silent redirect instead, and the account's
 * session list keeps describing console sessions.
 *
 * Nothing here is provider-specific beyond the id and scopes passed in, so the
 * same flow serves every entry in `providers.ts`.
 */

import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { buildQuickSetupOAuthUrls, isProviderTokenExpired } from './quick-setup'
import type { AvailableSmtpQuickSetupProvider } from './providers'

export interface ProviderAccessToken {
  token: string
  /** ISO 8601 expiry reported by Appwrite. */
  expiry: string
}

/** No usable provider token for this account; only a new OAuth2 flow can help. */
export class QuickSetupReauthorizeRequiredError extends Error {
  constructor(
    message = 'Provider authorization is required',
    options?: ErrorOptions,
  ) {
    super(message, options)
    this.name = 'QuickSetupReauthorizeRequiredError'
  }
}

/**
 * The identity Appwrite linked for this provider. Its presence is what
 * "this account is connected" means, so deleting it disconnects the provider.
 */
export async function findProviderIdentity(
  providerId: string,
): Promise<Models.Identity | null> {
  const response = await sdk.forConsole.account.listIdentities({
    queries: [Query.equal('provider', [providerId]), Query.limit(1)],
    total: false,
  })
  return response.identities?.[0] ?? null
}

/**
 * Provider access token for this account, or `null` when only a new
 * authorization can help: either the account is not connected, or the token
 * the last round trip left on the identity has expired.
 */
export async function resolveProviderAccessToken(
  providerId: string,
): Promise<ProviderAccessToken | null> {
  const identity = await findProviderIdentity(providerId)
  if (!identity?.providerAccessToken) return null
  if (isProviderTokenExpired(identity.providerAccessTokenExpiry)) return null
  return {
    token: identity.providerAccessToken,
    expiry: identity.providerAccessTokenExpiry,
  }
}

/**
 * Leave for the provider's consent screen and come back to the current page
 * with the outcome in its search params. The console session survives the round
 * trip, so the page is reached as the signed-in user; only its in-memory state
 * is lost to the navigation.
 *
 * The return URL keeps the path and drops any existing search params, so a
 * stale outcome from an earlier attempt cannot ride along.
 */
export function startProviderAuthorization(
  provider: AvailableSmtpQuickSetupProvider,
): void {
  if (typeof window === 'undefined') {
    throw new Error('Provider authorization requires a browser')
  }
  const { success, failure } = buildQuickSetupOAuthUrls(
    `${window.location.origin}${window.location.pathname}`,
    provider.id,
  )
  const result = sdk.forConsole.account.createOAuth2Token({
    provider: provider.oauth.provider,
    success,
    failure,
    scopes: provider.oauth.scopes,
  })
  // In browsers the SDK navigates itself; outside of one it returns the URL.
  if (typeof result === 'string') {
    window.location.assign(result)
  }
}
