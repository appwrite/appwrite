import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/analytics/add/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'
import { fetchProject } from '@/lib/react-query/hooks'

const addPropertySearchSchema = z.object({
  step: z.enum(['configure', 'setup']).optional(),
  platform: z.enum(['web', 'flutter', 'rest']).optional(),
  propertyId: z.string().optional(),
  /** `platform` = choose stack; `details` = create form (deep-link to skip step 1) */
  configureStep: z.enum(['platform', 'details']).optional(),
  /** Prefill (e.g. "Add analytics" from a site's overview). */
  name: z.string().max(128).optional(),
  domain: z.string().max(253).optional(),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/analytics/add',
)({
  head: () => ({ meta: [{ title: pageTitle('Add property', 'Analytics') }] }),
  validateSearch: addPropertySearchSchema,
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().analytics) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined
    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })
    return undefined
  },
  component: AddPropertyPage,
})

function AddPropertyPage() {
  const { projectId } = Route.useParams()
  const search = Route.useSearch()
  return <View projectId={projectId} search={search} />
}
