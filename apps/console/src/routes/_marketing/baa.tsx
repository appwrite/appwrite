import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { LegalPolicyView } from '@/components/pages/legal/View'
import baaContent from '@/content/legal/baa.md?raw'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'
import { useT } from '@/lib/i18n/translate'

export const Route = createFileRoute('/_marketing/baa')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/baa',
      pageName: 'Business Associate Agreement',
      description:
        "Appwrite's HIPAA Business Associate Agreement (BAA) governing how protected health information is handled for eligible plans.",
    });
    return seo;
  },
  loader: async ({ context }) => {
  },
  component: BaaPage,
})

function BaaPage() {
  const t = useT()
  return (<LegalPolicyView title={t('Business Associate Agreement')} content={baaContent} />
    )
}
