import type { NavigateOptions } from '@tanstack/react-router'
import {
  DEBUG_DEMO_CATALOG,
  DEFAULT_DEBUG_DEMO_ID,
  getDebugDemoById,
  type DebugDemoEntry,
} from '@/lib/debug-demos/catalog'
import {
  isOAuth2PreviewScreen,
  oauth2PreviewDemoIdForScreen,
} from '@/lib/debug-demos/oauth2-preview-screens'
import {
  activateDebugDemoSession,
  notifyDebugDemoSessionChange,
  pushDebugDemoSession,
  readDebugDemoSession,
  setDebugDemoSessionIndex,
  type DebugDemoSession,
} from '@/lib/debug-demos/session'
import { setDebugOverride } from '@/lib/debug-overrides'

type DemoNavigate = (options: NavigateOptions) => void

const PATHNAME_ONLY_DEMO_IDS: Record<string, string> = {
  '/debug/authorize-contributor-preview': 'git-contributor-authorization',
  '/debug/education-join-preview': 'auth-education-join',
  '/debug/education-plan-preview': 'education-plan-curtain',
  '/debug/join-invite-preview': 'auth-join-invite',
  '/debug/verify-email-preview': 'auth-verify-email',
  '/debug/oauth2-relay-preview': 'oauth2-relay',
  '/debug/mfa-preview': 'auth-mfa',
  '/debug/reset-preview': 'auth-reset',
  '/debug/magic-url-preview': 'auth-magic-url',
  '/debug/sites-auth-preview': 'sites-auth-preview',
  '/debug/org-setup-preview': 'console-org-setup',
}

function applyDemoSideEffects(demo: DebugDemoEntry) {
  setDebugOverride('showFullscreenLoader', false)
  setDebugOverride('previewCommunitySupportWizard', false)
  if (demo.type === 'fullscreen-loader') {
    setDebugOverride('showFullscreenLoader', demo.enabled)
    return
  }
  if (demo.type === 'community-wizard') {
    setDebugOverride('previewCommunitySupportWizard', true)
  }
}

function locationMatchesDemoHref(
  pathname: string,
  searchParams: URLSearchParams,
  href: string,
): boolean {
  const ref = new URL(href, 'http://local.invalid')
  if (ref.pathname !== pathname) return false
  for (const [key, value] of ref.searchParams) {
    if (searchParams.get(key) !== value) return false
  }
  return true
}

export function resolveDemoIdFromLocation(
  pathname: string,
  searchParams: URLSearchParams,
): string | undefined {
  if (pathname === '/debug/oauth2-preview') {
    const screen = searchParams.get('screen') ?? 'consent'
    if (isOAuth2PreviewScreen(screen)) {
      return oauth2PreviewDemoIdForScreen(screen)
    }
    return 'oauth2-consent'
  }

  const pathnameOnlyId = PATHNAME_ONLY_DEMO_IDS[pathname]
  if (pathnameOnlyId) {
    return pathnameOnlyId
  }

  for (const entry of DEBUG_DEMO_CATALOG) {
    if (
      entry.type === 'route' &&
      locationMatchesDemoHref(pathname, searchParams, entry.href)
    ) {
      return entry.id
    }
  }

  if (pathname === '/debug/community-share-examples') {
    return 'product-community-share'
  }

  return undefined
}

export function navigateToDemoTarget(
  demo: DebugDemoEntry,
  navigate: DemoNavigate,
  replace?: boolean,
) {
  applyDemoSideEffects(demo)

  if (demo.type === 'route') {
    navigate({ href: demo.href, replace })
    return
  }
  if (demo.type === 'community-share-examples') {
    navigate({ to: '/debug/community-share-examples', replace })
    return
  }
  if (demo.type === 'fullscreen-loader' || demo.type === 'community-wizard') {
    navigate({ to: '/', replace })
  }
}

export function openDebugDemo(
  demoId: string,
  navigate: DemoNavigate,
  options?: {
    replace?: boolean
    freshSession?: boolean
    updateSession?: boolean
  },
) {
  const demo =
    getDebugDemoById(demoId) ?? getDebugDemoById(DEFAULT_DEBUG_DEMO_ID)
  if (!demo) return

  const updateSession = options?.updateSession !== false
  if (updateSession) {
    if (options?.freshSession) {
      activateDebugDemoSession(demo.id)
    } else {
      pushDebugDemoSession(demo.id)
    }
    notifyDebugDemoSessionChange()
  }

  navigateToDemoTarget(demo, navigate, options?.replace)
}

export function goToDemoSessionIndex(
  index: number,
  navigate: DemoNavigate,
): DebugDemoSession | null {
  const current = readDebugDemoSession()
  const clamped = Math.min(
    Math.max(0, index),
    Math.max(0, current.history.length - 1),
  )
  const demoId = current.history[clamped]
  const demo = demoId ? getDebugDemoById(demoId) : undefined
  if (!demo) return null

  const session = setDebugDemoSessionIndex(clamped)
  notifyDebugDemoSessionChange()
  navigateToDemoTarget(demo, navigate, true)
  return session
}
