import { stringifyJsonLd } from '@/lib/seo/json-ld'

/**
 * Discord component embeds: the custom link preview Discord renders when a page
 * URL is pasted into chat, read from a JSON script in the server-rendered head.
 * Open Graph tags stay the fallback whenever the payload is missing or invalid.
 *
 * https://docs.discord.com/developers/link-previews/component-embeds
 */
export const DISCORD_EMBED_SCRIPT_ID = 'discord:component-embed'

/** Discord rejects larger payloads; escape sequences count at full length. */
export const DISCORD_EMBED_MAX_BYTES = 3000

/** Discord rejects payloads with more components, the container included. */
export const DISCORD_EMBED_MAX_COMPONENTS = 40

/** Appwrite pink (#FD366E). */
export const DISCORD_EMBED_ACCENT_COLOR = 0xfd366e

export type DiscordMedia = { url: string }

export type DiscordLinkButton = {
  type: 2
  style: 5
  label: string
  url: string
}

export type DiscordActionRow = {
  type: 1
  components: DiscordLinkButton[]
}

export type DiscordTextDisplay = {
  type: 10
  content: string
}

export type DiscordThumbnail = {
  type: 11
  media: DiscordMedia
  description?: string
}

export type DiscordSection = {
  type: 9
  components: DiscordTextDisplay[]
  accessory: DiscordThumbnail | DiscordLinkButton
}

export type DiscordMediaGallery = {
  type: 12
  items: Array<{ media: DiscordMedia; description?: string }>
}

export type DiscordSeparator = {
  type: 14
  divider?: boolean
  spacing?: 1 | 2
}

export type DiscordContainer = {
  type: 17
  accent_color?: number
  components: Array<
    | DiscordActionRow
    | DiscordTextDisplay
    | DiscordSection
    | DiscordMediaGallery
    | DiscordSeparator
  >
}

export type DiscordEmbedScript = {
  id: string
  type: 'application/json'
  children: string
}

const MARKDOWN_CHARACTERS = new Set([
  '\\',
  '*',
  '_',
  '~',
  '`',
  '|',
  '[',
  ']',
  '<',
  '>',
])
const LINE_START_MARKDOWN_CHARACTERS = new Set(['#', '-', '+'])

/**
 * Make content text render literally in a Text Display. Whitespace collapses to
 * single spaces so user text cannot start headings, lists, or quotes on a new line.
 */
export function escapeDiscordMarkdown(text: string): string {
  const line = text.replace(/\s+/g, ' ').trim()
  let escaped = ''
  for (const [index, character] of Array.from(line).entries()) {
    const markdown =
      MARKDOWN_CHARACTERS.has(character) ||
      (index === 0 && LINE_START_MARKDOWN_CHARACTERS.has(character))
    escaped += markdown ? `\\${character}` : character
  }
  return escaped
}

/** Shorten plain text on a word boundary, before it is escaped. */
export function truncateDiscordText(text: string, maxLength: number): string {
  const line = text.replace(/\s+/g, ' ').trim()
  if (line.length <= maxLength) return line

  const sliced = line.slice(0, maxLength - 1)
  const lastSpace = sliced.lastIndexOf(' ')
  const cut = lastSpace > maxLength * 0.6 ? sliced.slice(0, lastSpace) : sliced
  return `${cut.trimEnd()}…`
}

/**
 * Discord timestamp markup, rendered in each reader's locale and time zone
 * (`D` long date, `R` relative time).
 */
export function getDiscordTimestamp(
  date: string,
  style: 'D' | 'R',
): string | undefined {
  const time = new Date(date).getTime()
  if (Number.isNaN(time)) return undefined
  return `<t:${Math.floor(time / 1000)}:${style}>`
}

export function getDiscordLinkButton(
  label: string,
  url: string,
): DiscordLinkButton {
  return { type: 2, style: 5, label, url }
}

function countComponents(component: {
  components?: unknown[]
  accessory?: unknown
}): number {
  const children = (component.components ?? []) as Array<{
    components?: unknown[]
    accessory?: unknown
  }>
  return (
    1 +
    (component.accessory ? 1 : 0) +
    children.reduce((total, child) => total + countComponents(child), 0)
  )
}

/**
 * Head script for a page's component embed, or `undefined` when the payload is
 * over Discord's limits so the page keeps its Open Graph preview.
 */
export function getDiscordEmbedScript(
  component: DiscordContainer,
): DiscordEmbedScript | undefined {
  if (countComponents(component) > DISCORD_EMBED_MAX_COMPONENTS)
    return undefined

  const children = stringifyJsonLd({ component })
  if (new TextEncoder().encode(children).length > DISCORD_EMBED_MAX_BYTES) {
    return undefined
  }

  return { id: DISCORD_EMBED_SCRIPT_ID, type: 'application/json', children }
}
