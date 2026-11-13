import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/View'
import {
  sitesQueryOptions,
  siteTemplatesQueryOptions,
  vcsInstallationsQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
  SITES_DEFAULT_SORT_BY,
  SITES_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE } from '@/lib/sites/site-template-wizard'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const DEFAULT_PAGE = 1

export const Route = createFileRoute('/_public/projects/$projectId/sites/')({
  head: () => ({ meta: [{ title: pageTitle('Sites') }] }),
  validateSearch: listSearchSchema,
  loader: async ({ params, context, search: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const { search, page, limit, filterQueries, sort } = parseListSearch(
      routeSearch,
      {
        page: DEFAULT_PAGE,
        limit: GRID_DEFAULT_PAGE_SIZE,
      },
    )
    const sortBy = sort?.sortBy ?? SITES_DEFAULT_SORT_BY
    const sortOrder = sort?.sortOrder ?? SITES_DEFAULT_SORT_ORDER

    const projectData = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    const [sites] = await Promise.all([
      queryClient.ensureQueryData(
        sitesQueryOptions(
          projectId,
          page - 1,
          limit,
          search ?? undefined,
          filterQueries,
          sortBy,
          sortOrder,
        ),
      ),
      projectData?.teamId
        ? queryClient.ensureQueryData(
            organizationPlanQueryOptions(projectData.teamId),
          )
        : Promise.resolve(),
    ])

    // An empty project shows Git providers and template quick starts; load
    // them with the page.
    if (sites.total === 0 && !search && !filterQueries?.length) {
      await Promise.all([
        queryClient.prefetchQuery(vcsInstallationsQueryOptions(projectId)),
        queryClient.prefetchQuery(
          siteTemplatesQueryOptions(
            projectId,
            undefined,
            undefined,
            SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE,
          ),
        ),
      ])
    }
  },
  component: SitesIndexPage,
})

function SitesIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`sites-${projectId}-index`} />
}
