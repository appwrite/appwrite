import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/init/View'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute('/_public/init')({
  component: InitPage,
  head: () => ({ meta: [{ title: pageTitle('Init') }] }),
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
