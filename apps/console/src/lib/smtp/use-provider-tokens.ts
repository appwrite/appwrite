import { useCallback, useRef } from 'react'
import { isProviderTokenExpired } from './quick-setup'
import {
  QuickSetupReauthorizeRequiredError,
  resolveProviderAccessToken,
  type ProviderAccessToken,
} from './quick-setup-oauth'
import type { AvailableSmtpQuickSetupProvider } from './providers'

/**
 * Holds provider access tokens for the current page and runs API calls with
 * them. Tokens live in a ref only: nothing is persisted, and a reload reads
 * them back from the account's identities.
 *
 * Shared by every surface that talks to an email provider on the user's behalf
 * (project SMTP settings, the messaging provider wizard), so the reconnect
 * rules stay in one place.
 */
export function useProviderTokens() {
  const tokensRef = useRef(new Map<string, ProviderAccessToken>())

  /**
   * Runs a provider API call with a live token, reading one off the account's
   * identity when nothing usable is cached. There is no refresh path: a token
   * the provider rejects needs a new authorization just like a missing or
   * expired one, so both raise {@link QuickSetupReauthorizeRequiredError} for
   * the caller to offer a reconnect.
   */
  const callWith = useCallback(
    async <T>(
      provider: AvailableSmtpQuickSetupProvider,
      run: (accessToken: string) => Promise<T>,
    ): Promise<T> => {
      let current = tokensRef.current.get(provider.id)
      if (!current || isProviderTokenExpired(current.expiry)) {
        const resolved = await resolveProviderAccessToken(provider.id)
        if (!resolved) throw new QuickSetupReauthorizeRequiredError()
        current = resolved
        tokensRef.current.set(provider.id, current)
      }
      try {
        return await run(current.token)
      } catch (error) {
        if (!provider.api.isUnauthorizedError(error)) throw error
        tokensRef.current.delete(provider.id)
        throw new QuickSetupReauthorizeRequiredError(undefined, {
          cause: error,
        })
      }
    },
    [],
  )

  /** Caches a token for the provider. False means a new authorization is due. */
  const prime = useCallback(
    async (provider: AvailableSmtpQuickSetupProvider) => {
      const token = await resolveProviderAccessToken(provider.id)
      if (!token) return false
      tokensRef.current.set(provider.id, token)
      return true
    },
    [],
  )

  /** Drops a cached token, e.g. after the identity behind it was deleted. */
  const forget = useCallback((providerId: string) => {
    tokensRef.current.delete(providerId)
  }, [])

  return { callWith, prime, forget }
}
