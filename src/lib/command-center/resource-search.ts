/**
 * Project resource search helpers for the Command Center.
 *
 * Scores API resource hits with the same ranking logic as registry commands
 * and normalizes them into a shared shape for unified result lists.
 */

import type { Models } from '@appwrite.io/console'
import type { User } from '@/lib/utils/mock-data'
import { searchCommands, type ScoredCommand } from './search'
import type { CommandKind, CommandScope } from './types'

export const COMMAND_CENTER_RESOURCE_LIMIT = 15
export const COMMAND_CENTER_MAX_RESOURCE_HITS = 40

export type ProjectResourceKind =
  | 'database'
  | 'user'
  | 'team'
  | 'bucket'
  | 'function'
  | 'site'
  | 'message'
  | 'topic'
  | 'provider'

export const PROJECT_RESOURCE_KIND_LABELS: Record<ProjectResourceKind, string> =
  {
    database: 'Database',
    user: 'User',
    team: 'Team',
    bucket: 'Bucket',
    function: 'Function',
    site: 'Site',
    message: 'Message',
    topic: 'Topic',
    provider: 'Provider',
  }

/** Navigation section passed to `onNavigateToResource` or used to build hrefs. */
export type ProjectResourceSection =
  | 'databases'
  | 'auth/users'
  | 'auth/teams'
  | 'storage'
  | 'functions'
  | 'sites'
  | 'messaging/messages'
  | 'messaging/topics'
  | 'messaging/providers'

export interface ProjectResourceHit {
  id: string
  kind: ProjectResourceKind
  label: string
  description: string
  section: ProjectResourceSection
  resourceId: string
  score: number
}

const SCORE_ADAPTER = {
  kind: 'action' as CommandKind,
  scopes: ['project'] as CommandScope[],
}

export function scoreResourceFields(
  query: string,
  label: string,
  description?: string,
  keywords?: string[],
): number {
  const trimmed = query.trim()
  if (!trimmed) return 0

  const results = searchCommands(trimmed, [
    {
      id: 'resource',
      label,
      description,
      keywords,
      ...SCORE_ADAPTER,
    },
  ])

  return results[0]?.score ?? 0
}

export function rankResourceHits(
  query: string,
  hits: Omit<ProjectResourceHit, 'score'>[],
  options?: { prefiltered?: boolean },
): ProjectResourceHit[] {
  const trimmed = query.trim()
  if (!trimmed) return []
  const prefiltered = options?.prefiltered ?? false

  return hits
    .map((hit) => {
      const score = scoreResourceFields(
        trimmed,
        hit.label,
        hit.description,
        [hit.kind, hit.resourceId],
      )
      return {
        ...hit,
        score: prefiltered ? Math.max(score, 1) : score,
      }
    })
    .filter((hit) => prefiltered || hit.score > 0)
    .sort((a, b) => {
      if (b.score !== a.score) return b.score - a.score
      return a.label.localeCompare(b.label)
    })
    .slice(0, COMMAND_CENTER_MAX_RESOURCE_HITS)
}

export function getMessageSearchLabel(message: Models.Message): string {
  const data = message.data as Record<string, unknown> | undefined
  if (message.providerType === 'push' && data?.title) {
    return String(data.title)
  }
  if (message.providerType === 'sms' && data?.content) {
    return String(data.content)
  }
  if (message.providerType === 'email' && data?.subject) {
    return String(data.subject)
  }
  return message.$id
}

const PREFILTERED = { prefiltered: true } as const

export function buildDatabaseHits(
  query: string,
  databases: Array<{ $id: string; name: string }>,
): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    databases.map((db) => ({
      id: `db-${db.$id}`,
      kind: 'database' as const,
      label: db.name,
      description: db.$id,
      section: 'databases' as const,
      resourceId: db.$id,
    })),
    PREFILTERED,
  )
}

