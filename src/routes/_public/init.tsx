import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/init/View'
import { getInitPageMetaTags } from '@/lib/init/init-seo'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks/auth'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute('/_public/init')({
  ssr: true,
  component: InitPage,
  head: () => ({ meta: getInitPageMetaTags() }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().init) {
      throw redirect({ to: '/', replace: true })
    }

    // Resolve auth before first paint so header, sidebar, and hero CTAs do not reflow.
    await ensureConsoleAccountQueryData(context.queryClient)
  },
})

function InitPage() {
  return <View />
}
