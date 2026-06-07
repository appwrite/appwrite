import { createFileRoute, redirect } from '@tanstack/react-router'
import { LegalPolicyView } from '@/components/pages/legal/View'
import cookiesContent from '@/content/legal/cookies.md?raw'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/cookies')({
  ssr: false,
  head: () => ({
    meta: [
      { title: pageTitle('Cookies Policy') },
      {
        name: 'description',
        content:
          'This cookie policy explains what cookies are, how we use them at Appwrite, and how you can manage and customize your preferences.',
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
  component: CookiesPage,
})

function CookiesPage() {
  return (
    <MarketingPageShell>
      <LegalPolicyView
        title="Cookies Policy"
        content={cookiesContent}
        currentPolicy="cookies"
      />
    </MarketingPageShell>
  )
}
