import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId',
)({
  component: CollectionLayout,
})

function CollectionLayout() {
  return <Outlet />
}
