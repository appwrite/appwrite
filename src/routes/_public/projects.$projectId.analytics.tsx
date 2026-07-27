import {
  createFileRoute,
  Outlet,
  redirect,
  useMatches,
} from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/analytics/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/analytics')({
  head: () => ({ meta: [{ title: pageTitle('Analytics') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().analytics) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
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
