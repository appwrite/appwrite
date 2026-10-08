import { queryOptions, useQuery } from '@tanstack/react-query'
import { Addon } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { DEFAULT_STALE_TIME } from './constants'

export async function fetchProjectAddons(
  projectId: string,
): Promise<Models.AddonList> {
  return sdk.forConsole.projects.listAddons({ projectId })
}

export async function fetchProjectAddonPrice(
  projectId: string,
  addon: string,
): Promise<Models.AddonPrice> {
  return sdk.forConsole.projects.getAddonPrice({ projectId, addon })
}

export async function fetchOrganizationAddons(
  organizationId: string,
): Promise<Models.AddonList> {
  return sdk.forConsole.organizations.listAddons({ organizationId })
}

export async function fetchOrganizationAddonPrice(
  organizationId: string,
  addon: string,
): Promise<Models.AddonPrice> {
  return sdk.forConsole.organizations.getAddonPrice({
    organizationId,
    addon: addon as Addon,
  })
}

export function projectAddonsQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['addons', 'project', projectId],
    queryFn: () => fetchProjectAddons(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function projectAddonPriceQueryOptions(
  projectId: string | null | undefined,
  addon: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['addons', 'project', 'price', projectId, addon],
    queryFn: () => fetchProjectAddonPrice(projectId!, addon!),
    enabled: !!projectId && !!addon,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && addon ? 5 * 60 * 1000 : 0,
  })
}

export function organizationAddonsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['addons', 'organization', organizationId],
    queryFn: () => fetchOrganizationAddons(organizationId!),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

export function organizationAddonPriceQueryOptions(
  organizationId: string | null | undefined,
  addon: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['addons', 'organization', 'price', organizationId, addon],
    queryFn: () => fetchOrganizationAddonPrice(organizationId!, addon!),
    enabled: !!organizationId && !!addon,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: organizationId && addon ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectAddons(projectId: string | null | undefined) {
  const query = useQuery(projectAddonsQueryOptions(projectId))
  return {
    addons: query.data?.addons ?? [],
    total: query.data?.total ?? 0,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useProjectAddonPrice(
  projectId: string | null | undefined,
  addon: string | null | undefined,
) {
  const query = useQuery(projectAddonPriceQueryOptions(projectId, addon))
  return {
    addonPrice: query.data ?? null,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useOrganizationAddons(organizationId: string | null | undefined) {
  const query = useQuery(organizationAddonsQueryOptions(organizationId))
  return {
    addons: query.data?.addons ?? [],
    total: query.data?.total ?? 0,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useOrganizationAddonPrice(
  organizationId: string | null | undefined,
  addon: string | null | undefined,
) {
  const query = useQuery(
    organizationAddonPriceQueryOptions(organizationId, addon),
  )
  return {
    addonPrice: query.data ?? null,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}
