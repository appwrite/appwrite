/**
 * React Query hooks for monitors and the alerts they raise.
 */

import { useCallback, useMemo } from 'react'
import {
  infiniteQueryOptions,
  queryOptions,
  type InfiniteData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  createEscalation,
  deleteEscalation,
  listEscalations,
  listOrganizationMonitors,
  parseMonitor,
  updateEscalation,
  type Alert,
  type AlertMetric,
  type AlertResourceType,
  type EscalationInput,
  type MonitorInput,
  type MonitorType,
  type ParsedMonitor,
  type ProbeMonitorChanges,
  type ProbeMonitorInput,
} from '@/lib/appwrite/monitors'
import { PROBE_TYPES } from '@/lib/alerts/probes'
import { fetchAlertSeries, hasSeries } from '@/lib/alerts/series'
import { DEFAULT_STALE_TIME } from './constants'
import { nextCursorAfter } from './cursor'

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

/**
 * Newest first, a cursor page at a time; `status` narrows to firing or
 * resolved alerts.
 */
export function alertsInfiniteQueryOptions(
  projectId: string | null | undefined,
  filter: { status?: Alert['status']; monitorId?: string } = {},
) {
  return infiniteQueryOptions({
    queryKey: ['alerts', 'project', projectId, filter],
    queryFn: async ({ pageParam }) => {
      const queries = [Query.orderDesc('$createdAt'), Query.limit(PAGE_LIMIT)]
      if (filter.status) queries.push(Query.equal('status', [filter.status]))
      if (filter.monitorId) {
        queries.push(Query.equal('monitorId', [filter.monitorId]))
      }
      if (pageParam) queries.push(Query.cursorAfter(pageParam))
      const response = await sdk.forProject(projectId!).alerts.list({
        queries,
        total: false,
      })
      return { alerts: response.alerts ?? [] }
    },
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      nextCursorAfter(lastPage.alerts, PAGE_LIMIT),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

/**
 * Acknowledges a firing alert for the team, or undoes that. The feed's cached
 * pages take the alert as the server returns it, so its row updates in place.
 */
export function useUpdateAlert(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      alertId,
      acknowledged,
    }: {
      alertId: string
      acknowledged: boolean
    }) => {
      if (!projectId) throw new Error('Project ID is required')
      return await sdk
        .forProject(projectId)
        .alerts.update({ alertId, acknowledged })
    },
    onSuccess: (alert) => {
      queryClient.setQueriesData<InfiniteData<{ alerts: Alert[] }>>(
        { queryKey: ['alerts', 'project', projectId] },
        (current) =>
          current && {
            ...current,
            pages: current.pages.map((page) => ({
              ...page,
              alerts: page.alerts.map((entry) =>
                entry.$id === alert.$id ? alert : entry,
              ),
            })),
          },
      )
    },
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
    mutationFn: async (input: MonitorInput | ProbeMonitorInput) => {
      if (!projectId) throw new Error('Project ID is required')
      const monitors = sdk.forProject(projectId).monitors
      const monitorId = ID.unique()
      if (!('params' in input)) {
        return parseMonitor(
          await monitors.createUsageMonitor({ monitorId, ...input }),
        )
      }
      switch (input.type) {
        case 'http':
          return parseMonitor(
            await monitors.createHttpMonitor({ monitorId, ...input.params }),
          )
        case 'dns':
          return parseMonitor(
            await monitors.createDnsMonitor({ monitorId, ...input.params }),
          )
        case 'tcp':
          return parseMonitor(
            await monitors.createTcpMonitor({ monitorId, ...input.params }),
          )
        case 'smtp':
          return parseMonitor(
            await monitors.createSmtpMonitor({ monitorId, ...input.params }),
          )
      }
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['monitors', 'project', projectId],
        }),
        queryClient.invalidateQueries({
          queryKey: ['monitors', 'organization'],
        }),
      ])
    },
  })
}

