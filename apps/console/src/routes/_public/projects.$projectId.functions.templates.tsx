import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/functions/templates/View'
import {
  fetchProject,
  functionTemplatesPageQueryOptions,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

function parseCsvParam(s: string | null | undefined): string[] {
  if (!s?.trim()) return []
  return s
    .split(',')
    .map((x) => x.trim())
    .filter(Boolean)
}

function parseTemplatesLimit(value: unknown, fallback: number): number {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 1) return fallback
  return Math.min(Math.max(1, Math.floor(n)), 100)
}

function parseTemplatesOffset(value: unknown): number {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 0) return 0
  return Math.min(Math.floor(n), 1_000_000_000)
}

const templatesSearchSchema = z.object({
  search: z.string().optional().catch(undefined),
  /** Legacy page number (1-based); used only if `offset` is absent. */
  page: z.coerce.number().int().min(1).optional().catch(undefined),
  offset: z.coerce.number().int().min(0).optional().catch(undefined),
  limit: z.coerce.number().int().min(1).max(100).optional().catch(undefined),
  uc: z.string().optional().catch(undefined),
  rt: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/templates',
)({
  head: () => ({
    meta: [{ title: pageTitle('Templates', 'Functions') }],
  }),
  validateSearch: templatesSearchSchema,
  loaderDeps: ({ search }) => search,
  loader: async ({ params, context, deps: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const nameSearch = (routeSearch.search ?? '').trim()

    const projectData = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    const prefetch: Promise<unknown>[] = []

    // Server-side paging when not using name search (API has no name filter).
    if (!nameSearch) {
      const limit = parseTemplatesLimit(
        routeSearch.limit,
        GRID_DEFAULT_PAGE_SIZE,
      )
      let offset = parseTemplatesOffset(routeSearch.offset)
      if (routeSearch.offset == null && routeSearch.page != null) {
        const p = routeSearch.page
        if (Number.isFinite(p) && p >= 1) {
          offset = (Math.floor(p) - 1) * limit
        }
      }
      const runtimes = parseCsvParam(routeSearch.rt)
      const useCases = parseCsvParam(routeSearch.uc)
      prefetch.push(
        queryClient.ensureQueryData(
          functionTemplatesPageQueryOptions(
            projectId,
            offset,
            limit,
            runtimes,
            useCases,
          ),
        ),
      )
      const needsFacetBootstrap =
        offset !== 0 ||
        runtimes.length > 0 ||
        useCases.length > 0 ||
        limit !== GRID_DEFAULT_PAGE_SIZE
      if (needsFacetBootstrap) {
        prefetch.push(
          queryClient.ensureQueryData(
            functionTemplatesPageQueryOptions(
              projectId,
              0,
              GRID_DEFAULT_PAGE_SIZE,
              [],
              [],
            ),
          ),
        )
      }
    }

    await Promise.all([
      ...prefetch,
      projectData?.teamId
        ? queryClient.ensureQueryData(
            organizationPlanQueryOptions(projectData.teamId),
          )
        : Promise.resolve(),
    ])
  },
  component: FunctionsTemplatesPage,
})

function FunctionsTemplatesPage() {
  const { projectId } = Route.useParams()
  return <View key={`functions-templates-${projectId}`} />
}
