import { createFileRoute, redirect } from '@tanstack/react-router'
import { LegalPolicyView } from '@/components/pages/legal/View'
import privacyContent from '@/content/legal/privacy.md?raw'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/privacy')({
  ssr: false,
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
    if (typeof window === 'undefined') return

    if (!getActiveProfileFeatures().marketing) {
      throw redirect({ to: '/', replace: true })
    }

    void context.queryClient
      .prefetchQuery(consoleAccountQueryOptions())
      .catch(() => {})
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
