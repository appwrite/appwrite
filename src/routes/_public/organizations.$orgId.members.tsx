import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute('/_public/organizations/$orgId/members')({
  beforeLoad: ({ params }) => {
    if (getActiveProfileFeatures().orgRoles) {
      throw redirect({
        to: '/organizations/$orgId/settings/members',
        params: { orgId: params.orgId },
      })
    }
    throw redirect({
      to: '/organizations/$orgId',
      params: { orgId: params.orgId },
      replace: true,
    })
  },
})
