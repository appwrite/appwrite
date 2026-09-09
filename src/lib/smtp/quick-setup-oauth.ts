/**
 * Appwrite side of the SMTP quick setup: claim the provider identity through
 * the console OAuth2 token flow and keep a usable provider access token.
 *
 * Token lifecycle (browser only):
 * 1. `startProviderAuthorization` redirects through `account.createOAuth2Token`.
 * 2. Back on the SMTP tab, `claimProviderIdentity` calls `account.createSession`
 *    with the returned `userId` + `secret`. The new session carries the provider
 *    access and refresh tokens copied from the identity.
 * 3. `resolveProviderAccessToken` reuses that session (refreshing through
 *    `account.updateSession` when the short-lived access token expired) and
 *    falls back to the stored identity. When neither works, restart at 1.
 *
 * Nothing here is provider-specific beyond the id and scopes passed in, so the
 * same flow serves every entry in `providers.ts`.
 */

import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  buildQuickSetupOAuthUrls,
  isProviderTokenExpired,
  rememberQuickSetupPending,
} from './quick-setup'
import type { AvailableSmtpQuickSetupProvider } from './providers'

export type ProviderCredentialSource = 'session' | 'identity'

export interface ProviderAccessToken {
  token: string
  /** ISO 8601 expiry reported by Appwrite. */
  expiry: string
  source: ProviderCredentialSource
}

/** The current console session cannot produce a fresh token; run the OAuth2 flow again. */
export class QuickSetupReauthorizeRequiredError extends Error {
  constructor(
    message = 'Provider authorization is required',
    options?: ErrorOptions,
  ) {
    super(message, options)
    this.name = 'QuickSetupReauthorizeRequiredError'
  }
}

type ProviderCredentials = Pick<
  Models.Session,
  'provider' | 'providerAccessToken' | 'providerAccessTokenExpiry'
>

export function tokenFromSession(
  session: ProviderCredentials,
  providerId: string,
): ProviderAccessToken | null {
  if (session.provider !== providerId || !session.providerAccessToken) {
    return null
  }
  return {
    token: session.providerAccessToken,
    expiry: session.providerAccessTokenExpiry,
    source: 'session',
  }
}

export function sessionCanRefreshProvider(
  session: Pick<Models.Session, 'provider' | 'providerRefreshToken'>,
  providerId: string,
): boolean {
  return (
    session.provider === providerId && Boolean(session.providerRefreshToken)
  )
}

async function getCurrentSession(): Promise<Models.Session> {
  return await sdk.forConsole.account.getSession({ sessionId: 'current' })
}

/**
 * Appwrite refreshes the provider tokens of the *session* through
 * `updateSession`, so only a session created from that provider's token flow
 * can be refreshed. Identities keep the original (now rotated) refresh token
 * and have no refresh endpoint.
 */
async function refreshFromSession(
  session: Models.Session,
  providerId: string,
): Promise<ProviderAccessToken> {
  if (!sessionCanRefreshProvider(session, providerId)) {
    throw new QuickSetupReauthorizeRequiredError()
  }

  let refreshed: Models.Session
  try {
    refreshed = await sdk.forConsole.account.updateSession({
      sessionId: 'current',
    })
  } catch (error) {
    throw new QuickSetupReauthorizeRequiredError(undefined, { cause: error })
  }

  const token = tokenFromSession(refreshed, providerId)
  if (!token || isProviderTokenExpired(token.expiry)) {
    throw new QuickSetupReauthorizeRequiredError()
  }
  return token
}

/**
 * Force a new access token for the current session. Throws
 * {@link QuickSetupReauthorizeRequiredError} when the session is not for this
 * provider or the provider rejected the refresh token.
 */
export async function refreshProviderAccessToken(
  providerId: string,
): Promise<ProviderAccessToken> {
  return await refreshFromSession(await getCurrentSession(), providerId)
}

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
 * Best available provider access token without user interaction, or `null`
 * when a new authorization is needed.
 */
export async function resolveProviderAccessToken(
  providerId: string,
): Promise<ProviderAccessToken | null> {
  const session = await getCurrentSession()

  const fromSession = tokenFromSession(session, providerId)
  if (fromSession && !isProviderTokenExpired(fromSession.expiry)) {
    return fromSession
  }

  if (sessionCanRefreshProvider(session, providerId)) {
    try {
      return await refreshFromSession(session, providerId)
    } catch (error) {
      if (!(error instanceof QuickSetupReauthorizeRequiredError)) throw error
    }
  }

  // Identity tokens are only fresh right after a claim (short lifetime), but
  // they cover the case where the callback session was replaced since.
  const identity = await findProviderIdentity(providerId)
  if (
    identity?.providerAccessToken &&
    !isProviderTokenExpired(identity.providerAccessTokenExpiry)
  ) {
    return {
      token: identity.providerAccessToken,
      expiry: identity.providerAccessTokenExpiry,
      source: 'identity',
    }
  }

  return null
}

/**
 * Leave for the provider's consent screen.
 *
 * Appwrite deletes the current session the moment this flow starts, so the
 * redirect targets `/auth/smtp/callback`, which restores the session from the
 * returned `userId` + `secret` before sending the user back to the SMTP tab.
 * The pending record lets that route verify the callback belongs to this
 * browser and this account.
 */
export function startProviderAuthorization(
  provider: AvailableSmtpQuickSetupProvider,
  projectId: string,
  accountId: string,
): void {
  if (typeof window === 'undefined') {
    throw new Error('Provider authorization requires a browser')
  }
  rememberQuickSetupPending({
    providerId: provider.id,
    projectId,
    accountId,
  })
  const { success, failure } = buildQuickSetupOAuthUrls(
    window.location.origin,
    projectId,
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

/**
 * Exchange the one-time OAuth2 token for a session, restoring the console
 * login that Appwrite dropped when the flow started. Callers must first check
 * the claim against the pending record (`isExpectedQuickSetupClaim`).
 */
export async function claimProviderIdentity(params: {
  userId: string
  secret: string
}): Promise<Models.Session> {
  return await sdk.forConsole.account.createSession({
    userId: params.userId,
    secret: params.secret,
  })
}
