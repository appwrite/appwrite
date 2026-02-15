import { View } from '@/components/pages/organizations/$orgId/domains/$domainId/View'
import { createFileRoute } from '@tanstack/react-router'
import {
  fetchDomain,
  fetchDomainRecords,
  fetchOrganizations,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const RECORDS_PER_PAGE = 25
const STALE_TIME = 30 * 1000

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/$domainId/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.domain?.domain ?? 'Domain',
          'Domains',
        ),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { domainId } = params
    const { queryClient } = context

    if (!domainId) return undefined

    // Fetch and populate cache (same keys as hooks). Return data so View can use it for first paint (no flash).
    const [domain, organizations, records] = await Promise.all([
      queryClient.fetchQuery({
        queryKey: ['domain', domainId],
        queryFn: () => fetchDomain(domainId),
        staleTime: STALE_TIME,
      }),
      queryClient.fetchQuery({
        queryKey: ['organizations', 'console'],
        queryFn: fetchOrganizations,
        staleTime: STALE_TIME,
      }),
      queryClient.fetchQuery({
        queryKey: ['dns-records', 'domain', domainId, 0, RECORDS_PER_PAGE],
        queryFn: () => fetchDomainRecords(domainId, 0, RECORDS_PER_PAGE),
        staleTime: STALE_TIME,
      }),
    ])

    return { domain, organizations, records }
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
