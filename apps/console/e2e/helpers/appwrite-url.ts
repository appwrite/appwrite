import { env } from '../config/env'

/**
 * Path of an Appwrite API URL relative to `VITE_APPWRITE_ENDPOINT`
 * (e.g. `https://cloud.appwrite.io/v1/account` → `/account`).
 * URLs on other origins come back as their plain pathname.
 */
export function appwriteApiPath(url: string): string {
  const base = new URL(env.VITE_APPWRITE_ENDPOINT)
  const parsed = new URL(url)
  if (parsed.origin !== base.origin) return parsed.pathname
  const basePath = base.pathname.replace(/\/$/, '')
  return parsed.pathname.startsWith(basePath)
    ? parsed.pathname.slice(basePath.length)
    : parsed.pathname
}
