import {
  HeadContent,
  Scripts,
  createRootRouteWithContext,
} from '@tanstack/react-router'
import appCss from '../styles.css?url'

import type { QueryClient } from '@tanstack/react-query'
import { Toaster } from '@/components/ui/sonner'
import { ThemeProvider } from 'next-themes'
import { AIChatProvider, AIChatPanel } from '@/components/global/providers/AIChat'
import { DebugMenu } from '@/components/global/providers/DebugMenu'
import { PromoBannerProvider } from '@/components/global/providers/PromoBanner'
import { DebugModeProvider } from '@/components/global/providers/DebugMode'
import { FullscreenLoader } from '@/components/ui/loader'
import { useInitialLoader } from '@/hooks/use-initial-loader'
import { DynamicFavicon } from '@/components/global/shared/DynamicFavicon'
import { UploadWarning } from '@/components/global/providers/UploadWarning'
import { GlobalUploadProgress } from '@/components/global/shared/GlobalUploadProgress'

interface MyRouterContext {
  queryClient: QueryClient
}

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
        content: 'width=device-width, initial-scale=1',
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

function RootDocument({ children }: { children: React.ReactNode }) {
  const { isLoading } = useInitialLoader()

  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <HeadContent />
      </head>
      <body>
        <DynamicFavicon />
        <UploadWarning />
        <ThemeProvider
          attribute="class"
          defaultTheme="dark"
          enableSystem
          disableTransitionOnChange
        >
          <FullscreenLoader isVisible={isLoading} />
          <DebugModeProvider>
            <AIChatProvider>
              <PromoBannerProvider>
                <div className="flex h-screen w-screen overflow-hidden root-container">
                  <div className="root-scroll-container flex-1 overflow-hidden min-h-0 h-full">{children}</div>
                  <AIChatPanel />
                </div>
                <DebugMenu />
              </PromoBannerProvider>
            </AIChatProvider>
          </DebugModeProvider>
          <Toaster />
          <GlobalUploadProgress />
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
        </ThemeProvider>
        <Scripts />
      </body>
    </html>
  )
}
