import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { LegalPolicyView } from '@/components/pages/legal/View'
import termsContent from '@/content/legal/terms.md?raw'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/terms')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/terms',
      pageName: 'Terms and Conditions',
      description:
        'Review our Terms of Service to understand the rules and guidelines for using our open-source backend-as-a-service platform.',
    });
    return seo;
  },
  loader: async ({ context }) => {
  },
  component: TermsPage,
})

function TermsPage() {
  return (<LegalPolicyView title="Terms and Conditions" content={termsContent} currentPolicy="terms" />
    )
}
