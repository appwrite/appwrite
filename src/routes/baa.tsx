import { createFileRoute, redirect } from '@tanstack/react-router'
import { LegalPolicyView } from '@/components/pages/legal/View'
import baaContent from '@/content/legal/baa.md?raw'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/baa')({
  ssr: false,
  head: () => ({
    meta: [
      { title: pageTitle('Business Associate Agreement') },
      {
        name: 'description',
        content:
          "Appwrite's HIPAA Business Associate Agreement (BAA) governing how protected health information is handled for eligible plans.",
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
  component: BaaPage,
})

function BaaPage() {
  return (
    <MarketingPageShell>
      <LegalPolicyView title="Business Associate Agreement" content={baaContent} />
    </MarketingPageShell>
  )
}
