/**
 * React Query hooks for monitors and the alerts they raise.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  parseMonitor,
  type Alert,
  type AlertMetric,
  type AlertResourceType,
  type MonitorInput,
  type ParsedMonitor,
} from '@/lib/appwrite/monitors'
import { fetchAlertSeries, hasSeries } from '@/lib/alerts/series'
import { DEFAULT_STALE_TIME } from './constants'

/** The API caps a page at 100; the tabs list them all client-side like webhooks. */
const PAGE_LIMIT = 100

export async function fetchMonitors(projectId: string) {
  if (!projectId) {
    return { monitors: [] as ParsedMonitor[], total: 0 }
  }
  const response = await sdk.forProject(projectId).monitors.list({
    queries: [Query.orderDesc('$createdAt'), Query.limit(PAGE_LIMIT)],
  })
  return {
    monitors: (response.monitors ?? []).map(parseMonitor),
    total: response.total ?? 0,
  }
}

export async function fetchMonitor(
  projectId: string,
  monitorId: string,
): Promise<ParsedMonitor> {
  return parseMonitor(
    await sdk.forProject(projectId).monitors.get({ monitorId }),
  )
}

export function monitorsQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['monitors', 'project', projectId],
    queryFn: () => fetchMonitors(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useMonitors(projectId: string | null | undefined) {
  const { data, isLoading, error, refetch } = useQuery(
    monitorsQueryOptions(projectId),
  )
  return {
    monitors: data?.monitors ?? [],
    total: data?.total ?? 0,
    isLoading,
    error,
    refetch,
  }
}

export async function fetchAlert(
  projectId: string,
  alertId: string,
): Promise<Alert> {
  return await sdk.forProject(projectId).alerts.get({ alertId })
}

/** Newest first; `status` narrows to firing or resolved alerts. */
export function alertsQueryOptions(
  projectId: string | null | undefined,
  filter: { status?: Alert['status']; monitorId?: string } = {},
) {
  return queryOptions({
    queryKey: ['alerts', 'project', projectId, filter],
    queryFn: async () => {
      const queries = [Query.orderDesc('$createdAt'), Query.limit(PAGE_LIMIT)]
      if (filter.status) queries.push(Query.equal('status', [filter.status]))
      if (filter.monitorId) {
        queries.push(Query.equal('monitorId', [filter.monitorId]))
      }
      const response = await sdk.forProject(projectId!).alerts.list({
        queries,
      })
      return { alerts: response.alerts ?? [], total: response.total ?? 0 }
    },
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

/** The value a monitor compares, bucket by bucket, over its chart's range. */
export function alertSeriesQueryOptions(
  projectId: string,
  monitor: {
    metric: AlertMetric
    resourceType: AlertResourceType
    resourceId: string
    window: number
  },
  range?: number,
) {
  const { metric, resourceType, resourceId, window } = monitor
  return queryOptions({
    queryKey: [
      'alert-series',
      projectId,
      metric,
      resourceType,
      resourceId,
      window,
      range ?? null,
    ],
    queryFn: () =>
      fetchAlertSeries(
        projectId,
        { metric, resourceType, resourceId, window },
        Date.now(),
        range,
      ),
    enabled:
      !!projectId &&
      hasSeries(metric) &&
      (resourceType === 'project' || !!resourceId),
    staleTime: 5 * 60 * 1000,
    // Usage storage can answer 503 while ClickHouse is busy; a chart is worth a retry.
    retry: 2,
    refetchOnWindowFocus: false,
  })
}

export function useCreateMonitor(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: MonitorInput) => {
      if (!projectId) throw new Error('Project ID is required')
      return parseMonitor(
        await sdk
          .forProject(projectId)
          .monitors.createUsageMonitor({ monitorId: ID.unique(), ...input }),
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['monitors', 'project', projectId],
      })
    },
  })
}

/** Sends only the fields passed in `changes`. */
export function useUpdateMonitor(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      monitorId,
      changes,
    }: {
      monitorId: string
      changes: Partial<MonitorInput>
    }) => {
      if (!projectId) throw new Error('Project ID is required')
      return parseMonitor(
        await sdk
          .forProject(projectId)
          .monitors.updateUsageMonitor({ monitorId, ...changes }),
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['monitors', 'project', projectId],
      })
    },
  })
}

export function useDeleteMonitor(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (monitorId: string) => {
      if (!projectId) throw new Error('Project ID is required')
      return await sdk.forProject(projectId).monitors.delete({ monitorId })
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['monitors', 'project', projectId],
        }),
        // Deleting a monitor resolves its open alert.
        queryClient.invalidateQueries({
          queryKey: ['alerts', 'project', projectId],
        }),
      ])
    },
  })
}

/** Budget monitors an organization's budget settings keep, highest threshold first. */
export function organizationBudgetMonitorsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['monitors', 'organization', organizationId, 'budget'],
    queryFn: async () => {
      const response = await sdk.forConsole.monitors.listForOrganization({
        organizationId: organizationId!,
        queries: [Query.equal('type', ['budget']), Query.limit(25)],
      })
      return (response.monitors ?? [])
        .map(parseMonitor)
        .filter((monitor) => monitor.type === 'budget')
        .sort((a, b) => b.threshold - a.threshold)
    },
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

/** Adds or removes a webhook on every budget monitor of an organization. */
export function useSetBudgetMonitorsWebhook(organizationId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({
      monitors,
      webhookUrl,
    }: {
      monitors: ParsedMonitor[]
      webhookUrl: string
    }) => {
      // One at a time: concurrent monitor writes contend on the same schedules rows.
      const updated: ParsedMonitor[] = []
      for (const monitor of monitors) {
        const others = monitor.channels.filter(
          (channel) => channel !== 'webhook',
        )
        updated.push(
          parseMonitor(
            await sdk.forConsole.monitors.updateBudgetMonitor({
              organizationId,
              monitorId: monitor.$id,
              channels: webhookUrl === '' ? others : [...others, 'webhook'],
              webhookUrl: webhookUrl === '' ? undefined : webhookUrl,
            }),
          ),
        )
      }
      return updated
    },
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: ['monitors', 'organization', organizationId],
      }),
  })
}
