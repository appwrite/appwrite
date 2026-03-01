import { createFileRoute, redirect } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/organizations/$orgId/billing')({
  beforeLoad: ({ params }) => {
    throw redirect({
      to: '/organizations/$orgId/settings/billing',
      params: { orgId: params.orgId },
    })
  },
})
