import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/$providerId',
)({
  component: ProviderLayout,
})

function ProviderLayout() {
  return <Outlet />
}
