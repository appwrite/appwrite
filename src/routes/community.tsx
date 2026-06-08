import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/community/View'
import { fetchCommunityGitHubIssues } from '@/lib/community/github-issues'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/community')({
  ssr: false,
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
    if (typeof window === 'undefined') return { issues: [] }

    if (!getActiveProfileFeatures().marketing) {
      throw redirect({ to: '/', replace: true })
    }

    void context.queryClient
      .prefetchQuery(consoleAccountQueryOptions())
      .catch(() => {})

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
