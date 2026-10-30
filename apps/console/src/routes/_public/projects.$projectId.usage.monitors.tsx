import { useCallback, useMemo } from 'react'
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import {
  bucketsQueryOptions,
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  monitorsQueryOptions,
  projectQueryOptions,
  useMonitors,
  useOrganizationPlan,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { getBillingPlanResourceLimit } from '@/lib/billing/project-breakdown-resources'
import { Monitors } from '@/components/pages/projects/$projectId/usage/monitors/Monitors'
import type { RuleForm } from '@/components/pages/projects/$projectId/usage/monitors/RuleFields'
import { canWriteMonitors } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  MB_SECONDS_PER_GB_HOUR,
  METRICS,
  fromBytes,
  metricDefinition,
} from '@/lib/alerts/metrics'
import type { AlertMetric, AlertResourceType } from '@/lib/appwrite/monitors'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * `?metric=` (and optionally the resource and a threshold) opens the create form
 * filled in; `?monitor=` opens that monitor.
 */
const searchSchema = z.object({
  monitor: z.string().optional().catch(undefined),
  metric: z.string().optional().catch(undefined),
  resourceType: z.string().optional().catch(undefined),
  resourceId: z.string().optional().catch(undefined),
  threshold: z.coerce.number().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/usage/monitors',
)({
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Monitors', 'Usage') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    // Region must be cached before the project-scoped monitors call.
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const { monitors } = await queryClient.ensureQueryData(
      monitorsQueryOptions(projectId),
    )

    // Rows name the database or bucket they watch; load those names with the
    // list so the scope does not change under the reader.
    const types = new Set(monitors.map((monitor) => monitor.resourceType))
    await Promise.all([
      types.has('database') &&
        queryClient.ensureQueryData(databasesQueryOptions(projectId, 0, 100)),
      types.has('bucket') &&
        queryClient.ensureQueryData(bucketsQueryOptions(projectId, 0, 100)),
      types.has('dedicatedDatabase') &&
        queryClient.ensureQueryData(dedicatedDatabasesQueryOptions(projectId)),
    ]).catch(() => undefined)
  },
  component: UsageMonitorsPage,
})

function UsageMonitorsPage() {
  const t = useT()
  const { projectId } = Route.useParams()
  const search = Route.useSearch()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteMonitors(access, features)
  // A plan that caps monitors per project refuses the next one; 0 is no cap.
  const { plan } = useOrganizationPlan(project?.teamId)
  const { total } = useMonitors(projectId)
  const monitorsLimit = getBillingPlanResourceLimit(plan, 'monitors') ?? 0
  const isAtPlanLimit = monitorsLimit > 0 && total >= monitorsLimit

  const prefill = useMemo((): Partial<RuleForm> | undefined => {
    if (!search.metric || !(search.metric in METRICS)) return undefined
    const metric = search.metric as AlertMetric
    const definition = metricDefinition(metric)
    const resourceType = definition.resourceTypes.includes(
      search.resourceType as AlertResourceType,
    )
      ? (search.resourceType as AlertResourceType)
      : definition.resourceTypes[0]
    const fields: Partial<RuleForm> = {
      metric,
      resourceType,
      resourceId: resourceType === 'project' ? '' : (search.resourceId ?? ''),
    }
    if (search.threshold !== undefined && search.threshold >= 0) {
      if (definition.unit === 'bytes') {
        const { amount, unit } = fromBytes(search.threshold)
        fields.threshold = amount
        fields.byteUnit = unit
      } else if (definition.unit === 'gbHours') {
        fields.threshold = search.threshold / MB_SECONDS_PER_GB_HOUR
      } else {
        fields.threshold = search.threshold
      }
    }
    return fields
  }, [search])

  // Drop the query once it has been acted on, so a refresh does not reopen it.
  const clearSearch = useCallback(() => {
    void navigate({
      to: '/projects/$projectId/usage/monitors',
      params: { projectId },
      search: {},
      replace: true,
    })
  }, [navigate, projectId])

  return (
    <Monitors
      projectId={projectId}
      prefill={prefill}
      onPrefillConsumed={clearSearch}
      openMonitorId={search.monitor}
      onOpenMonitorConsumed={clearSearch}
      createDisabled={!canWrite || isAtPlanLimit}
      createDisabledTooltip={
        !canWrite
          ? t("You don't have permission to manage monitors.")
          : isAtPlanLimit
            ? t("You've reached the limit for this resource on your plan")
            : undefined
      }
    />
  )
}
