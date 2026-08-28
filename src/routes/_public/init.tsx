import { lazy, Suspense } from 'react'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { FullscreenLoader } from '@/components/ui/loader'
import { getInitPageMetaTags } from '@/lib/init/init-seo'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks/auth'
import { isInitSurfaceEnabled } from '@/lib/init/init-surface'

const InitView = lazy(() =>
  import('@/components/pages/init/View').then((module) => ({
    default: module.View,
  })),
)

export const Route = createFileRoute('/_public/init')({
  ssr: false,
  component: InitPage,
  head: () => ({ meta: getInitPageMetaTags() }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    if (!isInitSurfaceEnabled()) {
      throw redirect({ to: '/', replace: true })
    }

    // Resolve auth before first paint so header, sidebar, and hero CTAs do not reflow.
    await ensureConsoleAccountQueryData(context.queryClient)
  },
})

function InitPage() {
  return (
    <Suspense fallback={<FullscreenLoader />}>
      <InitView />
    </Suspense>
  )
}
