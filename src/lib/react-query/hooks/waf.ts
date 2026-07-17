/**
 * React Query hooks for Firewall (WAF service)
 */

import {
  useMutation,
  useQuery,
  useQueryClient,
  queryOptions,
  keepPreviousData,
} from '@tanstack/react-query'
import { ID, Query, WafRuleAction, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Dependencies } from './dependencies'
import { DEFAULT_PAGE_SIZE, DEFAULT_STALE_TIME } from './constants'
import type { FirewallCreatableAction } from '@/lib/firewall/actions'
import {
  fetchFirewallRuleImpact,
  buildFirewallUsageConditionSnapshots,
  draftsFromUsageConditionSnapshots,
  type FirewallRuleImpactData,
  type FirewallUsageConditionSnapshot,
} from '@/lib/firewall/usage'
import type {
  FirewallConditionDraft,
  FirewallResourceType,
} from '@/lib/firewall/conditions'

export type CreateFirewallRuleInput = {
  ruleId?: string
  action: FirewallCreatableAction
  resourceType: string
  resourceId?: string
  name: string
  description?: string
  priority?: number
  enabled?: boolean
  /** Query condition strings (API expects an array). */
  conditions?: string[]
  limit?: number
  interval?: number
  location?: string
  statusCode?: number
}

export type UpdateFirewallRuleInput = {
  ruleId: string
  action: WafRuleAction | string
  resourceType?: string
  resourceId?: string
  name?: string
  description?: string
  priority?: number
  enabled?: boolean
  conditions?: string[]
  limit?: number
  interval?: number
  location?: string
  statusCode?: number
}

function conditionsPayload(conditions?: string[]) {
  if (!conditions || conditions.length === 0) return undefined
  // SDK types this as string; API expects Query string array.
  return conditions as unknown as string
}

export async function fetchFirewallRules(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId) {
    return { rules: [] as Models.WafRule[], total: 0 }
  }

  const queries = [
    Query.orderAsc('priority'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await sdk.forProject(projectId).waf.listRules({
    queries,
    search: search?.trim() || undefined,
    total: true,
  })

  return {
    rules: response.rules || [],
    total: response.total || 0,
  }
}

export async function fetchFirewallRule(
  projectId: string,
  ruleId: string,
): Promise<Models.WafRule> {
  if (!projectId || !ruleId) {
    throw new Error('Project ID and rule ID are required')
  }
  return await sdk.forProject(projectId).waf.getRule({ ruleId })
}

export function firewallRulesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const normalizedSearch = search?.trim() || undefined
  return queryOptions({
    queryKey: [
      'firewall-rules',
      'project',
      projectId,
      page,
      limit,
      normalizedSearch,
    ],
    queryFn: () =>
      fetchFirewallRules(projectId!, page, limit, normalizedSearch),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useFirewallRules(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    firewallRulesQueryOptions(projectId, page, limit, search),
  )

  return {
    rules: data?.rules || [],
    total: data?.total || 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

async function createFirewallRule(
  projectId: string,
  input: CreateFirewallRuleInput,
) {
  const waf = sdk.forProject(projectId).waf
  const ruleId = input.ruleId?.trim() || ID.unique()
  const base = {
    ruleId,
    resourceType: input.resourceType,
    name: input.name,
    resourceId: input.resourceId?.trim() || undefined,
    description: input.description?.trim() || undefined,
    priority: input.priority,
    enabled: input.enabled ?? true,
    conditions: conditionsPayload(input.conditions),
  }

  switch (input.action) {
    case WafRuleAction.Bypass:
      return waf.createBypassRule(base)
    case WafRuleAction.Deny:
      return waf.createDenyRule(base)
    case WafRuleAction.RateLimit:
      return waf.createRateLimitRule({
        ...base,
        limit: input.limit ?? 100,
        interval: input.interval ?? 60,
      })
    case WafRuleAction.Redirect:
      return waf.createRedirectRule({
        ...base,
        location: input.location?.trim() || '/',
        statusCode: input.statusCode ?? 302,
      })
    default:
      throw new Error(`Unsupported firewall action: ${input.action}`)
  }
}

async function updateFirewallRule(
  projectId: string,
  input: UpdateFirewallRuleInput,
) {
  const waf = sdk.forProject(projectId).waf
  const base = {
    ruleId: input.ruleId,
    resourceType: input.resourceType,
    resourceId: input.resourceId,
    name: input.name,
    description: input.description,
    priority: input.priority,
    enabled: input.enabled,
    conditions: conditionsPayload(input.conditions),
  }

  switch (input.action) {
    case WafRuleAction.Bypass:
      return waf.updateBypassRule(base)
    case WafRuleAction.Deny:
      return waf.updateDenyRule(base)
    case WafRuleAction.RateLimit:
      return waf.updateRateLimitRule({
        ...base,
        limit: input.limit,
        interval: input.interval,
      })
    case WafRuleAction.Redirect:
      return waf.updateRedirectRule({
        ...base,
        location: input.location,
        statusCode: input.statusCode,
      })
    default:
      throw new Error(`Unsupported firewall action: ${input.action}`)
  }
}

export function useCreateFirewallRule(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: CreateFirewallRuleInput) => {
      if (!projectId) throw new Error('Project ID is required')
      return createFirewallRule(projectId, input)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['firewall-rules', 'project', projectId],
      })
      queryClient.invalidateQueries({ queryKey: Dependencies.FIREWALL_RULES })
    },
  })
}

