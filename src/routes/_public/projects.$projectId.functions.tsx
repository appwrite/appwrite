import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/View'

export const Route = createFileRoute('/_public/projects/$projectId/functions')({
  component: FunctionsPage,
})

function FunctionsPage() {
  const matches = useMatches()

  // Check if we're on a child route (function detail, templates, etc.)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId.includes('/functions/$functionId') ||
      match.routeId.includes('/functions/templates') ||
      match.routeId.startsWith(
        '/_public/projects/$projectId/functions/$functionId',
      ) ||
      match.routeId === '/_public/projects/$projectId/functions/templates',
  )

  // If we're on a child route, render the outlet (child route component)
  if (isChildRoute) {
    return <Outlet />
  }

  // Otherwise, show the functions list view
  return <View />
}
