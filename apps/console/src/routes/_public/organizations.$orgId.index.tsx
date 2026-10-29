import { createFileRoute } from '@tanstack/react-router'
import {
  organizationOverviewProjectsParamsFromUrl,
  prefetchOrganizationOverviewData,
} from '@/lib/organization-overview-prefetch'
import { urlFromRouterLocation } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/organizations/$orgId/')({
  head: () => ({ meta: [{ title: pageTitle('Organization') }] }),
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    if (orgId) {
      await prefetchOrganizationOverviewData(
        queryClient,
        orgId,
        organizationOverviewProjectsParamsFromUrl(
          urlFromRouterLocation(location),
        ),
      )
    }
  },
  component: OrgOverviewIndexPage,
})

// Index route doesn't need to render anything - parent OrgOverview handles the content
function OrgOverviewIndexPage() {
  return null
}
