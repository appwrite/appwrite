import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/education/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/education')({
  ssr: false,
  head: () => ({
    meta: [
      { title: pageTitle('Education') },
      {
        name: 'description',
        content:
          'Students can expand their skillset without spending a penny. Sign up for the Appwrite Education program to get access to our Pro plan.',
      },
    ],
  }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    if (!getActiveProfileFeatures().marketing) {
      throw redirect({ to: '/', replace: true })
    }

    void context.queryClient
      .prefetchQuery(consoleAccountQueryOptions())
      .catch(() => {})
  },
  component: EducationPage,
})

function EducationPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
