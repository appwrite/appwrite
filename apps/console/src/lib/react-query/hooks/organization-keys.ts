import { useMemo } from 'react'
import { useMutation, useQuery, useQueryClient, queryOptions } from '@tanstack/react-query'
import { ID, OrganizationKeyScopes } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { LONG_STALE_TIME } from './constants'
import { mapApiKeysFromResponse } from './projects'

export async function fetchOrganizationApiKeys(organizationId: string) {
  if (!organizationId) {
    throw new Error('Organization ID is required')
  }
  return sdk.forConsole.organization(organizationId).listKeys({ total: true })
}

export async function fetchOrganizationApiKey(
  organizationId: string,
  keyId: string,
) {
  if (!organizationId || !keyId) {
    throw new Error('Organization ID and API key ID are required')
  }
  return sdk.forConsole.organization(organizationId).getKey({ keyId })
}

export function organizationApiKeysQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['apiKeys', 'organization', organizationId],
    queryFn: () => fetchOrganizationApiKeys(organizationId!),
    enabled: !!organizationId,
    staleTime: LONG_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export type OrganizationApiKeysResponseRaw = Awaited<
  ReturnType<typeof fetchOrganizationApiKeys>
>

export function useOrganizationApiKeys(
  organizationId: string | undefined,
  options?: { initialData?: OrganizationApiKeysResponseRaw | null },
) {
  const {
    data: apiKeysData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    ...organizationApiKeysQueryOptions(organizationId),
    initialData: options?.initialData ?? undefined,
    initialDataUpdatedAt: options?.initialData ? 1 : 0,
  })

  const apiKeys = useMemo(
    () => mapApiKeysFromResponse(apiKeysData ?? null),
    [apiKeysData],
  )

  return {
    apiKeys,
    isLoading,
    error,
    refetch,
  }
}

export function useCreateOrganizationApiKey(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      name,
      scopes,
      expire,
    }: {
      name: string
      scopes?: string[]
      expire?: string
    }) => {
      if (!organizationId) {
        throw new Error('Organization ID is required')
      }
      if (!name.trim()) {
        throw new Error('API key name is required')
      }
      return await sdk.forConsole.organization(organizationId).createKey({
        keyId: ID.unique(),
        name: name.trim(),
        scopes: (scopes ?? []) as OrganizationKeyScopes[],
        expire,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['apiKeys', 'organization', organizationId],
      })
    },
  })
}

export function useUpdateOrganizationApiKey(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      keyId,
      name,
      scopes,
      expire,
    }: {
      keyId: string
      name: string
      scopes?: string[]
      expire?: string
    }) => {
      if (!organizationId) {
        throw new Error('Organization ID is required')
      }
      if (!keyId) {
        throw new Error('API key ID is required')
      }
      if (!name.trim()) {
        throw new Error('API key name is required')
      }
      return await sdk.forConsole.organization(organizationId).updateKey({
        keyId,
        name: name.trim(),
        scopes: (scopes ?? []) as OrganizationKeyScopes[],
        expire,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['apiKeys', 'organization', organizationId],
      })
    },
  })
}

export function useDeleteOrganizationApiKey(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (keyId: string) => {
      if (!organizationId) {
        throw new Error('Organization ID is required')
      }
      if (!keyId) {
        throw new Error('API key ID is required')
      }
      return await sdk.forConsole.organization(organizationId).deleteKey({
        keyId,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['apiKeys', 'organization', organizationId],
        type: 'all',
      })
    },
  })
}

export function mapOrganizationApiKeysFromResponse(
  apiKeysData: { keys?: unknown[] } | null,
) {
  return mapApiKeysFromResponse(apiKeysData)
}
