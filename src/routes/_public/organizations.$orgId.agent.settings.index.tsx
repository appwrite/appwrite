import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/agent/settings/',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/organizations/$orgId/agent/settings/models',
      params: { orgId: params.orgId },
      replace: true,
    })
  },
})
