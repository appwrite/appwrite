import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId',
)({
  component: BucketLayout,
})

function BucketLayout() {
  return <Outlet />
}
