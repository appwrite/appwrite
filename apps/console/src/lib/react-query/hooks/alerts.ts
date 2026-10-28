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
import type { AlertRule, AlertRuleInput } from '@/lib/appwrite/alerts'
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
