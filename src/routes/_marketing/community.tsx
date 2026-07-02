import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/community/View'
import { fetchCommunityGitHubIssues } from '@/lib/community/github-issues'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/community')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Community',
      description:
        'Join our vibrant community of developers. Ask questions, contribute solutions, and inspire others to improve the backend development experience.',
    }),
  }),
  loader: async ({ context }) => {

    const issues = await fetchCommunityGitHubIssues()
    return { issues }
  },
  component: CommunityPage,
})

function CommunityPage() {
  const { issues } = Route.useLoaderData()
  return (<View issues={issues} />
    )
}
