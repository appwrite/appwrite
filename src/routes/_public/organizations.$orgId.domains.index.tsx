import { View } from '@/components/pages/organizations/$orgId/domains/View'
import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  organizationsQueryOptions,
  organizationDomainsQueryOptions,
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'

const DEFAULT_PAGE = 1

export const Route = createFileRoute('/_public/organizations/$orgId/domains/')({
  head: () => ({ meta: [{ title: pageTitle('Domains', 'Organization') }] }),
  validateSearch: listSearchSchema,
  loader: async ({ params, context, search: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { orgId } = params
    const { queryClient } = context
    if (!orgId) return

    const { search, page, limit, filterQueries, sort } = parseListSearch(
      routeSearch,
      {
        page: DEFAULT_PAGE,
        limit: GRID_DEFAULT_PAGE_SIZE,
      },
    )
    const sortBy = sort?.sortBy ?? DOMAINS_DEFAULT_SORT_BY
    const sortOrder = sort?.sortOrder ?? DOMAINS_DEFAULT_SORT_ORDER

    await queryClient.ensureQueryData(organizationsQueryOptions())
    await queryClient.ensureQueryData(
      organizationDomainsQueryOptions(
        orgId,
        page - 1,
        limit,
        search ?? undefined,
        filterQueries,
        sortBy,
        sortOrder,
      ),
    )
  },
  component: DomainsIndexPage,
})

function DomainsIndexPage() {
  return <View />
}
