import { lazy, Suspense } from 'react'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { FullscreenLoader } from '@/components/ui/loader'
import { getInitPageRouteHead } from '@/lib/init/init-seo'
import { prefetchOptionalAuthHeaderData } from '@/lib/marketing/route-loader'
import { isInitSurfaceEnabled } from '@/lib/init/init-surface'
import { importNamedDefault } from '@/lib/stale-chunk-error'

const InitView = lazy(() =>
  importNamedDefault(() => import('@/components/pages/init/View'), 'View'),
)

const initSearchSchema = z.object({
  event: z.string().optional(),
  /** Init 5 hero concept preview (`particles` | `trails` | `glass`). */
  hero: z.string().optional(),
})

export const Route = createFileRoute('/_public/init')({
  ssr: false,
  validateSearch: initSearchSchema,
  component: InitPage,
  head: () => getInitPageRouteHead(),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    if (!isInitSurfaceEnabled()) {
      throw redirect({ to: '/', replace: true })
    }

    // Resolve auth before first paint so header, sidebar, and hero CTAs do not reflow.
    await prefetchOptionalAuthHeaderData(context.queryClient)
  },
})

function InitPage() {
  return (
    <Suspense fallback={<FullscreenLoader />}>
      <InitView />
    </Suspense>
  )
}
