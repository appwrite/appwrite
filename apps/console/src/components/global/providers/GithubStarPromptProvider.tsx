'use client'

import { lazy, Suspense, useEffect, useRef, useState } from 'react'
import { useLocation } from '@tanstack/react-router'
import {
  isOptionalAuthPage,
  useAuth,
} from '@/components/global/auth/RequireAuth'
import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import { useAnalytics } from '@/hooks/use-analytics'
import { isConsoleImpersonationActive } from '@/lib/console-impersonation'
import { GITHUB_STAR_WIZARD_BANNER_ID } from '@/lib/console-banners/catalog'
import { useDebugConsoleBannerPreviews } from '@/lib/console-banners/debug-preview'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'
import type { ConsoleAccountCache } from '@/lib/react-query/hooks/auth'
import { useGithubStarPrompt } from '@/lib/react-query/hooks/github-star-prompt'

const SURFACE = 'github_star_wizard'

const GithubStarWizardLazy = lazy(() =>
  import('@/components/global/shared/github-star-wizard/Content').then(
    (module) => ({ default: module.GithubStarWizardContent }),
  ),
)

function isAuthPage(pathname: string): boolean {
  return (
    pathname === '/sign-in' ||
    pathname === '/sign-up' ||
    pathname === '/recovery' ||
    pathname === '/reset' ||
    pathname === '/join' ||
    pathname === '/mfa' ||
    pathname === '/verify-email' ||
    pathname === '/auth/magic-url'
  )
}

function shouldSuppressOnPath(pathname: string): boolean {
  if (isAuthPage(pathname)) return true
  if (isOptionalAuthPage(pathname)) return true
  if (isMarketingPagePath(pathname)) return true
  if (pathname.startsWith('/debug/')) return true
  if (pathname.startsWith('/git/')) return true
  return false
}

/**
 * Shows the one-time GitHub star fullscreen wizard after enough distinct
 * console visit days land inside the rolling window (banner
 * `github-star-wizard-2026`). Any dismissal is permanent: the id is stored in
 * `console.dismissedBanners` and the wizard never shows again.
 */
export function GithubStarPromptProvider() {
  const { account, isAuthenticated } = useAuth()
  const location = useLocation()
  const { track } = useAnalytics()
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled, setPreviewEnabled } =
    useDebugConsoleBannerPreviews()
  const preview = isPreviewEnabled(GITHUB_STAR_WIZARD_BANNER_ID)
  const suppressed = shouldSuppressOnPath(location.pathname)
  const accountCache = isAuthenticated
    ? (account as ConsoleAccountCache | undefined)
    : undefined
  const accountId =
    accountCache && typeof accountCache === 'object' && '$id' in accountCache
      ? (accountCache.$id as string)
      : undefined
  const isImpersonating = isConsoleImpersonationActive(
    account as { impersonatorUserId?: string } | null | undefined,
  )
  const { shouldShow, dismiss } = useGithubStarPrompt(accountCache, {
    // Pref writes are skipped while impersonating to avoid polluting the
    // impersonated user's visit history.
    trackVisits: isAuthenticated && !suppressed && !isImpersonating,
  })
  /** Once the user dismisses, never reopen this session (prefs write races). */
  const [closed, setClosed] = useState(false)
  const trackedOpenRef = useRef(false)

  useEffect(() => {
    setClosed(false)
    trackedOpenRef.current = false
  }, [accountId])

  const open =
    !isScreenshotModeActive &&
    !closed &&
    (preview ||
      (isAuthenticated && !suppressed && !isImpersonating && shouldShow))

  useEffect(() => {
    if (!open) return
    if (trackedOpenRef.current) return
    trackedOpenRef.current = true
    // `surface` is a protected session dimension (route-derived, spread last
    // in trackEvent), so the wizard segments on its own `prompt` prop.
    track('Wizard Opened', { prompt: SURFACE, preview })
  }, [open, preview, track])

  if (!open) return null

  const close = (option: 'star' | 'skip') => {
    track('Wizard Option Selected', { prompt: SURFACE, option, preview })
    if (preview) {
      setPreviewEnabled(GITHUB_STAR_WIZARD_BANNER_ID, false)
      return
    }
    setClosed(true)
    dismiss()
  }

  return (
    <Suspense fallback={null}>
      <GithubStarWizardLazy
        onStar={() => close('star')}
        onSkip={() => close('skip')}
      />
    </Suspense>
  )
}
