import { AppwriteException } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

const AFFILIATE_REF_STORAGE_KEY = 'console.affiliate.ref'

function normalizeReferralCode(code: string): string {
  return code.trim().toLowerCase()
}

export function captureAffiliateReferralCode(code: string | undefined | null) {
  if (typeof window === 'undefined') return
  if (!getActiveProfileFeatures().affiliates) return

  const normalized = typeof code === 'string' ? normalizeReferralCode(code) : ''
  if (!normalized) return

  try {
    window.sessionStorage.setItem(AFFILIATE_REF_STORAGE_KEY, normalized)
  } catch {
    // Ignore storage failures (private mode, quota, etc.)
  }
}

export function peekAffiliateReferralCode(): string | null {
  if (typeof window === 'undefined') return null
  try {
    const value = window.sessionStorage.getItem(AFFILIATE_REF_STORAGE_KEY)
    return value ? normalizeReferralCode(value) : null
  } catch {
    return null
  }
}

function clearAffiliateReferralCode() {
  if (typeof window === 'undefined') return
  try {
    window.sessionStorage.removeItem(AFFILIATE_REF_STORAGE_KEY)
  } catch {
    // Ignore storage failures
  }
}

/**
 * Attribute the current session to a pending affiliate referral code, if any.
 * Safe to call repeatedly; clears the stored code after a successful bind or
 * when the user is already referred / self-referred.
 */
export async function consumePendingAffiliateReferral(): Promise<void> {
  if (typeof window === 'undefined') return
  if (!getActiveProfileFeatures().affiliates) return

  const code = peekAffiliateReferralCode()
  if (!code) return

  try {
    await sdk.forConsole.affiliates.createReferral({ code })
    clearAffiliateReferralCode()
  } catch (error) {
    if (
      error instanceof AppwriteException &&
      (error.type === 'affiliate_already_referred' ||
        error.type === 'affiliate_self_referral' ||
        error.type === 'affiliate_not_found')
    ) {
      clearAffiliateReferralCode()
      return
    }
    // Keep the code for a later retry (e.g. transient network errors).
  }
}

export function buildAffiliateSignupUrl(code: string): string {
  const origin =
    typeof window !== 'undefined' ? window.location.origin : 'https://cloud.appwrite.io'
  const url = new URL('/sign-up', origin)
  url.searchParams.set('ref', normalizeReferralCode(code))
  return url.toString()
}
