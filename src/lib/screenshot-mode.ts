/**
 * Screenshot mode - demo-friendly overlays for marketing captures.
 * Toggle by typing "smile" (see ScreenshotModeProvider).
 * Replaces the signed-in user's name, email, and avatar, organization names,
 * hides the DEV construction stripe, and hides Appwrite Cloud status alerts.
 */

import type { Models } from '@appwrite.io/console'
import { getRuntimeConfig } from '@/lib/runtime-config'

export const SCREENSHOT_MODE_OPEN_KEY = 'screenshot:modeOpen'
export const SCREENSHOT_MODE_CHANGE_EVENT = 'screenshotModeChange'

/** Case-insensitive key sequence that toggles screenshot mode. */
export const SCREENSHOT_MODE_TOGGLE_SEQUENCE = 'smile'

export const SCREENSHOT_MODE_USER_NAME = "Walter O'Brien"
export const SCREENSHOT_MODE_USER_EMAIL = 'walter@appwrite.io'
export const SCREENSHOT_MODE_USER_AVATAR_URL =
  '/images/community/avatars/walter.avif'
export const SCREENSHOT_MODE_ORG_NAME = 'ACME Corps'

export function readScreenshotModeOpen(): boolean {
  const defaultOpen = getRuntimeConfig().screenshotMode === 'true'
  if (typeof window === 'undefined') return defaultOpen
  try {
    const stored = localStorage.getItem(SCREENSHOT_MODE_OPEN_KEY)
    if (stored === 'true' || stored === 'false') return stored === 'true'
    return defaultOpen
  } catch {
    return defaultOpen
  }
}

export function writeScreenshotModeOpen(open: boolean): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.setItem(SCREENSHOT_MODE_OPEN_KEY, open ? 'true' : 'false')
  } catch {
    // localStorage unavailable
  }
  try {
    window.dispatchEvent(
      new CustomEvent(SCREENSHOT_MODE_CHANGE_EVENT, { detail: { open } }),
    )
  } catch {
    // CustomEvent unavailable
  }
}

/** Sync read for non-React fetch paths. */
export function isScreenshotModeActive(): boolean {
  return readScreenshotModeOpen()
}

export function subscribeScreenshotMode(
  listener: (open: boolean) => void,
): () => void {
  if (typeof window === 'undefined') return () => {}

  const onCustom = (event: Event) => {
    const detail = (event as CustomEvent<{ open: boolean }>).detail
    listener(detail?.open ?? readScreenshotModeOpen())
  }
  const onStorage = (event: StorageEvent) => {
    if (event.key === SCREENSHOT_MODE_OPEN_KEY || event.key === null) {
      listener(readScreenshotModeOpen())
    }
  }

  window.addEventListener(SCREENSHOT_MODE_CHANGE_EVENT, onCustom)
  window.addEventListener('storage', onStorage)
  return () => {
    window.removeEventListener(SCREENSHOT_MODE_CHANGE_EVENT, onCustom)
    window.removeEventListener('storage', onStorage)
  }
}

export function resolveScreenshotModeUserPhotoSrc(options: {
  userId?: string
  useCurrentUser?: boolean
  isCurrentUser?: boolean
  currentUserId?: string | null
}): string | null {
  if (!isScreenshotModeActive()) return null

  const trimmedUserId = options.userId?.trim() || ''
  const currentUserId = options.currentUserId?.trim() || ''
  const isCurrentUser =
    options.isCurrentUser === true ||
    options.useCurrentUser === true ||
    (trimmedUserId.length > 0 &&
      currentUserId.length > 0 &&
      trimmedUserId === currentUserId)

  if (!isCurrentUser) return null
  return SCREENSHOT_MODE_USER_AVATAR_URL
}

export function applyScreenshotModeAccount<
  T extends Models.User | null | undefined,
>(account: T): T {
  if (!account || !isScreenshotModeActive()) return account
  return {
    ...account,
    name: SCREENSHOT_MODE_USER_NAME,
    email: SCREENSHOT_MODE_USER_EMAIL,
  }
}

export function applyScreenshotModeOrganizationName<
  T extends { name: string } | null | undefined,
>(org: T): T {
  if (!org || !isScreenshotModeActive()) return org
  return { ...org, name: SCREENSHOT_MODE_ORG_NAME }
}

export function applyScreenshotModeOrganizationList<
  T extends { teams?: Array<{ name: string }> | null },
>(response: T): T {
  if (!response?.teams || !isScreenshotModeActive()) return response
  return {
    ...response,
    teams: response.teams.map((team) =>
      applyScreenshotModeOrganizationName(team),
    ),
  }
}
