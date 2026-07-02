import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { LegalPolicyView } from '@/components/pages/legal/View'
import privacyContent from '@/content/legal/privacy.md?raw'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/privacy')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Privacy Policy',
      description:
        "Appwrite's privacy policy outlines the purpose and scope of data collection necessary to operate our business and its impact on users.",
    }),
  }),
  loader: async ({ context }) => {
  },
  component: PrivacyPage,
})

function PrivacyPage() {
  return (<LegalPolicyView title="Privacy Policy" content={privacyContent} currentPolicy="privacy" />
    )
}
