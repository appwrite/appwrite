import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { LegalPolicyView } from '@/components/pages/legal/View'
import cookiesContent from '@/content/legal/cookies.md?raw'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'
import { useT } from '@/lib/i18n/translate'

export const Route = createFileRoute('/_marketing/cookies')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Cookies Policy',
      description:
        'This cookie policy explains what cookies are, how we use them at Appwrite, and how you can manage and customize your preferences.',
    }),
  }),
  loader: async ({ context }) => {
  },
  component: CookiesPage,
})

function CookiesPage() {
  const t = useT()
  return (<LegalPolicyView
        title={t('Cookies Policy')}
        content={cookiesContent}
        currentPolicy="cookies"
      />
    )
}
