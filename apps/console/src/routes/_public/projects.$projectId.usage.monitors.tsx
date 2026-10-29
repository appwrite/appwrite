import { useCallback, useMemo } from 'react'
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import {
  monitorsQueryOptions,
  projectQueryOptions,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { Monitors } from '@/components/pages/projects/$projectId/usage/monitors/Monitors'
import type { RuleForm } from '@/components/pages/projects/$projectId/usage/monitors/RuleFields'
import { canWriteMonitors } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { METRICS, fromBytes } from '@/lib/alerts/metrics'
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
    await queryClient.ensureQueryData(monitorsQueryOptions(projectId))
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

  const prefill = useMemo((): Partial<RuleForm> | undefined => {
    if (!search.metric || !(search.metric in METRICS)) return undefined
    const metric = search.metric as AlertMetric
    const definition = METRICS[metric]
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
      createDisabled={!canWrite}
      createDisabledTooltip={
        canWrite
          ? undefined
          : t("You don't have permission to manage monitors.")
      }
    />
  )
}
