import { createFileRoute, redirect } from '@tanstack/react-router'
import { LegalPolicyView } from '@/components/pages/legal/View'
import termsContent from '@/content/legal/terms.md?raw'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/terms')({
  ssr: false,
  head: () => ({
    meta: [
      { title: pageTitle('Terms and Conditions') },
      {
        name: 'description',
        content:
          'Review our Terms of Service to understand the rules and guidelines for using our open-source backend-as-a-service platform.',
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
  component: TermsPage,
})

function TermsPage() {
  return (
    <MarketingPageShell>
      <LegalPolicyView title="Terms and Conditions" content={termsContent} currentPolicy="terms" />
    </MarketingPageShell>
  )
}
