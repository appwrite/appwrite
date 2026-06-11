import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/education/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/education')({
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Education') },
      {
        name: 'description',
        content:
          'Students can expand their skillset without spending a penny. Sign up for the Appwrite Education program to get access to our Pro plan.',
      },
    ],
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: EducationPage,
})

function EducationPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
