import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/View'
import {
  sitesQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  listSearchSchema,
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  queryParamToMap,
} from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const DEFAULT_PAGE = 1
const SITES_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/sites/')({
  head: () => ({ meta: [{ title: pageTitle('Sites') }] }),
  validateSearch: listSearchSchema,
  pendingComponent: () => (
    <div className="flex h-full items-center justify-center">
      <div className="text-muted-foreground">Loading sites...</div>
    </div>
  ),
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const search = getSearch(url)
    const page = getPage(url, DEFAULT_PAGE)
    const limit = getLimit(url, SITES_PER_PAGE)
    const queryParam = getQueryParam(url)
    const filterMap = queryParamToMap(queryParam)
    const filterQueries =
      filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

    const projectData = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    await Promise.all([
      queryClient.ensureQueryData(
        sitesQueryOptions(projectId, page - 1, limit, search ?? undefined, filterQueries),
      ),
      projectData?.teamId
        ? queryClient.ensureQueryData(
            organizationPlanQueryOptions(projectData.teamId),
          )
        : Promise.resolve(),
    ])
  },
  component: SitesIndexPage,
})

function SitesIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`sites-${projectId}-index`} />
}
