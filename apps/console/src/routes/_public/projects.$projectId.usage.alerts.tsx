import { useCallback } from 'react'
import { createFileRoute, redirect, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import { AlertHistory } from '@/components/pages/projects/$projectId/usage/alerts/AlertHistory'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * `?alert=` opens that alert, as alert emails link to it. Links from before
 * monitors (`?rule=`, `?metric=`) belong to the Monitors page.
 */
const searchSchema = z.object({
  alert: z.string().optional().catch(undefined),
  rule: z.string().optional().catch(undefined),
  metric: z.string().optional().catch(undefined),
  resourceType: z.string().optional().catch(undefined),
  resourceId: z.string().optional().catch(undefined),
  threshold: z.coerce.number().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/usage/alerts',
)({
  validateSearch: searchSchema,
  beforeLoad: ({ params, search }) => {
    if (search.rule || search.metric) {
      const { rule, metric, resourceType, resourceId, threshold } = search
      throw redirect({
        to: '/projects/$projectId/usage/monitors',
        params,
        search: rule
          ? { monitor: rule }
          : { metric, resourceType, resourceId, threshold },
        replace: true,
      })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Alerts', 'Usage') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    // Region must be cached before the project-scoped alerts call.
    await context.queryClient.ensureQueryData(
      projectQueryOptions(params.projectId),
    )
  },
  component: UsageAlertsPage,
})

function UsageAlertsPage() {
  const { projectId } = Route.useParams()
  const search = Route.useSearch()
  const navigate = useNavigate()

  // Drop the query once it has been acted on, so a refresh does not reopen it.
  const clearSearch = useCallback(() => {
    void navigate({
      to: '/projects/$projectId/usage/alerts',
      params: { projectId },
      search: {},
      replace: true,
    })
  }, [navigate, projectId])

  return (
    <AlertHistory
      projectId={projectId}
      openAlertId={search.alert}
      onOpenAlertConsumed={clearSearch}
    />
  )
}
