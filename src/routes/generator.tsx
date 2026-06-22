import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/generator/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/generator')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Cover generator') },
      {
        name: 'description',
        content:
          'Internal cover generator for Appwrite marketing assets and Open Graph images.',
      },
      { name: 'robots', content: 'noindex, nofollow' },
    ],
  }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().marketing) {
      throw redirect({ to: '/', replace: true })
    }
    await marketingPageLoader(context.queryClient)
  },
  component: GeneratorPage,
})

function GeneratorPage() {
  return <View />
}
