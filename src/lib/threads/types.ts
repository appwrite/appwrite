import type { Models } from '@appwrite.io/console'

export interface DiscordMessage extends Pick<Models.Document, '$id'> {
  threadId: string
  author: string
  author_id?: string
  author_avatar: string
  message: string
  role?: string
  timestamp: string
  reaction_count?: number
  is_edited?: boolean
}

export interface DiscordThread extends Models.Document {
  discord_id: string
  author: string
  author_id?: string
  tags?: string[]
  author_avatar: string
  seo_description?: string
  content: string
  title: string
  search_meta?: string
  tldr: string
  vote_count: number
  message_count: number
  participant_count?: number
  last_activity?: string
  is_resolved?: boolean
}

export interface DiscordAuthor extends Models.Document {
  discord_id: string
  username: string
  display_name: string
  avatar?: string
  roles?: string[]
  joined_at?: string
  thread_count: number
  reply_count: number
  bio?: string
}

export type ThreadsListResult = {
  threads: DiscordThread[]
  hasMore: boolean
  nextCursor?: string
  total: number
}

export type ThreadsIndexLoaderData = ThreadsListResult & {
  q?: string
  tags: string[]
}

export type ThreadsAuthorLoaderData = {
  author: DiscordAuthor
  threads: DiscordThread[]
  total: number
  canonicalUrl: string
}

export type ThreadMentionChannel = {
  title: string
  href: string
}

/** Resolved Discord mention labels for a thread page. */
export type ThreadMentionLookup = {
  users: Record<string, string>
  channels: Record<string, ThreadMentionChannel>
}

export type ThreadsDetailLoaderData = {
  thread: DiscordThread
  messages: DiscordMessage[]
  related: DiscordThread[]
  canonicalUrl: string
  mentionLookup: ThreadMentionLookup
}
