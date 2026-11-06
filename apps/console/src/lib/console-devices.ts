import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

/**
 * Console account switching (operator-only): a `device` is the account switching
 * container on the console project. It holds several console sessions on one
 * browser and tracks the active one, so an operator can stay signed in to several
 * console accounts and switch between them. The device is carried in the
 * `a_device_console` cookie; switching rotates the chosen session's secret into
 * the `a_session_console` cookie, so it only takes effect after a hard reload.
 *
 * See appwrite/appwrite account switching (devices) endpoints.
 */

const LABELS_KEY = 'console.device.accountLabels'

/** Set on `/sign-in?addAccount=1` so the sign-in loader renders the form for an operator who is already signed in. */
export const ADD_ACCOUNT_SEARCH_PARAM = 'addAccount'

export type ConsoleAccountLabel = {
  email: string
  name: string
}

/**
 * Each session carries the user's name (`userName`), but not the email, and an operator
 * cannot read other console users through the account API. So each account's email is
 * cached locally the last time it was the active account on this browser; the switcher
 * shows the name alone for an account it has not seen active yet.
 */
function readLabels(): Record<string, ConsoleAccountLabel> {
  if (typeof window === 'undefined') return {}
  try {
    const raw = window.localStorage.getItem(LABELS_KEY)
    const parsed = raw ? (JSON.parse(raw) as unknown) : null
    return parsed && typeof parsed === 'object'
      ? (parsed as Record<string, ConsoleAccountLabel>)
      : {}
  } catch {
    return {}
  }
}

export function rememberConsoleAccountLabel(
  account: Pick<Models.User, '$id' | 'email' | 'name'> | null | undefined,
): void {
  if (typeof window === 'undefined' || !account?.$id) return
  const labels = readLabels()
  labels[account.$id] = {
    email: account.email ?? '',
    name: account.name ?? '',
  }
  try {
    window.localStorage.setItem(LABELS_KEY, JSON.stringify(labels))
  } catch {
    // Storage full or blocked: labels just fall back to the user id.
  }
}

export function getConsoleAccountLabel(
  userId: string,
): ConsoleAccountLabel | undefined {
  return readLabels()[userId]
}

/**
 * Create the console device, or return the existing one (the endpoint answers 201
 * either way). Safe to call on every open; it only ever writes the cookie once.
 */
export function ensureConsoleDevice(): Promise<Models.Device> {
  return sdk.forConsole.account.createDevice()
}

export function getConsoleDevice(): Promise<Models.Device> {
  return sdk.forConsole.account.getDevice({ deviceId: 'current' })
}

/** Make `sessionId` the active account. The caller must hard reload for the rotated cookie to apply. */
export function switchConsoleDeviceSession(
  sessionId: string,
): Promise<Models.Session> {
  return sdk.forConsole.account.updateDeviceSession({
    deviceId: 'current',
    sessionId,
  })
}

/** Sign out of every account the device holds and clear both cookies. */
export function signOutConsoleDevice(): Promise<unknown> {
  return sdk.forConsole.account.deleteDevice({ deviceId: 'current' })
}
