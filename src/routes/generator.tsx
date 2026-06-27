import { createFileRoute, redirect } from '@tanstack/react-router'
import { GeneratorLayout } from '@/components/pages/generator/GeneratorLayout'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/generator')({
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Generator') },
      {
        name: 'description',
        content:
          'Internal generator for Appwrite marketing assets, Open Graph images, and diagrams.',
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
  component: GeneratorLayout,
})
