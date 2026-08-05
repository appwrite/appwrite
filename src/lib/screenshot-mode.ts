/**
 * Screenshot mode - demo-friendly overlays for marketing captures.
 * Toggle by typing "smile" (see ScreenshotModeProvider).
 */

import type { Models } from '@appwrite.io/console'

export const SCREENSHOT_MODE_OPEN_KEY = 'screenshot:modeOpen'
export const SCREENSHOT_MODE_CHANGE_EVENT = 'screenshotModeChange'

/** Case-insensitive key sequence that toggles screenshot mode. */
export const SCREENSHOT_MODE_TOGGLE_SEQUENCE = 'smile'

export const SCREENSHOT_MODE_USER_NAME = "Walter O'Brien"
export const SCREENSHOT_MODE_ORG_NAME = 'ACME Corps'

export function readScreenshotModeOpen(): boolean {
  if (typeof window === 'undefined') return false
  try {
    return localStorage.getItem(SCREENSHOT_MODE_OPEN_KEY) === 'true'
  } catch {
    return false
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

export function applyScreenshotModeAccount<
  T extends Models.User | null | undefined,
>(account: T): T {
  if (!account || !isScreenshotModeActive()) return account
  return { ...account, name: SCREENSHOT_MODE_USER_NAME }
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
