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
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { fetchProjectFunctionsByIds } from './functions'
import { fetchProjectSitesByIds } from './sites'

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
  /** Rate-limit bucket key: `ip` or `userId`. */
  key?: string
  /** Rate-limit algorithm: `fixedWindow`, `slidingWindow`, or `tokenBucket`. */
  strategy?: string
  /** Token-bucket burst capacity. */
  maxBucketSize?: number
  location?: string
  statusCode?: number
  challengeType?: string
  difficulty?: number
  ttl?: number
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
  /** Rate-limit bucket key: `ip` or `userId`. */
  key?: string
  /** Rate-limit algorithm: `fixedWindow`, `slidingWindow`, or `tokenBucket`. */
  strategy?: string
  /** Token-bucket burst capacity. */
  maxBucketSize?: number
  location?: string
  statusCode?: number
  challengeType?: string
  difficulty?: number
  ttl?: number
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
  resourceType?: FirewallResourceType,
  resourceId?: string,
) {
  if (!projectId) {
    return { rules: [] as Models.WafRule[], total: 0 }
  }

  const queries = [
    Query.orderDesc('priority'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  if (resourceType) {
    queries.unshift(Query.equal('resourceType', resourceType))
  }

  const normalizedResourceId = resourceId?.trim()
  if (normalizedResourceId && resourceType && resourceType !== 'api') {
    queries.unshift(Query.equal('resourceId', normalizedResourceId))
  }

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

/** Map of resource ID → display name from functions/sites list calls. */
export type FirewallResourceNameMap = Record<string, string>

function normalizeFirewallResourceIds(resourceIds: string[]): string[] {
  return [
    ...new Set(
      resourceIds.filter((id) => typeof id === 'string' && id.trim()),
    ),
  ].sort()
}

/**
 * Resolve function/site names for firewall rule grouping.
 * Makes one list call per resource type (functions + sites) in parallel with
 * the relevant IDs. Skips a side when that ID list is empty.
 */
export async function fetchFirewallResourceNames(
  projectId: string,
  idsByType: {
    functions?: string[]
    sites?: string[]
  },
): Promise<FirewallResourceNameMap> {
  const functionIds = normalizeFirewallResourceIds(idsByType.functions ?? [])
  const siteIds = normalizeFirewallResourceIds(idsByType.sites ?? [])
  if (!projectId || (functionIds.length === 0 && siteIds.length === 0)) {
    return {}
  }

  const [functionsResult, sitesResult] = await Promise.all([
    functionIds.length > 0
      ? fetchProjectFunctionsByIds(projectId, functionIds)
      : Promise.resolve({ functions: [] as Models.Function[] }),
    siteIds.length > 0
      ? fetchProjectSitesByIds(projectId, siteIds)
      : Promise.resolve({ sites: [] as Models.Site[] }),
  ])

  const names: FirewallResourceNameMap = {}
  for (const fn of functionsResult.functions) {
    names[fn.$id] = fn.name
  }
  for (const site of sitesResult.sites) {
    names[site.$id] = site.name
  }
  return names
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
  resourceType?: FirewallResourceType,
  resourceId?: string,
) {
  const normalizedSearch = search?.trim() || undefined
  const normalizedResourceId = resourceId?.trim() || undefined
  return queryOptions({
    queryKey: [
      'firewall-rules',
      'project',
      projectId,
      page,
      limit,
      normalizedSearch,
      resourceType ?? null,
      normalizedResourceId ?? null,
    ],
    queryFn: () =>
      fetchFirewallRules(
        projectId!,
        page,
        limit,
        normalizedSearch,
        resourceType,
        normalizedResourceId,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    // Keep showing the previous list until the new search/page request finishes.
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useFirewallRules(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  resourceType?: FirewallResourceType,
  resourceId?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    firewallRulesQueryOptions(
      projectId,
      page,
      limit,
      search,
      resourceType,
      resourceId,
    ),
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

export function firewallResourceNamesQueryOptions(
  projectId: string | null | undefined,
  resourceType: Extract<FirewallResourceType, 'functions' | 'sites'> | null,
  resourceIds: string[],
) {
  const ids = normalizeFirewallResourceIds(resourceIds)
  return queryOptions({
    queryKey: [
      'firewall-resource-names',
      'project',
      projectId,
      resourceType,
      ids,
    ],
    queryFn: () =>
      fetchFirewallResourceNames(projectId!, {
        functions: resourceType === 'functions' ? ids : undefined,
        sites: resourceType === 'sites' ? ids : undefined,
      }),
    enabled: !!projectId && !!resourceType && ids.length > 0,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useFirewallResourceNames(
  projectId: string | null | undefined,
  resourceType: Extract<FirewallResourceType, 'functions' | 'sites'> | null,
  resourceIds: string[],
) {
  const { data, isLoading, isFetching } = useQuery(
    firewallResourceNamesQueryOptions(projectId, resourceType, resourceIds),
  )

  return {
    names: (data ?? {}) as FirewallResourceNameMap,
    isLoading,
    isFetching,
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
    case WafRuleAction.Challenge:
      // challengeType is optional; omit to let the API apply its default.
      return waf.createChallengeRule({
        ...base,
        challengeType: input.challengeType?.trim() || undefined,
        difficulty: input.difficulty,
        ttl: input.ttl,
      })
    case WafRuleAction.RateLimit:
      return waf.createRateLimitRule({
        ...base,
        limit: input.limit ?? 100,
        interval: input.interval ?? 60,
        key: input.key,
        strategy: input.strategy,
        // Only meaningful for tokenBucket; ignored by the API otherwise.
        maxBucketSize:
          input.strategy === 'tokenBucket' ? input.maxBucketSize : undefined,
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
    case WafRuleAction.Challenge:
      return waf.updateChallengeRule({
        ...base,
        challengeType: input.challengeType,
        difficulty: input.difficulty,
        ttl: input.ttl,
      })
    case WafRuleAction.RateLimit:
      return waf.updateRateLimitRule({
        ...base,
        limit: input.limit,
        interval: input.interval,
        key: input.key,
        // strategy is immutable after creation, so it is never sent here.
        // maxBucketSize is only forwarded for existing token-bucket rules.
        maxBucketSize:
          input.strategy === 'tokenBucket' ? input.maxBucketSize : undefined,
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

export function firewallRuleImpactQueryOptions(
  projectId: string | null | undefined,
  conditions: FirewallConditionDraft[],
  resourceType: FirewallResourceType,
  resourceId?: string,
  dateRange?: DateRange,
  chartInterval?: UsageChartInterval,
  logRetentionHours?: number,
  action?: FirewallCreatableAction,
) {
  const normalizedResourceId = resourceId?.trim() || undefined
  const conditionSnapshots = buildFirewallUsageConditionSnapshots(conditions)
  const from = dateRange?.from?.toISOString()
  const to = dateRange?.to?.toISOString()

  return queryOptions({
    queryKey: [
      'firewall-impact',
      'project',
      projectId,
      resourceType,
      normalizedResourceId ?? '',
      conditionSnapshots,
      from ?? '',
      to ?? '',
      chartInterval ?? '',
      logRetentionHours ?? null,
      action ?? '',
    ] as const,
    queryFn: ({ queryKey }) => {
      const [
        ,
        ,
        impactProjectId,
        impactResourceType,
        impactResourceId,
        impactConditions,
        impactFrom,
        impactTo,
        impactChartInterval,
        impactLogRetentionHours,
        impactAction,
      ] = queryKey

      return fetchFirewallRuleImpact(String(impactProjectId), {
        conditions: draftsFromUsageConditionSnapshots(
          impactConditions as FirewallUsageConditionSnapshot[],
        ),
        resourceType: impactResourceType as FirewallResourceType,
        resourceId: impactResourceId ? String(impactResourceId) : undefined,
        dateRange: impactFrom
          ? {
              from: new Date(String(impactFrom)),
              to: impactTo ? new Date(String(impactTo)) : undefined,
            }
          : undefined,
        chartInterval: impactChartInterval
          ? (impactChartInterval as UsageChartInterval)
          : undefined,
        logRetentionHours:
          typeof impactLogRetentionHours === 'number'
            ? impactLogRetentionHours
            : undefined,
        action: impactAction
          ? (impactAction as FirewallCreatableAction)
          : undefined,
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
  dateRange?: DateRange,
  chartInterval?: UsageChartInterval,
  logRetentionHours?: number,
  action?: FirewallCreatableAction,
) {
  const { data, isLoading, isFetching, error } = useQuery(
    firewallRuleImpactQueryOptions(
      projectId,
      conditions,
      resourceType,
      resourceId,
      dateRange,
      chartInterval,
      logRetentionHours,
      action,
    ),
  )

  return {
    impact: data as FirewallRuleImpactData | undefined,
    isLoading,
    isFetching,
    error,
  }
}
