import { View } from '@/components/pages/organizations/$orgId/domains/View'
import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  organizationsQueryOptions,
  organizationDomainsQueryOptions,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  listSearchSchema,
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  getSort,
  queryParamToMap,
} from '@/lib/table-filters'
import {
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'

const DEFAULT_PAGE = 1

export const Route = createFileRoute('/_public/organizations/$orgId/domains/')({
  head: () => ({ meta: [{ title: pageTitle('Domains', 'Organization') }] }),
  validateSearch: listSearchSchema,
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return

    const { orgId } = params
    const { queryClient } = context
    if (!orgId) return

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const search = getSearch(url)
    const page = getPage(url, DEFAULT_PAGE)
    const limit = getLimit(url, GRID_DEFAULT_PAGE_SIZE)
    const queryParam = getQueryParam(url)
    const filterMap = queryParamToMap(queryParam)
    const filterQueries =
      filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
    const sort = getSort(url)
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
