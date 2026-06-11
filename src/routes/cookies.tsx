import { createFileRoute } from '@tanstack/react-router'
import { LegalPolicyView } from '@/components/pages/legal/View'
import cookiesContent from '@/content/legal/cookies.md?raw'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/cookies')({
  ssr: true,
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
    await marketingPageLoader(context.queryClient)
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
