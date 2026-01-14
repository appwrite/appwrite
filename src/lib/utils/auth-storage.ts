/**
 * Utility functions for storing and retrieving last login method
 */

const LAST_LOGIN_METHOD_KEY = 'last-login-method'

export type LoginMethod = 'github' | 'email'

/**
 * Get the last login method from localStorage
 */
export function getLastLoginMethod(): LoginMethod | null {
  if (typeof window === 'undefined') return null
  try {
    const stored = localStorage.getItem(LAST_LOGIN_METHOD_KEY)
    if (stored === 'github' || stored === 'email') {
      return stored
    }
  } catch {
    // ignore storage errors
  }
  return null
}

/**
 * Store the last login method in localStorage
 */
export function setLastLoginMethod(method: LoginMethod): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.setItem(LAST_LOGIN_METHOD_KEY, method)
  } catch {
    // ignore storage errors
  }
}
