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
import { ThemeProvider } from 'next-themes'
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
    var r = t === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : t;
    var e = document.documentElement;
    ['light','dark','system','crazy','stealth'].forEach(function(c){e.classList.remove(c);});
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
    ],
    scripts: [...scripts],
  }),

  shellComponent: RootDocument,
})

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
      themes={['light', 'dark', 'system', 'crazy', 'stealth']}
    >
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

function RootDocument({ children }: { children: React.ReactNode }) {
  const { isLoading, isAuthRoute } = useInitialLoader()
  const [clientMounted, setClientMounted] = useState(false)
  const location = useLocation()
  useEffect(() => {
    setClientMounted(true)
  }, [])

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
              <FullscreenLoader isVisible={isLoading} />
            ) : !isAuthRoute ? (
              <StaticFullscreenLoader />
            ) : null}
            <SentryContextProvider>
              <DebugModeProvider>
                <AIChatProvider>
                  <PromoBannerProvider>
                    <div className="flex w-screen overflow-hidden root-container">
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