export function buildUserHits(query: string, users: User[]): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    users.map((user) => ({
      id: `user-${user.$id}`,
      kind: 'user' as const,
      label: user.name || user.email || 'Unknown user',
      description: user.email || user.$id,
      section: 'auth/users' as const,
      resourceId: user.$id,
    })),
    PREFILTERED,
  )
}

export function buildTeamHits(
  query: string,
  teams: Array<{ id: string; name: string }>,
): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    teams.map((team) => ({
      id: `team-${team.id}`,
      kind: 'team' as const,
      label: team.name,
      description: team.id,
      section: 'auth/teams' as const,
      resourceId: team.id,
    })),
    PREFILTERED,
  )
}

export function buildBucketHits(
  query: string,
  buckets: Array<{ $id: string; name: string }>,
): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    buckets.map((bucket) => ({
      id: `bucket-${bucket.$id}`,
      kind: 'bucket' as const,
      label: bucket.name,
      description: bucket.$id,
      section: 'storage' as const,
      resourceId: bucket.$id,
    })),
    PREFILTERED,
  )
}

export function buildFunctionHits(
  query: string,
  functions: Array<{ $id: string; name: string; runtime?: string }>,
): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    functions.map((fn) => ({
      id: `fn-${fn.$id}`,
      kind: 'function' as const,
      label: fn.name,
      description: fn.runtime || fn.$id,
      section: 'functions' as const,
      resourceId: fn.$id,
    })),
    PREFILTERED,
  )
}

export function buildSiteHits(
  query: string,
  sites: Array<{ $id: string; name: string }>,
): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    sites.map((site) => ({
      id: `site-${site.$id}`,
      kind: 'site' as const,
      label: site.name,
      description: site.$id,
      section: 'sites' as const,
      resourceId: site.$id,
    })),
    PREFILTERED,
  )
}

export function buildMessageHits(
  query: string,
  messages: Models.Message[],
): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    messages.map((message) => ({
      id: `message-${message.$id}`,
      kind: 'message' as const,
      label: getMessageSearchLabel(message),
      description: `${message.providerType} · ${message.$id}`,
      section: 'messaging/messages' as const,
      resourceId: message.$id,
    })),
    PREFILTERED,
  )
}

export function buildTopicHits(
  query: string,
  topics: Models.Topic[],
): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    topics.map((topic) => ({
      id: `topic-${topic.$id}`,
      kind: 'topic' as const,
      label: topic.name,
      description: topic.$id,
      section: 'messaging/topics' as const,
      resourceId: topic.$id,
    })),
    PREFILTERED,
  )
}

export function buildProviderHits(
  query: string,
  providers: Models.Provider[],
): ProjectResourceHit[] {
  return rankResourceHits(
    query,
    providers.map((provider) => ({
      id: `provider-${provider.$id}`,
      kind: 'provider' as const,
      label: provider.name,
      description: `${provider.type} · ${provider.$id}`,
      section: 'messaging/providers' as const,
      resourceId: provider.$id,
    })),
    PREFILTERED,
  )
}

export function mergeScoredResourceHits(
  groups: ProjectResourceHit[][],
): ProjectResourceHit[] {
  const byId = new Map<string, ProjectResourceHit>()
  for (const group of groups) {
    for (const hit of group) {
      const existing = byId.get(hit.id)
      if (!existing || hit.score > existing.score) {
        byId.set(hit.id, hit)
      }
    }
  }
  return Array.from(byId.values())
    .sort((a, b) => {
      if (b.score !== a.score) return b.score - a.score
      return a.label.localeCompare(b.label)
    })
    .slice(0, COMMAND_CENTER_MAX_RESOURCE_HITS)
}

export function searchCommandsWithScores(
  query: string,
  entries: Array<{
    id: string
    label: string
    description?: string
    keywords?: string[]
    kind: CommandKind
    group?: string
  }>,
): ScoredCommand[] {
  return searchCommands(query, entries.map((entry) => ({
    ...entry,
    scopes: ['project', 'organization', 'account'],
  })))
}
