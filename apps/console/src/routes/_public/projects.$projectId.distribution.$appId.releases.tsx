import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/releases',
)({
  component: Outlet,
})