export function useUpdateFirewallRule(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: UpdateFirewallRuleInput) => {
      if (!projectId) throw new Error('Project ID is required')
      return updateFirewallRule(projectId, input)
    },
    onSuccess: async (_data, variables) => {
      await queryClient.refetchQueries({
        queryKey: ['firewall-rules', 'project', projectId],
      })
      queryClient.invalidateQueries({ queryKey: Dependencies.FIREWALL_RULES })
      queryClient.invalidateQueries({
        queryKey: ['firewall-rule', 'project', projectId, variables.ruleId],
      })
    },
  })
}

export function useDeleteFirewallRule(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (ruleId: string) => {
      if (!projectId) throw new Error('Project ID is required')
      return await sdk.forProject(projectId).waf.deleteRule({ ruleId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['firewall-rules', 'project', projectId],
      })
      queryClient.invalidateQueries({ queryKey: Dependencies.FIREWALL_RULES })
    },
  })
}

export function useUpdateProjectFirewall(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId) throw new Error('Project ID is required')
      return await sdk.forProject(projectId).project.updateWaf({ enabled })
    },
    onSuccess: async (project) => {
      queryClient.setQueryData(['project', projectId], project)
      await queryClient.refetchQueries({
        queryKey: [Dependencies.PROJECT, projectId],
        exact: true,
        type: 'all',
      })
    },
  })
}

export function firewallRuleImpactQueryOptions(
  projectId: string | null | undefined,
  conditions: FirewallConditionDraft[],
  resourceType: FirewallResourceType,
  resourceId?: string,
) {
  const normalizedResourceId = resourceId?.trim() || undefined
  const conditionSnapshots = buildFirewallUsageConditionSnapshots(conditions)

  return queryOptions({
    queryKey: [
      'firewall-impact',
      'project',
      projectId,
      resourceType,
      normalizedResourceId ?? '',
      conditionSnapshots,
    ] as const,
    queryFn: ({ queryKey }) => {
      const [
        ,
        ,
        impactProjectId,
        impactResourceType,
        impactResourceId,
        impactConditions,
      ] = queryKey

      return fetchFirewallRuleImpact(String(impactProjectId), {
        conditions: draftsFromUsageConditionSnapshots(
          impactConditions as FirewallUsageConditionSnapshot[],
        ),
        resourceType: impactResourceType as FirewallResourceType,
        resourceId: impactResourceId ? String(impactResourceId) : undefined,
      })
    },
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useFirewallRuleImpact(
  projectId: string | null | undefined,
  conditions: FirewallConditionDraft[],
  resourceType: FirewallResourceType,
  resourceId?: string,
) {
  const { data, isLoading, isFetching, error } = useQuery(
    firewallRuleImpactQueryOptions(
      projectId,
      conditions,
      resourceType,
      resourceId,
    ),
  )

  return {
    impact: data as FirewallRuleImpactData | undefined,
    isLoading,
    isFetching,
    error,
  }
}
