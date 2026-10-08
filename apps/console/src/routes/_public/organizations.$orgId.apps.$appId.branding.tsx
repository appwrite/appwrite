import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/branding',
)({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/organizations/$orgId/apps/$appId/settings',
      params: {
        orgId: params.orgId,
        appId: params.appId,
      },
      replace: true,
    })
  },
  component: () => null,
})
