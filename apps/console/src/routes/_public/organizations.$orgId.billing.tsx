import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute('/_public/organizations/$orgId/billing')({
  beforeLoad: ({ params }) => {
    if (getActiveProfileFeatures().billing) {
      throw redirect({
        to: '/organizations/$orgId/settings/billing',
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
