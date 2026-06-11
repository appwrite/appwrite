import { createFileRoute } from '@tanstack/react-router'
import { LegalPolicyView } from '@/components/pages/legal/View'
import privacyContent from '@/content/legal/privacy.md?raw'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/privacy')({
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Privacy Policy') },
      {
        name: 'description',
        content:
          "Appwrite's privacy policy outlines the purpose and scope of data collection necessary to operate our business and its impact on users.",
      },
    ],
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: PrivacyPage,
})

function PrivacyPage() {
  return (
    <MarketingPageShell>
      <LegalPolicyView title="Privacy Policy" content={privacyContent} currentPolicy="privacy" />
    </MarketingPageShell>
  )
}
