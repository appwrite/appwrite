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
import { getAppwriteErrorInfo } from '@/lib/utils/error-formatting'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'
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
  loaderDeps: ({ search }) => search,
  loader: async ({ params, context, deps: routeSearch }) => {
    if (typeof window === 'undefined') return undefined

    const { domainId } = params
    const { queryClient } = context

    if (!domainId) return undefined

    const { filterQueries, sort } = parseListSearch(routeSearch, {
      page: 1,
      limit: ROWS_DEFAULT_PAGE_SIZE,
    })
    const sortBy = sort?.sortBy ?? DNS_RECORDS_DEFAULT_SORT_BY
    const sortOrder = sort?.sortOrder ?? DNS_RECORDS_DEFAULT_SORT_ORDER

    const recordsOptions = domainRecordsQueryOptions(
      domainId,
      0,
      ROWS_DEFAULT_PAGE_SIZE,
      filterQueries,
      sortBy,
      sortOrder,
    )

    // A missing domain renders the page's own "Domain not found" state.
    const skipMissingDomain = (error: unknown) => {
      if (getAppwriteErrorInfo(error).type === 'domain_not_found')
        return undefined
      throw error
    }

    const [domain, organizations, records] = await Promise.all([
      queryClient
        .ensureQueryData(domainQueryOptions(domainId))
        .catch(skipMissingDomain),
      queryClient.ensureQueryData(organizationsQueryOptions()),
      queryClient.ensureQueryData(recordsOptions).catch(skipMissingDomain),
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
