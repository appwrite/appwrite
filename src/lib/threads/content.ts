import { Query } from '@appwrite.io/console'
import { getThreadsAppwriteConfig, isThreadsConfigured } from './config'
import { getThreadsDatabases } from './client'
import { extractDiscordMentionIds, normalizeThreadPlainText } from './markdown'
import { THREADS_DISCORD_GUILD_ID, THREADS_PAGE_SIZE } from './constants'
import type {
  DiscordAuthor,
  DiscordMessage,
  DiscordThread,
  ThreadMentionLookup,
  ThreadsListResult,
} from './types'

type Ranked<T> = {
  data: T
  rank: number
}

type FilterThreadsArgs = {
  threads: DiscordThread[]
  q?: string | null
  tags?: string[]
  allTags?: boolean
}

export function sanitizeThreadContent(
  rawContent: string,
  maxLength: number = 200,
  mentionLookup?: ThreadMentionLookup,
): string {
  const cleaned = normalizeThreadPlainText(rawContent, mentionLookup).replace(
    /```(?:\w+)?\n([\s\S]*?)```|```([\s\S]*?)```/g,
    (_, withLang, withoutLang) => (withLang || withoutLang).trim(),
  )

  return cleaned.length > maxLength
    ? `${cleaned.slice(0, maxLength)}...`
    : cleaned
}

export function filterThreads({
  q,
  threads: threadDocs,
  tags,
  allTags,
}: FilterThreadsArgs): DiscordThread[] {
  const threads = tags?.length
    ? threadDocs.filter((thread) => {
        const threadTags = thread.tags ?? []
        if (allTags) {
          return tags.every((tag) => threadTags.includes(tag))
        }
        return tags.some((tag) => threadTags.includes(tag))
      })
    : threadDocs

  if (!q) return threads

  const queryWords = q.toLowerCase().split(/\s+/).filter(Boolean)
  const rankPerWord = 1 / queryWords.length
  const res: Ranked<DiscordThread>[] = []

  threads.forEach((item) => {
    const foundWords = new Set<string>()

    Object.values(item).forEach((value) => {
      const stringified = JSON.stringify(value).toLowerCase()

      queryWords.forEach((word) => {
        if (stringified.includes(word)) {
          foundWords.add(word)
        }
      })
    })

    const rank = foundWords.size * rankPerWord

    if (rank > 0) {
      res.push({ data: item, rank })
    }
  })

  return res.sort((a, b) => b.rank - a.rank).map(({ data }) => data)
}

type GetThreadsArgs = {
  q?: string | null
  tags?: string[]
  allTags?: boolean
  cursor?: string
}

export async function getThreads({
  q,
  tags,
  allTags,
  cursor,
}: GetThreadsArgs): Promise<ThreadsListResult> {
  const config = getThreadsAppwriteConfig()
  const databases = getThreadsDatabases()

  const normalizedTags = tags?.filter(Boolean) ?? []

  let query = [
    q ? Query.search('search_meta', q) : undefined,
    Query.orderDesc('$createdAt'),
    Query.limit(THREADS_PAGE_SIZE),
    cursor ? Query.cursorAfter(cursor) : undefined,
    normalizedTags.length > 0 ? Query.contains('tags', normalizedTags) : undefined,
  ].filter(Boolean) as string[]

  const data = await databases.listDocuments(
    config.databaseId,
    config.threadsCollectionId,
    query,
  )

  const threadDocs = data.documents as unknown as DiscordThread[]
  const filtered = filterThreads({
    threads: threadDocs,
    q,
    tags: normalizedTags,
    allTags,
  })
  const hasMore = data.documents.length === THREADS_PAGE_SIZE
  const nextCursor = hasMore
    ? data.documents[data.documents.length - 1]?.$id
    : undefined

  return {
    threads: filtered,
    hasMore,
    nextCursor,
    total: data.total,
  }
}

export async function getAuthor(discordId: string): Promise<DiscordAuthor> {
  const config = getThreadsAppwriteConfig()
  const databases = getThreadsDatabases()

  return (await databases.getDocument(
    config.databaseId,
    config.authorsCollectionId,
    discordId,
  )) as unknown as DiscordAuthor
}

export async function getAuthorThreads(authorId: string) {
  const config = getThreadsAppwriteConfig()
  const databases = getThreadsDatabases()

  const data = await databases.listDocuments(
    config.databaseId,
    config.threadsCollectionId,
    [
      Query.equal('author_id', authorId),
      Query.orderDesc('$createdAt'),
      Query.limit(THREADS_PAGE_SIZE),
    ],
  )

  return {
    threads: data.documents as unknown as DiscordThread[],
    total: data.total,
  }
}

