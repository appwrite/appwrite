import { useCallback, useRef } from 'react'
import { isProviderTokenExpired } from './quick-setup'
import {
  QuickSetupReauthorizeRequiredError,
  refreshProviderAccessToken,
  resolveProviderAccessToken,
  type ProviderAccessToken,
} from './quick-setup-oauth'
import type { AvailableSmtpQuickSetupProvider } from './providers'

/**
 * Holds provider access tokens for the current page and runs API calls with
 * them. Tokens live in a ref only: nothing is persisted, and a reload starts
 * over from the account's sessions.
 *
 * Shared by every surface that talks to an email provider on the user's
 * behalf (project SMTP settings, the messaging provider wizard), so the
 * refresh and retry rules stay in one place.
 */
export function useProviderTokens() {
  const tokensRef = useRef(new Map<string, ProviderAccessToken>())

  /**
   * Runs a provider API call with a live token. Without a usable cached one it
   * resolves a fresh token the cheapest way available, which includes the
   * identity's own token right after a claim. A 401 forces one session refresh
   * and a retry. When nothing can produce a token this throws
   * `QuickSetupReauthorizeRequiredError` so the caller can offer to reconnect.
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
        const refreshed = await refreshProviderAccessToken(provider.id)
        tokensRef.current.set(provider.id, refreshed)
        return await run(refreshed.token)
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
