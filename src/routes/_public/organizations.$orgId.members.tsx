import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/organizations/$orgId/members')({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/organizations/$orgId/settings/members',
      params: { orgId: params.orgId },
      replace: true,
    })
  },
})
