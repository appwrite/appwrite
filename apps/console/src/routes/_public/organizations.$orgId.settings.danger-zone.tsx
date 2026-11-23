import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/danger-zone',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/organizations/$orgId/settings',
      params: { orgId: params.orgId },
    })
  },
})
