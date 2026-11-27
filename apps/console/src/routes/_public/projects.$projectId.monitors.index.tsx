import { useCallback, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { createFileRoute, redirect, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import {
  bucketsQueryOptions,
  databasesQueryOptions,
  dedicatedDatabasesQueryOptions,
  monitorsQueryOptions,
  organizationProbeCountQueryOptions,
  usageMonitorCountQueryOptions,
  useOrganizationPlan,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { monitorCaps } from '@/lib/alerts/plan-limits'
import { Monitors } from '@/components/pages/projects/$projectId/monitors/Monitors'
import type { RuleForm } from '@/components/pages/projects/$projectId/monitors/RuleFields'
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

export const Route = createFileRoute('/_public/projects/$projectId/monitors/')({
  validateSearch: searchSchema,
  // Alert emails and older links open a monitor with `?monitor=`; it has its own page now.
  beforeLoad: ({ params, search }) => {
    if (search.monitor) {
      throw redirect({
        to: '/projects/$projectId/monitors/$monitorId',
        params: { projectId: params.projectId, monitorId: search.monitor },
        replace: true,
      })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Monitors') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    // The layout has cached the project, so its region is set for this call.
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
  component: MonitorsPage,
})

function MonitorsPage() {
  const t = useT()
  const { projectId } = Route.useParams()
  const search = Route.useSearch()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteMonitors(access, features)
  // Usage monitors count against the project's `monitors` cap and synthetic
  // checks against the organization's `probes` cap; 0 or missing is no cap.
  // Creating stays open while either type can still be added.
  const { plan } = useOrganizationPlan(project?.teamId)
  const { data: usageCount } = useQuery(
    usageMonitorCountQueryOptions(projectId),
  )
  const { data: probeCount } = useQuery({
    ...organizationProbeCountQueryOptions(project?.teamId),
    enabled: features.billing && !!project?.teamId,
  })
  const isAtPlanLimit = monitorCaps(plan, usageCount, probeCount).allAtLimit

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
      to: '/projects/$projectId/monitors',
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
