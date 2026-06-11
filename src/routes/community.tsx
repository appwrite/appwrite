import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/community/View'
import { fetchCommunityGitHubIssues } from '@/lib/community/github-issues'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/community')({
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Community') },
      {
        name: 'description',
        content:
          'Join our vibrant community of developers. Ask questions, contribute solutions, and inspire others to improve the backend development experience.',
      },
    ],
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)

    const issues = await fetchCommunityGitHubIssues()
    return { issues }
  },
  component: CommunityPage,
})

function CommunityPage() {
  const { issues } = Route.useLoaderData()
  return (
    <MarketingPageShell>
      <View issues={issues} />
    </MarketingPageShell>
  )
}
