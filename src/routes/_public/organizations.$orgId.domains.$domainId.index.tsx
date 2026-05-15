import { View } from '@/components/pages/organizations/$orgId/domains/$domainId/View'
import { createFileRoute } from '@tanstack/react-router'
import {
  domainQueryOptions,
  domainRecordsQueryOptions,
  organizationsQueryOptions,
  DNS_RECORDS_DEFAULT_SORT_BY,
  DNS_RECORDS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { listSearchSchema, getSort, getQueryParam, queryParamToMap } from '@/lib/table-filters'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/$domainId/',
)({
  validateSearch: listSearchSchema,
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.domain?.domain ?? 'Domain', 'Domains'),
      },
    ],
  }),
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return undefined

    const { domainId } = params
    const { queryClient } = context

    if (!domainId) return undefined

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const sort = getSort(url)
    const sortBy = sort?.sortBy ?? DNS_RECORDS_DEFAULT_SORT_BY
    const sortOrder = sort?.sortOrder ?? DNS_RECORDS_DEFAULT_SORT_ORDER

    const filterMap = queryParamToMap(getQueryParam(url))
    const filterQueriesFromUrl =
      filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

    const recordsOptions = domainRecordsQueryOptions(
      domainId,
      0,
      ROWS_DEFAULT_PAGE_SIZE,
      filterQueriesFromUrl,
      sortBy,
      sortOrder,
    )

    const [domain, organizations, records] = await Promise.all([
      queryClient.ensureQueryData(domainQueryOptions(domainId)),
      queryClient.ensureQueryData(organizationsQueryOptions()),
      queryClient.ensureQueryData(recordsOptions),
    ])

    return {
      domain,
      organizations,
      records,
    }
  },
  component: DomainDetailPage,
})

function DomainDetailPage() {
  const { domainId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View
      key={`domain-${domainId}-index`}
      initialData={
        loaderData
          ? {
              domain: loaderData.domain,
              records: loaderData.records,
            }
          : undefined
      }
    />
  )
}
