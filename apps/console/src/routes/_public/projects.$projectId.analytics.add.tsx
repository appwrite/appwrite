import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { AnalyticsPropertyType } from '@appwrite.io/console'
import { View } from '@/components/pages/projects/$projectId/analytics/add/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'
import {
  analyticsPropertiesQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { ANALYTICS_PLATFORMS } from '@/lib/analytics-wizard/snippets'

const addPropertySearchSchema = z.object({
  step: z.enum(['configure', 'setup']).optional(),
  type: z.enum(AnalyticsPropertyType).optional().catch(undefined),
  platform: z.enum(ANALYTICS_PLATFORMS).optional().catch(undefined),
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
    const project = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })
    // The create form waits for the plan limit (useAnalyticsPropertiesPlanLimit):
    // the plan and the one-row property count, so it cannot be submitted first.
    await Promise.all([
      queryClient
        .ensureQueryData(analyticsPropertiesQueryOptions(projectId, 0, 1))
        .catch(() => undefined),
      project?.teamId
        ? queryClient
            .ensureQueryData(organizationPlanQueryOptions(project.teamId))
            .catch(() => undefined)
        : undefined,
    ])
    return undefined
  },
  component: AddPropertyPage,
})

function AddPropertyPage() {
  const { projectId } = Route.useParams()
  const search = Route.useSearch()
  return <View projectId={projectId} search={search} />
}
