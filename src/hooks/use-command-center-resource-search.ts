import { useMemo } from 'react'
import { useQueries } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import type { User } from '@/lib/utils/mock-data'
import {
  consoleDatabasesQueryOptions,
  usersQueryOptions,
  teamsQueryOptions,
  bucketsQueryOptions,
  functionsQueryOptions,
  sitesQueryOptions,
  messagesQueryOptions,
  topicsQueryOptions,
  providersQueryOptions,
} from '@/lib/react-query/hooks'
import {
  buildBucketHits,
  buildDatabaseHits,
  buildFunctionHits,
  buildMessageHits,
  buildProviderHits,
  buildSiteHits,
  buildTeamHits,
  buildTopicHits,
  buildUserHits,
  COMMAND_CENTER_RESOURCE_LIMIT,
  mergeScoredResourceHits,
  type ProjectResourceHit,
  type ProjectResourceKind,
} from '@/lib/command-center/resource-search'

export interface CommandCenterResourceSearchOptions {
  projectId: string | null | undefined
  query: string
  enabled: boolean
  kinds: ReadonlySet<ProjectResourceKind>
}

const RESOURCE_QUERY_ORDER: ProjectResourceKind[] = [
  'database',
  'user',
  'team',
  'bucket',
  'function',
  'site',
  'message',
  'topic',
  'provider',
]

export function useCommandCenterResourceSearch({
  projectId,
  query,
  enabled,
  kinds,
}: CommandCenterResourceSearchOptions) {
  const trimmedQuery = query.trim()
  const shouldFetch = enabled && !!projectId && trimmedQuery.length > 0

  const activeKinds = useMemo(
    () => RESOURCE_QUERY_ORDER.filter((kind) => kinds.has(kind)),
    [kinds],
  )

  const queries = useMemo(() => {
    const pid = projectId!
    const search = trimmedQuery
    const limit = COMMAND_CENTER_RESOURCE_LIMIT
    const baseEnabled = shouldFetch

    return activeKinds.map((kind) => {
      switch (kind) {
        case 'database':
          return {
            ...consoleDatabasesQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
        case 'user':
          return {
            ...usersQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
        case 'team':
          return {
            ...teamsQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
        case 'bucket':
          return {
            ...bucketsQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
        case 'function':
          return {
            ...functionsQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
        case 'site':
          return {
            ...sitesQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
        case 'message':
          return {
            ...messagesQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
        case 'topic':
          return {
            ...topicsQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
        case 'provider':
          return {
            ...providersQueryOptions(pid, 0, limit, search),
            enabled: baseEnabled,
          }
      }
    })
  }, [projectId, trimmedQuery, shouldFetch, activeKinds])

  const results = useQueries({ queries })

  const isLoading = shouldFetch && results.some((result) => result.isLoading)
  const isFetching = shouldFetch && results.some((result) => result.isFetching)

  const hits: ProjectResourceHit[] = useMemo(() => {
    if (!shouldFetch) return []

    const groups: ProjectResourceHit[][] = []

    activeKinds.forEach((kind, index) => {
      const data = results[index]?.data
      if (!data) return

      switch (kind) {
        case 'database': {
          const databases = (data as { databases?: Array<{ $id: string; name: string }> })
            .databases
          if (databases?.length) {
            groups.push(buildDatabaseHits(trimmedQuery, databases))
          }
          break
        }
        case 'user': {
          const users = (data as { users?: User[] }).users
          if (users?.length) {
            groups.push(buildUserHits(trimmedQuery, users))
          }
          break
        }
        case 'team': {
          const teams = (data as { teams?: Array<{ id: string; name: string }> }).teams
          if (teams?.length) {
            groups.push(buildTeamHits(trimmedQuery, teams))
          }
          break
        }
        case 'bucket': {
          const buckets = (data as { buckets?: Array<{ $id: string; name: string }> }).buckets
          if (buckets?.length) {
            groups.push(buildBucketHits(trimmedQuery, buckets))
          }
          break
        }
        case 'function': {
          const functions = (
            data as { functions?: Array<{ $id: string; name: string; runtime?: string }> }
          ).functions
          if (functions?.length) {
            groups.push(buildFunctionHits(trimmedQuery, functions))
          }
          break
        }
        case 'site': {
          const sites = (data as { sites?: Array<{ $id: string; name: string }> }).sites
          if (sites?.length) {
            groups.push(buildSiteHits(trimmedQuery, sites))
          }
          break
        }
        case 'message': {
          const messages = (data as { messages?: Models.Message[] }).messages
          if (messages?.length) {
            groups.push(buildMessageHits(trimmedQuery, messages))
          }
          break
        }
        case 'topic': {
          const topics = (data as { topics?: Models.Topic[] }).topics
          if (topics?.length) {
            groups.push(buildTopicHits(trimmedQuery, topics))
          }
          break
        }
        case 'provider': {
          const providers = (data as { providers?: Models.Provider[] }).providers
          if (providers?.length) {
            groups.push(buildProviderHits(trimmedQuery, providers))
          }
          break
        }
      }
    })

    return mergeScoredResourceHits(groups)
  }, [shouldFetch, activeKinds, results, trimmedQuery])

  return {
    hits,
    isLoading,
    isFetching,
  }
}
