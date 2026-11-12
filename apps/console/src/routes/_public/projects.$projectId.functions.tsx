import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/functions')({
  head: () => ({ meta: [{ title: pageTitle('Functions') }] }),
  component: FunctionsPage,
})

function FunctionsPage() {
  const matches = useMatches()

  // Check if we're on a child route (function detail, editor, create, etc.)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId.includes('/functions/$functionId') ||
      match.routeId.includes('/functions/editor') ||
      match.routeId.includes('/functions/create') ||
      match.routeId.includes('/functions/templates') ||
      match.routeId.startsWith(
        '/_public/projects/$projectId/functions/$functionId',
      ) ||
      match.routeId === '/_public/projects/$projectId/functions/editor' ||
      match.routeId === '/_public/projects/$projectId/functions/create' ||
      match.routeId === '/_public/projects/$projectId/functions/create/' ||
      match.routeId === '/_public/projects/$projectId/functions/templates',
  )

  // If we're on a child route, render the outlet (child route component).
  // Wrap in full-height flex container so fixed-layout children (e.g. editor) can fill space.
  if (isChildRoute) {
    return (
      <div className="flex h-full min-h-0 flex-1 flex-col">
        <Outlet />
      </div>
    )
  }

  // Otherwise, show the functions list view
  return <View />
}
