import type { ZeroProject } from '@/lib/appwrite/zero'

/** Label the server puts on the anonymous console user that owns a Zero project. */
export const ZERO_ACCOUNT_LABEL = 'zero'

const STORAGE_KEY = 'console.zero'

/** What the browser that created a Zero project keeps to show its deadline and claim it. */
export type ZeroSession = {
  zeroId: string
  secret: string
  claimUrl: string
  expiresAt: string
  claimBy: string
  projectId: string
  region: string
}

export function isZeroAccount(
  account: { labels?: string[] } | null | undefined,
): boolean {
  return !!account?.labels?.includes(ZERO_ACCOUNT_LABEL)
}

export function readZeroSession(): ZeroSession | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const value = JSON.parse(raw) as Partial<ZeroSession>
    if (!value.zeroId || !value.secret) return null
    return value as ZeroSession
  } catch {
    return null
  }
}

export function writeZeroSession(zero: ZeroProject): void {
  if (typeof window === 'undefined' || !zero.secret) return
  const session: ZeroSession = {
    zeroId: zero.$id,
    secret: zero.secret,
    claimUrl: zero.claimUrl ?? '',
    expiresAt: zero.expiresAt,
    claimBy: zero.claimBy,
    projectId: zero.projectId,
    region: zero.region,
  }
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(session))
  } catch {
    /* private mode */
  }
}

export function clearZeroSession(): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.removeItem(STORAGE_KEY)
  } catch {
    /* private mode */
  }
}

/** Claim page path for a Zero project; the secret travels in the query. */
export function getZeroClaimPath(zeroId: string, secret: string): string {
  return `/zero/${encodeURIComponent(zeroId)}?secret=${encodeURIComponent(secret)}`
}
