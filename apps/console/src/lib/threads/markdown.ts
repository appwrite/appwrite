import { THREADS_DISCORD_GUILD_ID } from './constants'
import type { ThreadMentionLookup } from './types'

const DISCORD_SNOWFLAKE_PATTERN = /\d{17,20}/
const DISCORD_USER_MENTION_PATTERN = /<@!?(\d{17,20})>/g
const DISCORD_CHANNEL_MENTION_PATTERN = /<#(\d{17,20})>/g

const EMPTY_MENTION_LOOKUP: ThreadMentionLookup = {
  users: {},
  channels: {},
}

/** Discord single-line fenced snippets: ```code``` → `code` for markdown parsers. */
function normalizeDiscordInlineCodeFences(content: string): string {
  return content.replace(/```([^`\n]+?)```/g, '`$1`')
}

function normalizeLineEndings(content: string): string {
  return content.replace(/\r\n/g, '\n')
}

function escapeMarkdownLinkText(text: string): string {
  return text.replace(/[\[\]\\]/g, '\\$&')
}

export function getDiscordChannelUrl(channelId: string): string {
  return `https://discord.com/channels/${THREADS_DISCORD_GUILD_ID}/${channelId}`
}

export function getDiscordUserUrl(userId: string): string {
  return `https://discord.com/users/${userId}`
}

export function getDiscordGuildUrl(): string {
  return `https://discord.com/channels/${THREADS_DISCORD_GUILD_ID}`
}

function getDiscordEmojiUrl(id: string, animated: boolean): string {
  return `https://cdn.discordapp.com/emojis/${id}.${animated ? 'gif' : 'webp'}`
}

function formatDiscordTimestamp(unixSeconds: string): string {
  const date = new Date(Number(unixSeconds) * 1000)
  if (Number.isNaN(date.getTime())) return `<t:${unixSeconds}>`

  return date.toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  })
}

function markdownLink(label: string, href: string): string {
  return `[${escapeMarkdownLinkText(label)}](${href})`
}

function resolveUserMentionLabel(
  userId: string,
  lookup: ThreadMentionLookup,
): string {
  const name = lookup.users[userId]?.trim()
  return name ? `@${name}` : '@user'
}

function resolveChannelMentionLabel(
  channelId: string,
  lookup: ThreadMentionLookup,
): string {
  const channel = lookup.channels[channelId]
  if (channel?.title) return channel.title
  return '#channel'
}

function resolveChannelMentionHref(
  channelId: string,
  lookup: ThreadMentionLookup,
): string {
  return lookup.channels[channelId]?.href ?? getDiscordChannelUrl(channelId)
}

export function extractDiscordMentionIds(contents: string[]): {
  userIds: Set<string>
  channelIds: Set<string>
} {
  const userIds = new Set<string>()
  const channelIds = new Set<string>()

  for (const content of contents) {
    for (const match of content.matchAll(DISCORD_USER_MENTION_PATTERN)) {
      userIds.add(match[1])
    }
    for (const match of content.matchAll(DISCORD_CHANNEL_MENTION_PATTERN)) {
      channelIds.add(match[1])
    }
  }

  return { userIds, channelIds }
}

function replaceDiscordRoleMentions(content: string): string {
  return content.replace(/<@&(\d{17,20})>/g, (_, roleId) => {
    if (!DISCORD_SNOWFLAKE_PATTERN.test(roleId)) return `<@&${roleId}>`
    return markdownLink('@role', getDiscordGuildUrl())
  })
}

function replaceDiscordUserMentions(
  content: string,
  lookup: ThreadMentionLookup,
): string {
  return content.replace(DISCORD_USER_MENTION_PATTERN, (_, userId) => {
    if (!DISCORD_SNOWFLAKE_PATTERN.test(userId)) return `<@${userId}>`
    return markdownLink(
      resolveUserMentionLabel(userId, lookup),
      `/threads/authors/${userId}`,
    )
  })
}

