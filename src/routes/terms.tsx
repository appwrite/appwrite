import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { LegalPolicyView } from '@/components/pages/legal/View'
import termsContent from '@/content/legal/terms.md?raw'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/terms')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Terms and Conditions',
      description:
        'Review our Terms of Service to understand the rules and guidelines for using our open-source backend-as-a-service platform.',
    }),
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
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