/** Sends only the fields passed in `changes`. */
export function useUpdateMonitor(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      monitorId,
      type,
      changes,
    }: {
      monitorId: string
      /** Each type is updated through its own endpoint. */
      type: MonitorType
      changes: Partial<MonitorInput> | ProbeMonitorChanges
    }) => {
      if (!projectId) throw new Error('Project ID is required')
      const monitors = sdk.forProject(projectId).monitors
      const params = { monitorId, ...changes }
      const updated =
        type === 'http'
          ? monitors.updateHttpMonitor(params)
          : type === 'dns'
            ? monitors.updateDnsMonitor(params)
            : type === 'tcp'
              ? monitors.updateTcpMonitor(params)
              : type === 'smtp'
                ? monitors.updateSmtpMonitor(params)
                : monitors.updateUsageMonitor(params)
      return parseMonitor(await updated)
    },
    // Pausing and resuming flip at once in the list, and roll back if the update fails.
    onMutate: async ({ monitorId, changes }) => {
      if (changes.enabled === undefined) return undefined
      const queryKey = monitorsQueryOptions(projectId).queryKey
      await queryClient.cancelQueries({ queryKey })
      const previous = queryClient.getQueryData(queryKey)
      queryClient.setQueryData(queryKey, (current) =>
        current
          ? {
              ...current,
              monitors: current.monitors.map((monitor) =>
                monitor.$id === monitorId
                  ? { ...monitor, enabled: changes.enabled! }
                  : monitor,
              ),
            }
          : current,
      )
      return { previous }
    },
    onError: (_error, _variables, context) => {
      if (context?.previous) {
        queryClient.setQueryData(
          monitorsQueryOptions(projectId).queryKey,
          context.previous,
        )
      }
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
        queryClient.invalidateQueries({
          queryKey: ['monitors', 'organization'],
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
      const response = await listOrganizationMonitors(sdk.forConsole.client, {
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

/** How many synthetic checks the organization holds across its projects, which its plan counts. */
export function organizationProbeCountQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['monitors', 'organization', organizationId, 'probes'],
    queryFn: async () => {
      const response = await listOrganizationMonitors(sdk.forConsole.client, {
        organizationId: organizationId!,
        queries: [
          Query.equal(
            'type',
            PROBE_TYPES.map((type) => type.value),
          ),
          Query.limit(1),
        ],
      })
      return response.total ?? 0
    },
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

function organizationClient(organizationId: string | null | undefined) {
  if (!organizationId) throw new Error('Organization ID is required')
  return sdk.forConsole.organization(organizationId).client
}

async function collectPages<T extends { $id: string }>(
  fetchPage: (queries: string[]) => Promise<T[]>,
): Promise<T[]> {
  const items: T[] = []
  let cursor: string | undefined
  do {
    const queries = [Query.limit(PAGE_LIMIT)]
    if (cursor) queries.push(Query.cursorAfter(cursor))
    const page = await fetchPage(queries)
    items.push(...page)
    cursor = nextCursorAfter(page, PAGE_LIMIT)
  } while (cursor)
  return items
}

/** An organization's escalations, by name. */
export function escalationsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['escalations', organizationId],
    queryFn: () =>
      collectPages(async (queries) => {
        const response = await listEscalations(
          organizationClient(organizationId),
          { queries: [Query.orderAsc('name'), ...queries], total: false },
        )
        return response.escalations
      }),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

export function useEscalations(organizationId: string | null | undefined) {
  const { data, isLoading, error, refetch } = useQuery(
    escalationsQueryOptions(organizationId),
  )
  return { escalations: data ?? [], isLoading, error, refetch }
}

export function useCreateEscalation(organizationId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: EscalationInput) =>
      createEscalation(organizationClient(organizationId), {
        escalationId: ID.unique(),
        ...input,
      }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['escalations', organizationId],
      })
    },
  })
}

/** Sends only the fields passed in `changes`. */
export function useUpdateEscalation(organizationId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      escalationId,
      changes,
    }: {
      escalationId: string
      changes: Partial<EscalationInput>
    }) =>
      updateEscalation(
        organizationClient(organizationId),
        escalationId,
        changes,
      ),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['escalations', organizationId],
      })
    },
  })
}

/** Refused with `escalation_in_use` while a monitor uses the escalation. */
export function useDeleteEscalation(organizationId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (escalationId: string) =>
      deleteEscalation(organizationClient(organizationId), escalationId),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['escalations', organizationId],
      })
    },
  })
}

/**
 * The confirmed members an escalation step can name, and how a step names
 * one: a member who left reads as their user ID.
 */
export function useEscalationMembers(
  organizationId: string | null | undefined,
) {
  const { data, isLoading } = useQuery({
    queryKey: ['escalation-members', organizationId],
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    queryFn: () =>
      collectPages(async (queries) => {
        const response = await sdk.forConsole.teams.listMemberships({
          teamId: organizationId!,
          queries,
          total: false,
        })
        return response.memberships
      }),
  })
  const members = useMemo(
    () =>
      (data ?? [])
        .filter((member) => member.confirm && member.userId)
        .map((member) => ({
          userId: member.userId,
          name: member.userName || member.userEmail,
          email: member.userEmail,
          roles: member.roles,
        })),
    [data],
  )
  const memberName = useCallback(
    (userId: string) =>
      members.find((member) => member.userId === userId)?.name ?? userId,
    [members],
  )
  return { members, memberName, isLoading }
}
