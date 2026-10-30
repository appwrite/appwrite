import { createFileRoute, redirect, isRedirect } from '@tanstack/react-router'
import { PartnerLayout } from '@/components/pages/partners/$partnerId/Layout'
import {
  organizationQueryOptions,
  partnerClientsQueryOptions,
} from '@/lib/react-query/hooks'
import { isPartnerOrganization } from '@/lib/partners'

export const Route = createFileRoute('/_public/partners/$partnerId')({
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { partnerId } = params
    if (!partnerId) return
    try {
      const org = await context.queryClient.ensureQueryData(
        organizationQueryOptions(partnerId),
      )
      if (!org) {
        throw redirect({ to: '/', replace: true })
      }
      if (!isPartnerOrganization(org)) {
        throw redirect({
          to: '/organizations/$orgId',
          params: { orgId: partnerId },
          replace: true,
        })
      }
    } catch (error) {
      if (isRedirect(error)) throw error
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { partnerId } = params
    if (!partnerId) return
    await context.queryClient
      .ensureQueryData(partnerClientsQueryOptions(partnerId))
      .catch(() => {})
  },
  component: PartnerLayoutRoute,
})

function PartnerLayoutRoute() {
  const { partnerId } = Route.useParams()
  return <PartnerLayout partnerId={partnerId} />
}
