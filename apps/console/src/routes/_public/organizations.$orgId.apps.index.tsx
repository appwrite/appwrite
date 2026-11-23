import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/organizations/$orgId/apps/')({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/organizations/$orgId/settings/oauth-apps',
      params: { orgId: params.orgId },
      replace: true,
    })
  },
  component: () => null,
})