export async function getThread(threadId: string): Promise<DiscordThread> {
  const config = getThreadsAppwriteConfig()
  const databases = getThreadsDatabases()

  return (await databases.getDocument(
    config.databaseId,
    config.threadsCollectionId,
    threadId,
  )) as unknown as DiscordThread
}

function seedMentionLookupFromMessages(
  messages: DiscordMessage[],
): ThreadMentionLookup {
  const users: Record<string, string> = {}

  for (const message of messages) {
    if (message.author_id && message.author) {
      users[message.author_id] = message.author
    }
  }

  return { users, channels: {} }
}

export async function resolveThreadMentionLookup(
  contents: string[],
  messages: DiscordMessage[] = [],
): Promise<ThreadMentionLookup> {
  const lookup = seedMentionLookupFromMessages(messages)
  const { userIds, channelIds } = extractDiscordMentionIds(contents)

  const missingAuthorIds = [...userIds].filter((id) => !lookup.users[id])
  await Promise.all(
    missingAuthorIds.map(async (authorId) => {
      try {
        const author = await getAuthor(authorId)
        lookup.users[authorId] = author.display_name || author.username
      } catch {
        // Author may not exist in the synced authors collection.
      }
    }),
  )

  await Promise.all(
    [...channelIds].map(async (channelId) => {
      if (lookup.channels[channelId]) return

      try {
        const thread = await getThread(channelId)
        lookup.channels[channelId] = {
          title: thread.title,
          href: getThreadHref(thread),
        }
      } catch {
        // Channel id is a Discord channel, not a mirrored forum thread.
      }
    }),
  )

  return lookup
}

export async function getThreadMessages(
  threadId: string,
): Promise<DiscordMessage[]> {
  const config = getThreadsAppwriteConfig()
  const databases = getThreadsDatabases()

  const data = await databases.listDocuments(
    config.databaseId,
    config.messagesCollectionId,
    [Query.equal('threadId', threadId)],
  )

  return (data.documents as unknown as DiscordMessage[]).sort(
    (a, b) => new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime(),
  )
}

export async function getRelatedThreads(
  thread: DiscordThread,
  limit: number = 3,
): Promise<DiscordThread[]> {
  const tags = thread.tags?.filter(Boolean) ?? []
  const { threads } = await getThreads({ tags, allTags: false })
  return threads.filter(({ $id }) => $id !== thread.$id).slice(0, limit)
}

export async function* iterateAllThreads(total?: number) {
  if (!isThreadsConfigured()) return

  const config = getThreadsAppwriteConfig()
  const databases = getThreadsDatabases()
  const limit = 100
  let cursor: string | undefined
  let count = 0

  while (true) {
    const queries = [Query.limit(limit)]
    if (cursor) {
      queries.push(Query.cursorAfter(cursor))
    }

    const data = await databases.listDocuments(
      config.databaseId,
      config.threadsCollectionId,
      queries,
    )

    if (data.documents.length === 0) break

    for (const thread of data.documents as unknown as DiscordThread[]) {
      yield thread
      count++
      if (total !== undefined && count >= total) return
    }

    cursor = data.documents[data.documents.length - 1]?.$id
  }
}

export async function getAllThreadIds(): Promise<string[]> {
  const ids: string[] = []
  for await (const thread of iterateAllThreads()) {
    ids.push(thread.$id)
  }
  return ids
}

export function getDiscordThreadUrl(discordId: string): string {
  return `https://discord.com/channels/${THREADS_DISCORD_GUILD_ID}/${discordId}`
}

export function isThreadResolved(thread: DiscordThread): boolean {
  return (
    Boolean(thread.is_resolved) ||
    /\[(solved|resolved|closed|fixed)\]/i.test(thread.title)
  )
}

export function getThreadHref(thread: DiscordThread): string {
  return `/threads/${thread.discord_id}`
}

export function getAuthorHref(authorId: string): string {
  return `/threads/authors/${authorId}`
}

export function formatThreadsTotal(total: number): string {
  if (total >= 1000) {
    return `${Math.floor(total / 1000)}000+`
  }
  return String(total)
}

export function getAuthorDescription(author: DiscordAuthor): string {
  return (
    author.bio ??
    `${author.display_name} has posted ${author.thread_count} threads and ${author.reply_count} replies on the Appwrite Discord community.`
  )
}

export function cleanThreadRoleLabel(role: string | undefined): string {
  if (!role) return ''

  return role
    .replace(/<a?:[^:>\s]+:\d+>/g, '')
    .replace(/[\p{Extended_Pictographic}\p{Emoji_Presentation}\uFE0F\u200D]/gu, '')
    .replace(/\s+/g, ' ')
    .trim()
}
