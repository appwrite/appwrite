import {
  HeadContent,
  Scripts,
  ScriptOnce,
  createRootRouteWithContext,
} from '@tanstack/react-router'
import appCss from '../styles.css?url'
import {
  getRuntimeConfig,
  getRuntimeConfigScript,
} from '@/lib/runtime-config'

import type { QueryClient } from '@tanstack/react-query'
import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Toaster } from '@/components/ui/sonner'
import { ThemeProvider, useTheme } from 'next-themes'
import {
  applyFaviconHref,
  applyFaviconVariant,
  getDefaultFaviconVariant,
} from '@/lib/favicon'
import {
  isLegacyTheme,
  LEGACY_ICON_SRC,
} from '@/lib/legacy-theme-assets'
import { AIChatProvider } from '@/components/global/providers/AIChat'
import { DocsPreviewProvider } from '@/components/global/providers/DocsPreview'
import {
  ConsoleRightPane,
  ConsoleRightPaneProvider,
} from '@/components/global/providers/ConsoleRightPane'
import { DebugMenu } from '@/components/global/providers/DebugMenu'
import { PromoBannerProvider } from '@/components/global/providers/PromoBanner'
import { CookieConsentProvider } from '@/components/global/providers/CookieConsent'
import { DebugModeProvider } from '@/components/global/providers/DebugMode'
import { SentryContextProvider } from '@/components/global/providers/SentryContext'
import { NavigationHistoryProvider } from '@/components/global/providers/NavigationHistoryProvider'
import {
  FullscreenLoader,
} from '@/components/ui/loader'
import { useInitialLoader } from '@/hooks/use-initial-loader'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { getStatusBannerParts } from '@/lib/cloud-status-copy'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import { useAppwriteCloudStatus } from '@/lib/react-query/hooks'
import { consoleProjectScopesQueryOptions } from '@/lib/react-query/hooks/console-project-scopes'
import { DynamicFavicon } from '@/components/global/shared/DynamicFavicon'
import { UploadWarning } from '@/components/global/providers/UploadWarning'
import { GlobalUploadProgress } from '@/components/global/shared/GlobalUploadProgress'
import { useLocation, useMatches } from '@tanstack/react-router'
import {
  getAnalyticsRoutePath,
  trackPageView,
} from '@/lib/analytics'
import { canTrackAnalytics, subscribeCookieConsent } from '@/lib/cookie-consent/consent-state'
import { useGlobalAnalyticsTracker } from '@/hooks/use-global-analytics-tracker'
import {
  getConsoleRouteIds,
  withPageTitleNameContext,
} from '@/lib/utils/page-title'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { getSeoRobotsMetaTags } from '@/lib/seo/indexing'

interface MyRouterContext {
  queryClient: QueryClient
}

/**
 * Inline script that runs before first paint (via ScriptOnce). Applies theme
 * class to <html> so the initial loader respects user choice first, then
 * system preference. Must match next-themes storageKey ("theme") and logic.
 */
const THEME_SCRIPT = `(function(){
  try {
    var t = localStorage.getItem('theme') || 'system';
    if (t === 'classic') { localStorage.setItem('theme', 'dark'); t = 'dark'; }
    var r = t === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : t;
    var e = document.documentElement;
    ['light','dark','system','crazy','stealth','classic','premium','high-contrast','barbie','nineties','legacy'].forEach(function(c){e.classList.remove(c);});
    e.classList.add(r);
  } catch (e) {}
})()`

const scripts: React.DetailedHTMLProps<
  React.ScriptHTMLAttributes<HTMLScriptElement>,
  HTMLScriptElement
>[] = []

/**
 * Font preloads aligned with VITE_CONSOLE_PROFILE. Cloud uses Aeonik (appwrite/website);
 * self-hosted uses Inter from appwrite/console static fonts. Debug menu profile override
 * is client-only, so preloads follow the build env until the user refreshes after switching.
 */
function getHeadFontPreloads() {
  const raw = getRuntimeConfig()
    .consoleProfile.toLowerCase()
    .trim()
    .replace(/\s+/g, '-')
  if (raw === 'self-hosted') {
    return [
      {
        rel: 'preload' as const,
        href: '/fonts/inter/inter-v8-latin-regular.woff2',
        as: 'font' as const,
        type: 'font/woff2',
        crossOrigin: 'anonymous' as const,
      },
      {
        rel: 'preload' as const,
        href: '/fonts/inter/inter-v8-latin-600.woff2',
        as: 'font' as const,
        type: 'font/woff2',
        crossOrigin: 'anonymous' as const,
      },
    ]
  }
  return [
    {
      rel: 'preload' as const,
      href: '/fonts/aeonik-pro/AeonikPro-Regular.woff2',
      as: 'font' as const,
      type: 'font/woff2',
      crossOrigin: 'anonymous' as const,
    },
  ]
}