function replaceDiscordChannelMentions(
  content: string,
  lookup: ThreadMentionLookup,
): string {
  return content.replace(DISCORD_CHANNEL_MENTION_PATTERN, (_, channelId) => {
    if (!DISCORD_SNOWFLAKE_PATTERN.test(channelId)) return `<#${channelId}>`
    return markdownLink(
      resolveChannelMentionLabel(channelId, lookup),
      resolveChannelMentionHref(channelId, lookup),
    )
  })
}

function replaceDiscordCustomEmojis(content: string): string {
  return content
    .replace(/<a:(\w+):(\d{17,20})>/g, (_, name, id) => {
      return `![${name}](${getDiscordEmojiUrl(id, true)})`
    })
    .replace(/<:(\w+):(\d{17,20})>/g, (_, name, id) => {
      return `![${name}](${getDiscordEmojiUrl(id, false)})`
    })
}

function replaceDiscordTimestamps(content: string): string {
  return content.replace(/<t:(\d{10,13})(?::[a-zA-Z])?>/g, (_, unix) => {
    const normalizedUnix =
      unix.length > 10 ? String(Math.floor(Number(unix) / 1000)) : unix
    return formatDiscordTimestamp(normalizedUnix)
  })
}

function replaceDiscordSlashCommands(content: string): string {
  return content.replace(/<\/([^:>\n]+):(\d{17,20})>/g, (_, name) => {
    return `\`/${String(name).trim()}\``
  })
}

function replaceDiscordSpoilers(content: string): string {
  return content.replace(/\|\|([^|\n]+?)\|\|/g, (_, text: string) => text)
}

function replaceDiscordMentionsForMarkdown(
  content: string,
  lookup: ThreadMentionLookup,
): string {
  let result = content
  result = replaceDiscordRoleMentions(result)
  result = replaceDiscordUserMentions(result, lookup)
  result = replaceDiscordChannelMentions(result, lookup)
  result = replaceDiscordCustomEmojis(result)
  result = replaceDiscordTimestamps(result)
  result = replaceDiscordSlashCommands(result)
  result = replaceDiscordSpoilers(result)
  return result
}

function replaceDiscordMentionsForPlainText(
  content: string,
  lookup: ThreadMentionLookup,
): string {
  let result = content
  result = result.replace(/<@&(\d{17,20})>/g, '@role')
  result = result.replace(DISCORD_USER_MENTION_PATTERN, (_, userId) =>
    resolveUserMentionLabel(userId, lookup),
  )
  result = result.replace(DISCORD_CHANNEL_MENTION_PATTERN, (_, channelId) =>
    resolveChannelMentionLabel(channelId, lookup),
  )
  result = result.replace(/<a?:(\w+):\d{17,20}>/g, ':$1:')
  result = result.replace(/<t:(\d{10,13})(?::[a-zA-Z])?>/g, (_, unix) => {
    const normalizedUnix =
      unix.length > 10 ? String(Math.floor(Number(unix) / 1000)) : unix
    return formatDiscordTimestamp(normalizedUnix)
  })
  result = result.replace(/<\/([^:>\n]+):\d{17,20}>/g, '/$1')
  result = replaceDiscordSpoilers(result)
  return result
}

export function prepareThreadMessageForMarkdown(
  content: string,
  mentionLookup: ThreadMentionLookup = EMPTY_MENTION_LOOKUP,
): string {
  let result = normalizeLineEndings(content)
  result = replaceDiscordMentionsForMarkdown(result, mentionLookup)
  result = normalizeDiscordInlineCodeFences(result)
  return result
}

export function normalizeThreadPlainText(
  content: string,
  mentionLookup: ThreadMentionLookup = EMPTY_MENTION_LOOKUP,
): string {
  let result = normalizeLineEndings(content)
  result = replaceDiscordMentionsForPlainText(result, mentionLookup)
  result = normalizeDiscordInlineCodeFences(result)
  return result
}

export function isDiscordEmojiImageUrl(src?: string): boolean {
  return Boolean(src?.includes('cdn.discordapp.com/emojis/'))
}
