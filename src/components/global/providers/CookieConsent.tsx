import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useQuery } from '@tanstack/react-query'
import { CookieConsentBanner } from '@/components/global/shared/CookieConsentBanner'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canTrackAnalytics,
  setCookieConsentState,
} from '@/lib/cookie-consent/consent-state'
import { loadTrackingScriptsAfterConsent } from '@/lib/cookie-consent/load-tracking-scripts'
import { requiresCookieConsentBanner } from '@/lib/cookie-consent/regions'
import {
  readStoredCookieConsent,
  writeStoredCookieConsent,
} from '@/lib/cookie-consent/storage'
import type { CookieConsentPreferences } from '@/lib/cookie-consent/types'
import { localeQueryOptions } from '@/lib/react-query/hooks/locale'

type CookieConsentContextValue = {
  /** Opens the banner so the visitor can change preferences. */
  openPreferences: () => void
  bannerRequired: boolean
  preferencesOpen: boolean
}

const CookieConsentContext = createContext<CookieConsentContextValue | null>(
  null,
)

function applyAnalyticsConsent(analytics: boolean) {
  setCookieConsentState({
    resolved: true,
    bannerRequired: true,
    analyticsGranted: analytics,
  })
  if (analytics) {
    loadTrackingScriptsAfterConsent()
  }
}

function applyNonRegulatedRegion() {
  setCookieConsentState({
    resolved: true,
    bannerRequired: false,
    analyticsGranted: true,
  })
  loadTrackingScriptsAfterConsent()
}

export function CookieConsentProvider({ children }: { children: ReactNode }) {
  const { features } = useConsoleProfile()
  const cookieBannerEnabled = features.cookieBanner

  const {
    data: locale,
    isSuccess: localeReady,
    isError: localeError,
  } = useQuery({
    ...localeQueryOptions(),
    enabled: cookieBannerEnabled,
  })
  const bannerRequired = !cookieBannerEnabled
    ? false
    : localeReady
      ? requiresCookieConsentBanner(locale)
      : localeError
        ? true
        : false

  const [preferencesOpen, setPreferencesOpen] = useState(false)
  const [isReopening, setIsReopening] = useState(false)
  const [showBanner, setShowBanner] = useState(false)
  const [customizeOpen, setCustomizeOpen] = useState(false)
  const [draftAnalytics, setDraftAnalytics] = useState(false)

  useEffect(() => {
    if (!cookieBannerEnabled) {
      setShowBanner(false)
      setPreferencesOpen(false)
      setIsReopening(false)
      setCustomizeOpen(false)
      applyNonRegulatedRegion()
      return
    }

    if (!localeReady && !localeError) return

    if (!bannerRequired) {
      setShowBanner(false)
      setPreferencesOpen(false)
      setIsReopening(false)
      applyNonRegulatedRegion()
      return
    }

    const stored = readStoredCookieConsent()
    if (stored) {
      setShowBanner(false)
      setPreferencesOpen(false)
      setIsReopening(false)
      setDraftAnalytics(stored.analytics)
      applyAnalyticsConsent(stored.analytics)
      return
    }

    setCookieConsentState({
      resolved: true,
      bannerRequired: true,
      analyticsGranted: false,
    })
    setShowBanner(true)
    setDraftAnalytics(false)
  }, [bannerRequired, cookieBannerEnabled, localeError, localeReady])

  const persistPreferences = useCallback(
    (preferences: CookieConsentPreferences) => {
      writeStoredCookieConsent(preferences.analytics)
      setDraftAnalytics(preferences.analytics)
      applyAnalyticsConsent(preferences.analytics)
      setShowBanner(false)
      setPreferencesOpen(false)
      setIsReopening(false)
      setCustomizeOpen(false)
    },
    [],
  )

  const acceptAll = useCallback(() => {
    persistPreferences({ analytics: true })
  }, [persistPreferences])

  const rejectNonEssential = useCallback(() => {
    persistPreferences({ analytics: false })
  }, [persistPreferences])

  const saveCustomPreferences = useCallback(() => {
    persistPreferences({ analytics: draftAnalytics })
  }, [draftAnalytics, persistPreferences])

  const openPreferences = useCallback(() => {
    if (!cookieBannerEnabled) return
    const stored = readStoredCookieConsent()
    setDraftAnalytics(stored?.analytics ?? canTrackAnalytics())
    setCustomizeOpen(true)
    setPreferencesOpen(true)
    setIsReopening(true)
    setShowBanner(true)
  }, [cookieBannerEnabled])

  const closeBanner = useCallback(() => {
    if (isReopening && readStoredCookieConsent()) {
      setShowBanner(false)
      setPreferencesOpen(false)
      setIsReopening(false)
      setCustomizeOpen(false)
      return
    }
    if (!readStoredCookieConsent()) {
      rejectNonEssential()
    }
  }, [isReopening, rejectNonEssential])

  const handleCustomizeOpenChange = useCallback((open: boolean) => {
    if (!open && !readStoredCookieConsent()) {
      setDraftAnalytics(false)
    }
    setCustomizeOpen(open)
  }, [])

  const contextValue = useMemo(
    () => ({
      openPreferences,
      bannerRequired:
        cookieBannerEnabled && (localeReady || localeError)
          ? bannerRequired
          : false,
      preferencesOpen,
    }),
    [
      bannerRequired,
      cookieBannerEnabled,
      localeError,
      localeReady,
      openPreferences,
      preferencesOpen,
    ],
  )

  return (
    <CookieConsentContext.Provider value={contextValue}>
      {children}
      {cookieBannerEnabled && showBanner ? (
        <CookieConsentBanner
          customizeOpen={customizeOpen}
          draftAnalytics={draftAnalytics}
          onAcceptAll={acceptAll}
          onClose={closeBanner}
          onCustomizeOpenChange={handleCustomizeOpenChange}
          onDraftAnalyticsChange={setDraftAnalytics}
          onRejectNonEssential={rejectNonEssential}
          onSaveCustomPreferences={saveCustomPreferences}
          reopening={isReopening}
        />
      ) : null}
    </CookieConsentContext.Provider>
  )
}

export function useCookieConsent() {
  const context = useContext(CookieConsentContext)
  if (!context) {
    throw new Error('useCookieConsent must be used within CookieConsentProvider')
  }
  return context
}

/** Safe when provider is absent (e.g. tests). Returns no-op openPreferences. */
export function useOptionalCookieConsent() {
  return useContext(CookieConsentContext)
}
