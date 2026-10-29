/**
 * React Query hooks for project alert rules.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type {
  AlertMetric,
  AlertResourceType,
  AlertRule,
  AlertRuleInput,
} from '@/lib/appwrite/alerts'
import { fetchAlertSeries, hasSeries } from '@/lib/alerts/series'
import { DEFAULT_STALE_TIME } from './constants'

/** The API caps a page at 100 rules; the tab lists them all client-side like webhooks. */
const ALERT_RULES_LIMIT = 100

export async function fetchAlertRules(projectId: string) {
  if (!projectId) {
    return { rules: [] as AlertRule[], total: 0 }
  }
  const response = await sdk.forProject(projectId).alerts.listRules({
    queries: [Query.orderDesc('$createdAt'), Query.limit(ALERT_RULES_LIMIT)],
  })
  return { rules: response.rules ?? [], total: response.total ?? 0 }
}

export async function fetchAlertRule(
  projectId: string,
  ruleId: string,
): Promise<AlertRule> {
  return await sdk.forProject(projectId).alerts.getRule({ ruleId })
}

export function alertRulesQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['alert-rules', 'project', projectId],
    queryFn: () => fetchAlertRules(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useAlertRules(projectId: string | null | undefined) {
  const { data, isLoading, error, refetch } = useQuery(
    alertRulesQueryOptions(projectId),
  )
  return {
    rules: data?.rules ?? [],
    total: data?.total ?? 0,
    isLoading,
    error,
    refetch,
  }
}

/** The value a rule compares, bucket by bucket, over its chart's range. */
export function alertSeriesQueryOptions(
  projectId: string,
  rule: {
    metric: AlertMetric
    resourceType: AlertResourceType
    resourceId: string
    window: number
  },
  range?: number,
) {
  const { metric, resourceType, resourceId, window } = rule
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

export function useCreateAlertRule(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: AlertRuleInput) => {
      if (!projectId) throw new Error('Project ID is required')
      return await sdk
        .forProject(projectId)
        .alerts.createRule({ ruleId: ID.unique(), ...input })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['alert-rules', 'project', projectId],
      })
    },
  })
}

/** Sends only the fields passed in `changes`. */
export function useUpdateAlertRule(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      ruleId,
      changes,
    }: {
      ruleId: string
      changes: Partial<AlertRuleInput>
    }) => {
      if (!projectId) throw new Error('Project ID is required')
      return await sdk
        .forProject(projectId)
        .alerts.updateRule({ ruleId, ...changes })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['alert-rules', 'project', projectId],
      })
    },
  })
}

export function useDeleteAlertRule(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (ruleId: string) => {
      if (!projectId) throw new Error('Project ID is required')
      return await sdk.forProject(projectId).alerts.deleteRule({ ruleId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['alert-rules', 'project', projectId],
      })
    },
  })
}

/** Budget alert rules an organization's budget settings keep, newest threshold first. */
export function organizationBudgetRulesQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['alert-rules', 'organization', organizationId, 'budget'],
    queryFn: async () => {
      const response = await sdk.forConsole.alerts.listOrganizationRules({
        organizationId: organizationId!,
        queries: [
          Query.equal('metric', ['billing.budgetUsed']),
          Query.limit(25),
        ],
      })
      return (response.rules ?? []).sort((a, b) => b.threshold - a.threshold)
    },
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

/** Adds or removes a webhook on every budget alert rule of an organization. */
export function useSetBudgetRulesWebhook(organizationId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({
      rules,
      webhookUrl,
    }: {
      rules: AlertRule[]
      webhookUrl: string
    }) => {
      // One at a time: concurrent rule writes contend on the same schedules rows.
      const updated: AlertRule[] = []
      for (const rule of rules) {
        const others = rule.channels.filter((channel) => channel !== 'webhook')
        updated.push(
          await sdk.forConsole.alerts.updateOrganizationRule({
            organizationId,
            ruleId: rule.$id,
            channels: webhookUrl === '' ? others : [...others, 'webhook'],
            webhookUrl: webhookUrl === '' ? undefined : webhookUrl,
          }),
        )
      }
      return updated
    },
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: ['alert-rules', 'organization', organizationId],
      }),
  })
}
