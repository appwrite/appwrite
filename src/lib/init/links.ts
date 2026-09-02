import {
  isInitTicketOgPath,
  isInitTicketSharePath,
} from '@/lib/init/init-surface'
import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { isPreLaunchAllowedPath } from '@/lib/pre-launch'

/** True for off-site URLs; relative paths and hash links stay in-app. */
export function isExternalInitHref(href: string): boolean {
  return /^https?:\/\//i.test(href) || href.startsWith('//')
}

export const INIT_TICKET_SECTION_HASH = '#ticket'

/** Where Init YouTube sessions land when no per-session link is set. */
export const INIT_YOUTUBE_CHANNEL_HREF = 'https://www.youtube.com/@Appwrite'

/**
 * Per-day stream URLs, keyed by Init day. Days left out fall back to the
 * channel, so links can land one at a time as streams get scheduled.
 */
export const INIT_DAY_STREAM_HREFS: Record<number, string | undefined> = {
  1: 'https://www.youtube.com/watch?v=FaEmdOwzQMw',
  2: 'https://www.youtube.com/watch?v=Rq4WNPvqWes',
  3: 'https://www.youtube.com/watch?v=0UHYLAtWBMo',
  4: 'https://www.youtube.com/watch?v=N4bz33uSiko',
  5: 'https://www.youtube.com/watch?v=LYq0qtyQmJk',
}

export type ResolvedInitHref = {
  href: string
  external: boolean
}

const CONSOLE_ONLY_PATH_PREFIXES = [
  '/projects',
  '/organizations',
  '/account',
  '/blocks',
  '/generator',
  '/assistant',
  '/agent',
] as const

function splitHrefHash(href: string): { pathname: string; hash: string } {
  const hashIndex = href.indexOf('#')
  if (hashIndex < 0) return { pathname: href, hash: '' }
  return {
    pathname: href.slice(0, hashIndex),
    hash: href.slice(hashIndex + 1),
  }
}

function appendHrefHash(url: string, hash: string): string {
  return hash ? `${url}#${hash}` : url
}

function normalizePathname(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}

function toLegacyWebsiteUrl(pathname: string, hash: string): string {
  if (pathname === '/' || pathname === '/home') {
    return appendHrefHash(`${MARKETING_SITE_ORIGIN}/`, hash)
  }
  return appendHrefHash(`${MARKETING_SITE_ORIGIN}${pathname}`, hash)
}

function isConsoleOnlyPath(pathname: string): boolean {
  return CONSOLE_ONLY_PATH_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  )
}

/**
 * During pre-launch, keep Init/auth/self links, send public marketing/docs/blog
 * URLs to https://appwrite.io, and hide console-only or unfinished Init routes.
 */
export function resolvePreLaunchPublicHref(
  href: string,
): ResolvedInitHref | null {
  const trimmed = href.trim()
  if (!trimmed) return null

  if (trimmed.startsWith('#')) {
    return { href: trimmed, external: false }
  }

  if (isExternalInitHref(trimmed)) {
    return { href: trimmed, external: true }
  }

  const { pathname, hash } = splitHrefHash(trimmed)
  const normalized = normalizePathname(pathname.split('?')[0] ?? pathname)

  if (normalized === '/init') {
    return { href: trimmed, external: false }
  }

  if (isInitTicketSharePath(normalized) || isInitTicketOgPath(normalized)) {
    return { href: trimmed, external: false }
  }

  if (normalized === '/init' || normalized.startsWith('/init/')) {
    return null
  }

  if (isPreLaunchAllowedPath(normalized)) {
    return { href: trimmed, external: false }
  }

  if (isConsoleOnlyPath(normalized)) {
    return null
  }

  return {
    href: toLegacyWebsiteUrl(normalized, hash),
    external: true,
  }
}

export function resolveInitHref(
  href: string | undefined,
  preLaunch: boolean,
): ResolvedInitHref | null {
  if (!href) return null
  if (!preLaunch) {
    return { href, external: isExternalInitHref(href) }
  }
  return resolvePreLaunchPublicHref(href)
}
