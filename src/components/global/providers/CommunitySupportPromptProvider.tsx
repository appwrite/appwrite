'use client'

import { useEffect, useRef, useState } from 'react'
import { useLocation } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { CommunitySupportWizard } from '@/components/global/shared/CommunitySupportWizard'
import { useCommunitySupportPrompt } from '@/lib/react-query/hooks/community-support-prompt'
import type { ConsoleAccountCache } from '@/lib/react-query/hooks/auth'
import { isConsoleImpersonationActive } from '@/lib/console-impersonation'
import { setDebugOverride, useDebugOverrides } from '@/lib/debug-overrides'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'
import { useAnalytics } from '@/hooks/use-analytics'
import type { CommunitySupportActionId } from '@/lib/community/support-prompt'

const SURFACE = 'community_support_wizard'

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
  return false
}

/**
 * Shows the skippable community-support fullscreen wizard after enough unique
 * usage days, and again every ~2 months until the user picks a support path.
 * Impression count and timing live in account prefs (`console.communitySupport`).
 */
export function CommunitySupportPromptProvider() {
  const { account, isAuthenticated } = useAuth()
  const location = useLocation()
  const { track } = useAnalytics()
  const { previewCommunitySupportWizard } = useDebugOverrides()
  const suppressed = shouldSuppressOnPath(location.pathname)
  const accountCache = isAuthenticated
    ? (account as ConsoleAccountCache | undefined)
    : undefined
  const accountId =
    accountCache && typeof accountCache === 'object' && '$id' in accountCache
      ? accountCache.$id
      : undefined
  const isImpersonating = isConsoleImpersonationActive(
    account as { impersonatorUserId?: string } | null | undefined,
  )
  const { shouldShow, recordShown, skip, takeAction, state } =
    useCommunitySupportPrompt(accountCache, {
      // Pref writes are skipped while impersonating; avoid optimistic cache
      // churn that can cascade into max-update-depth loops on /account.
      trackActiveDay: isAuthenticated && !suppressed && !isImpersonating,
    })
  const [debugOpen, setDebugOpen] = useState(false)
  /** Keeps the wizard mounted for the current impression after prefs stamp lastShownAt. */
  const [impressionOpen, setImpressionOpen] = useState(false)
  const recordingShowRef = useRef(false)
  /** Once the user closes/skips, never reopen this session (prefs races used to). */
  const dismissedRef = useRef(false)
  const trackedDebugOpenRef = useRef(false)

  useEffect(() => {
    dismissedRef.current = false
    recordingShowRef.current = false
    setImpressionOpen(false)
  }, [accountId])

  useEffect(() => {
    if (previewCommunitySupportWizard) {
      setDebugOpen(true)
      setImpressionOpen(false)
    } else {
      setDebugOpen(false)
    }
  }, [previewCommunitySupportWizard])

  useEffect(() => {
    if (
      dismissedRef.current ||
      debugOpen ||
      !isAuthenticated ||
      suppressed ||
      isImpersonating ||
      !shouldShow
    ) {
      return
    }
    if (recordingShowRef.current) return
    recordingShowRef.current = true
    setImpressionOpen(true)
    recordShown()
    track('Wizard Opened', {
      surface: SURFACE,
      preview: false,
      shownCount: state.shownCount + 1,
    })
  }, [
    debugOpen,
    isAuthenticated,
    isImpersonating,
    recordShown,
    shouldShow,
    state.shownCount,
    suppressed,
    track,
  ])

  useEffect(() => {
    if (!debugOpen || suppressed) {
      trackedDebugOpenRef.current = false
      return
    }
    if (trackedDebugOpenRef.current) return
    trackedDebugOpenRef.current = true
    track('Wizard Opened', {
      surface: SURFACE,
      preview: true,
    })
  }, [debugOpen, suppressed, track])

  const open =
    !suppressed &&
    !isImpersonating &&
    (debugOpen || (isAuthenticated && impressionOpen))

  if (!open) return null

  const trackOption = (option: CommunitySupportActionId | 'skip') => {
    track('Wizard Option Selected', {
      surface: SURFACE,
      option,
      preview: debugOpen,
      ...(debugOpen ? {} : { shownCount: state.shownCount }),
    })
  }

  const dismissImpression = () => {
    dismissedRef.current = true
    recordingShowRef.current = true
    setImpressionOpen(false)
  }

  return (
    <CommunitySupportWizard
      onSkip={() => {
        trackOption('skip')
        if (debugOpen) {
          setDebugOpen(false)
          setDebugOverride('previewCommunitySupportWizard', false)
          return
        }
        dismissImpression()
        skip()
      }}
      onAction={(actionId) => {
        trackOption(actionId)
        if (debugOpen) {
          setDebugOpen(false)
          setDebugOverride('previewCommunitySupportWizard', false)
          return
        }
        dismissImpression()
        takeAction(actionId)
      }}
    />
  )
}