export const Route = createRootRouteWithContext<MyRouterContext>()({
  loader: async () => {
    // Client-side authentication is handled by RequireAuth component
    // Return null for currentUser - it will be fetched client-side
    return {
      currentUser: null,
    }
  },
  head: () => ({
    meta: [
      {
        name: 'viewport',
        content:
          'width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover',
      },
      {
        title: 'Appwrite Console',
      },
      ...getSeoRobotsMetaTags(getRequestSiteOrigin()),
    ],
    links: [
      {
        rel: 'icon',
        href: import.meta.env.DEV ? '/logo-theme.svg' : '/logo.svg',
        type: 'image/svg+xml',
      },
      ...(import.meta.env.DEV
        ? []
        : [
            {
              rel: 'shortcut icon' as const,
              href: '/favicon.ico',
            },
          ]),
      {
        rel: 'apple-touch-icon',
        href: '/apple-touch-icon.png',
        sizes: '180x180',
      },
      ...getHeadFontPreloads(),
    ],
    scripts: [...scripts],
  }),

  shellComponent: RootDocument,
})

/** Remap removed debug theme so old localStorage values do not leave stale classes. */
function MigrateRemovedThemes() {
  const { theme, setTheme } = useTheme()
  useEffect(() => {
    if (theme === 'classic') setTheme('dark')
  }, [theme, setTheme])
  return null
}

/** Swap favicon to the classic Appwrite mark while the legacy debug theme is active. */
function LegacyThemeFavicon() {
  const { theme, resolvedTheme } = useTheme()

  useEffect(() => {
    if (isLegacyTheme(theme, resolvedTheme)) {
      applyFaviconHref(LEGACY_ICON_SRC, { cacheBust: false })
      return
    }

    const variant = getDefaultFaviconVariant()
    applyFaviconVariant(variant, { cacheBust: false })
  }, [theme, resolvedTheme])

  return null
}

/**
 * Renders ThemeProvider only after client mount. next-themes uses React context
 * in a way that can fail during SSR (renderToPipeableStream) with "Cannot read
 * properties of null (reading 'useContext')". Deferring to client avoids this.
 */
function ClientThemeProvider({ children }: { children: React.ReactNode }) {
  const [mounted, setMounted] = useState(false)
  useEffect(() => {
    setMounted(true)
  }, [])
  if (!mounted) {
    return <>{children}</>
  }
  return (
    <ThemeProvider
      attribute="class"
      defaultTheme="system"
      enableSystem
      disableTransitionOnChange
      themes={[
        'light',
        'dark',
        'system',
        'crazy',
        'stealth',
        'premium',
        'high-contrast',
        'barbie',
        'nineties',
        'legacy',
      ]}
    >
      <MigrateRemovedThemes />
      <LegacyThemeFavicon />
      {children}
    </ThemeProvider>
  )
}

/**
 * Renders children only after client mount. Use for components that call
 * next-themes useTheme() so they never run during SSR (avoids useContext crash).
 */
function ClientOnly({ children }: { children: React.ReactNode }) {
  const [mounted, setMounted] = useState(false)
  useEffect(() => {
    setMounted(true)
  }, [])
  if (!mounted) return null
  return <>{children}</>
}

function PlausibleRouteTracker() {
  const location = useLocation()
  const matches = useMatches()
  const leafRoute = matches[matches.length - 1]
  const [consentTick, setConsentTick] = useState(0)

  useEffect(() => {
    return subscribeCookieConsent(() => {
      setConsentTick((tick) => tick + 1)
    })
  }, [])

  useEffect(() => {
    if (!canTrackAnalytics() || typeof window === 'undefined') return

    const routePath = getAnalyticsRoutePath(leafRoute?.routeId, location.pathname)
    trackPageView(routePath)
  }, [consentTick, leafRoute?.routeId, location.pathname])

  return null
}

function ContextualDocumentTitle() {
  const location = useLocation()
  const queryClient = useQueryClient()
  const [previousContextPart, setPreviousContextPart] = useState<
    string | undefined
  >()
  useMatches()

  useEffect(() => {
    if (typeof document === 'undefined') return

    const { projectId, orgId } = getConsoleRouteIds(location.pathname)
    const project = projectId
      ? (queryClient.getQueryData(['project', projectId]) as
          | { name?: string }
          | undefined)
      : undefined
    const organization = orgId
      ? (queryClient.getQueryData(['organization', orgId]) as
          | { name?: string }
          | undefined) ??
        (
          queryClient.getQueryData(['organizations', 'console']) as
            | { teams?: { $id?: string; name?: string }[] }
            | undefined
        )?.teams?.find((team) => team.$id === orgId)
      : undefined
    const contextPart = project?.name ?? organization?.name

    const nextTitle = withPageTitleNameContext(document.title, {
      projectName: project?.name,
      organizationName: organization?.name,
      previousContextPart,
    })
    if (document.title !== nextTitle) {
      document.title = nextTitle
    }
    if (previousContextPart !== contextPart) {
      setPreviousContextPart(contextPart)
    }
  })

  return null
}

