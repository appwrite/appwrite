import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { LegalPolicyView } from '@/components/pages/legal/View'
import privacyContent from '@/content/legal/privacy.md?raw'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/privacy')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/privacy',
      pageName: 'Privacy Policy',
      description:
        "Appwrite's privacy policy outlines the purpose and scope of data collection necessary to operate our business and its impact on users.",
    });
    return seo;
  },
  loader: async ({ context }) => {
  },
  component: PrivacyPage,
})

function PrivacyPage() {
  return (<LegalPolicyView title="Privacy Policy" content={privacyContent} currentPolicy="privacy" />
    )
}
