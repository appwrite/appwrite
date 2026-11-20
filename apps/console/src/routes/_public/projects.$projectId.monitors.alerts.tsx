import { useCallback } from 'react'
import { createFileRoute, redirect, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import { alertsInfiniteQueryOptions } from '@/lib/react-query/hooks'
import { AlertHistory } from '@/components/pages/projects/$projectId/monitors/alerts/AlertHistory'
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
  '/_public/projects/$projectId/monitors/alerts',
)({
  validateSearch: searchSchema,
  beforeLoad: ({ params, search }) => {
    if (search.rule || search.metric) {
      const { rule, metric, resourceType, resourceId, threshold } = search
      throw redirect({
        to: '/projects/$projectId/monitors',
        params,
        search: rule
          ? { monitor: rule }
          : { metric, resourceType, resourceId, threshold },
        replace: true,
      })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Alerts', 'Monitors') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    // The layout has cached the project, so its region is set for this call.
    // The feed's first page, the "All" filter, so it renders without a skeleton.
    await context.queryClient.ensureInfiniteQueryData(
      alertsInfiniteQueryOptions(params.projectId),
    )
  },
  component: AlertsPage,
})

function AlertsPage() {
  const { projectId } = Route.useParams()
  const search = Route.useSearch()
  const navigate = useNavigate()

  // Drop the query once it has been acted on, so a refresh does not reopen it.
  const clearSearch = useCallback(() => {
    void navigate({
      to: '/projects/$projectId/monitors/alerts',
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