/** When true, upload progress is shown by the project layout unified panel instead of root */
function isProjectRoute(pathname: string) {
  const parts = pathname.split('/').filter(Boolean)
  return parts[0] === 'projects' && parts.length >= 2
}

const STATUS_PAGE_URL = 'https://status.appwrite.online'

function RootDocument({ children }: { children: React.ReactNode }) {
  const queryClient = useQueryClient()
  const { isLoading, skipStaticLoader } = useInitialLoader()
  const [clientMounted, setClientMounted] = useState(false)
  const location = useLocation()
  const { isCloud, features } = useConsoleProfile()
  const { account, isFetched } = useAuth()
  const cloudStatusEnabled = isCloud && features.systemStatus
  const showCloudStatusToOperator =
    isFetched && isOperatorAccount(account as OperatorAccount)
  const { data: statusData, isSuccess: isStatusSuccess } =
    useAppwriteCloudStatus(cloudStatusEnabled && showCloudStatusToOperator)
  const { showFullscreenLoader } = useDebugOverrides()
  useGlobalAnalyticsTracker()

  useEffect(() => {
    setClientMounted(true)
  }, [])

  useEffect(() => {
    if (typeof window === 'undefined') return
    void queryClient
      .prefetchQuery(consoleProjectScopesQueryOptions())
      .catch(() => {})
  }, [queryClient])

  const isLoaderVisible = isLoading || showFullscreenLoader

  const statusBanner =
    cloudStatusEnabled &&
    showCloudStatusToOperator &&
    isLoaderVisible &&
    isStatusSuccess &&
    statusData?.consoleAlertState &&
    statusData.consoleAlertState !== 'operational'
      ? {
          ...getStatusBannerParts(statusData.consoleAlertState, {
            reportTitle: statusData.activeReport?.title,
            startsAt: statusData.activeReport?.startsAt,
            endsAt: statusData.activeReport?.endsAt,
            regionsLine: statusData.regionsLine,
          }),
          href: STATUS_PAGE_URL,
          state: statusData.consoleAlertState,
        }
      : undefined

  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <meta charSet="utf-8" />
        <link rel="stylesheet" href={appCss} />
        <HeadContent />
      </head>
      <body suppressHydrationWarning>
        {/* Publish runtime config to the browser before the app bundle runs.
            Must precede <Scripts /> so module-level config reads see it. */}
        <ScriptOnce>{getRuntimeConfigScript()}</ScriptOnce>
        <ScriptOnce>{THEME_SCRIPT}</ScriptOnce>
        <DynamicFavicon />
        <UploadWarning />
        <PlausibleRouteTracker />
        <ContextualDocumentTitle />
        <ClientThemeProvider>
          <CookieConsentProvider>
            <NavigationHistoryProvider>
            {/* Branded loader (logo + 2.0) from first paint; fade out only when data is ready. */}
            {!skipStaticLoader ? (
              <FullscreenLoader
                isVisible={clientMounted ? isLoaderVisible : true}
                statusBanner={clientMounted ? statusBanner : undefined}
              />
            ) : null}
            <SentryContextProvider>
              <DebugModeProvider>
                <ConsoleRightPaneProvider>
                  {features.aiAssistant ? (
                    <AIChatProvider>
                      <DocsPreviewProvider>
                        <PromoBannerProvider>
                          <div className="flex w-full min-w-0 overflow-hidden root-container">
                            <div className="root-scroll-container flex-1 overflow-hidden min-h-0 h-full">
                              {children}
                            </div>
                            <ConsoleRightPane />
                          </div>
                          <ClientOnly>
                            <DebugMenu />
                          </ClientOnly>
                        </PromoBannerProvider>
                      </DocsPreviewProvider>
                    </AIChatProvider>
                  ) : (
                    <DocsPreviewProvider>
                      <PromoBannerProvider>
                        <div className="flex w-full min-w-0 overflow-hidden root-container">
                          <div className="root-scroll-container flex-1 overflow-hidden min-h-0 h-full">
                            {children}
                          </div>
                          <ConsoleRightPane />
                        </div>
                        <ClientOnly>
                          <DebugMenu />
                        </ClientOnly>
                      </PromoBannerProvider>
                    </DocsPreviewProvider>
                  )}
                </ConsoleRightPaneProvider>
              </DebugModeProvider>
            </SentryContextProvider>
            <ClientOnly>
              <Toaster />
            </ClientOnly>
            <ClientOnly>
              {!isProjectRoute(location.pathname) && <GlobalUploadProgress />}
            </ClientOnly>
            </NavigationHistoryProvider>
          </CookieConsentProvider>
        </ClientThemeProvider>
        <Scripts />
      </body>
    </html>
  )
}
