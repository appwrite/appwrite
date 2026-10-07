import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/analytics/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/analytics')({
  head: () => ({ meta: [{ title: pageTitle('Analytics') }] }),
  component: AnalyticsPage,
})

function AnalyticsPage() {
  const matches = useMatches()
  // Check if we're on a child route (website detail page)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId === '/_public/projects/$projectId/analytics/$websiteId',
  )

  if (isChildRoute) {
    return <Outlet />
  }

  return <View />
}
