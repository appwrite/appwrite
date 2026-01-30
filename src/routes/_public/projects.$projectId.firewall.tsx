import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/View'

export const Route = createFileRoute('/_public/projects/$projectId/firewall')({
  component: FirewallPage,
})

function FirewallPage() {
  const matches = useMatches()

  // Check if we're on a child route (analytics, logs, etc.)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId.includes('/firewall/analytics') ||
      match.routeId.includes('/firewall/logs') ||
      (match.routeId.startsWith('/_public/projects/$projectId/firewall/') &&
        match.routeId !== '/_public/projects/$projectId/firewall'),
  )

  // If we're on a child route, render the outlet (child route component)
  if (isChildRoute) {
    return <Outlet />
  }

  // Otherwise, show the firewall view
  return <View />
}
