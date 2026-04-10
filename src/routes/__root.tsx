import {
  HeadContent,
  Scripts,
  ScriptOnce,
  createRootRouteWithContext,
} from '@tanstack/react-router'
import appCss from '../styles.css?url'

import type { QueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Toaster } from '@/components/ui/sonner'
import { ThemeProvider, useTheme } from 'next-themes'
import {
  AIChatProvider,
  AIChatPanel,
} from '@/components/global/providers/AIChat'
import { DebugMenu } from '@/components/global/providers/DebugMenu'
import { PromoBannerProvider } from '@/components/global/providers/PromoBanner'
import { DebugModeProvider } from '@/components/global/providers/DebugMode'
import { SentryContextProvider } from '@/components/global/providers/SentryContext'
import { NavigationHistoryProvider } from '@/components/global/providers/NavigationHistoryProvider'
import {
  FullscreenLoader,
  StaticFullscreenLoader,
} from '@/components/ui/loader'
import { useInitialLoader } from '@/hooks/use-initial-loader'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getStatusBannerParts } from '@/lib/cloud-status-copy'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useAppwriteCloudStatus } from '@/lib/react-query/hooks'
import { DynamicFavicon } from '@/components/global/shared/DynamicFavicon'
import { UploadWarning } from '@/components/global/providers/UploadWarning'
import { GlobalUploadProgress } from '@/components/global/shared/GlobalUploadProgress'
import { useLocation } from '@tanstack/react-router'

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
    ['light','dark','system','crazy','stealth','classic','premium','high-contrast','barbie','nineties'].forEach(function(c){e.classList.remove(c);});
    e.classList.add(r);
  } catch (e) {}
})()`

const scripts: React.DetailedHTMLProps<
  React.ScriptHTMLAttributes<HTMLScriptElement>,
  HTMLScriptElement
>[] = []

if (import.meta.env.VITE_INSTRUMENTATION_SCRIPT_SRC) {
  scripts.push({
    src: import.meta.env.VITE_INSTRUMENTATION_SCRIPT_SRC,
    type: 'module',
  })
}

/**
 * Font preloads aligned with VITE_CONSOLE_PROFILE. Cloud uses Aeonik (appwrite/website);
 * self-hosted uses Inter from appwrite/console static fonts. Debug menu profile override
 * is client-only, so preloads follow the build env until the user refreshes after switching.
 */
function getHeadFontPreloads() {
  const raw = (
    import.meta.env?.VITE_CONSOLE_PROFILE as string | undefined
  )
    ?.toLowerCase()
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
        charSet: 'utf-8',
      },
      {
        name: 'viewport',
        content:
          'width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover',
      },
      {
        title: 'Appwrite Console',
      },
    ],
    links: [
      {
        rel: 'stylesheet',
        href: appCss,
      },
      {
        rel: 'icon',
        href: '/logo.svg',
        type: 'image/svg+xml',
      },
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
      ]}
    >
      <MigrateRemovedThemes />
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

/** When true, upload progress is shown by the project layout unified panel instead of root */
function isProjectRoute(pathname: string) {
  const parts = pathname.split('/').filter(Boolean)
  return parts[0] === 'projects' && parts.length >= 2
}

const STATUS_PAGE_URL = 'https://status.appwrite.online'

function RootDocument({ children }: { children: React.ReactNode }) {
  const { isLoading, isAuthRoute } = useInitialLoader()
  const [clientMounted, setClientMounted] = useState(false)
  const location = useLocation()
  const { features } = useConsoleProfile()
  const { data: statusData, isSuccess: isStatusSuccess } =
    useAppwriteCloudStatus(features.systemStatus)
  const { showFullscreenLoader } = useDebugOverrides()

  useEffect(() => {
    setClientMounted(true)
  }, [])

  const isLoaderVisible = isLoading || showFullscreenLoader

  const statusBanner =
    isLoaderVisible &&
    isStatusSuccess &&
    statusData?.aggregateState &&
    statusData.aggregateState !== 'operational'
      ? {
          ...getStatusBannerParts(statusData.aggregateState, {
            reportTitle: statusData.activeReport?.title,
            startsAt: statusData.activeReport?.startsAt,
            endsAt: statusData.activeReport?.endsAt,
          }),
          href: STATUS_PAGE_URL,
          state: statusData.aggregateState,
        }
      : undefined

  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <HeadContent />
      </head>
      <body suppressHydrationWarning>
        <ScriptOnce>{THEME_SCRIPT}</ScriptOnce>
        <DynamicFavicon />
        <UploadWarning />
        <ClientThemeProvider>
          <NavigationHistoryProvider>
            {/* Show branded loader (logo + 2.0) from first paint; avoid route "Loading..." flash.
                Before client mount: always show static loader for non-auth routes (including "/")
                so the very first HTML paint shows the logo instead of route-level "Loading...".
                When clientMounted, use FullscreenLoader so it can run fade-out before unmount. */}
            {clientMounted ? (
              <FullscreenLoader
                isVisible={isLoaderVisible}
                statusBanner={statusBanner}
              />
            ) : !isAuthRoute ? (
              <StaticFullscreenLoader />
            ) : null}
            <SentryContextProvider>
              <DebugModeProvider>
                {features.aiAssistant ? (
                  <AIChatProvider>
                    <PromoBannerProvider>
                      <div className="flex w-full min-w-0 overflow-hidden root-container">
                        <div className="root-scroll-container flex-1 overflow-hidden min-h-0 h-full">
                          {children}
                        </div>
                        <AIChatPanel />
                      </div>
                      <ClientOnly>
                        <DebugMenu />
                      </ClientOnly>
                    </PromoBannerProvider>
                  </AIChatProvider>
                ) : (
                  <PromoBannerProvider>
                    <div className="flex w-full min-w-0 overflow-hidden root-container">
                      <div className="root-scroll-container flex-1 overflow-hidden min-h-0 h-full">
                        {children}
                      </div>
                    </div>
                    <ClientOnly>
                      <DebugMenu />
                    </ClientOnly>
                  </PromoBannerProvider>
                )}
              </DebugModeProvider>
            </SentryContextProvider>
            <ClientOnly>
              <Toaster />
            </ClientOnly>
            <ClientOnly>
              {!isProjectRoute(location.pathname) && <GlobalUploadProgress />}
            </ClientOnly>
          </NavigationHistoryProvider>
          {/* <TanStackDevtools
            config={{
              position: 'bottom-left',
            }}
            plugins={[
              {
                name: 'Tanstack Router',
                render: <TanStackRouterDevtoolsPanel />,
              },
              TanStackQueryDevtools,
            ]}
          /> */}
        </ClientThemeProvider>
        <Scripts />
      </body>
    </html>
  )
}
