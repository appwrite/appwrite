import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/community/View'
import { fetchCommunityGitHubIssues } from '@/lib/community/github-issues'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/community')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/community',
      pageName: 'Community',
      description:
        'Join our vibrant community of developers. Ask questions, contribute solutions, and inspire others to improve the backend development experience.',
    });
    return seo;
  },
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
