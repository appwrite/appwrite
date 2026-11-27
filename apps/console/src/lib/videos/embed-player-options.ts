import { parseEmbedStartParam } from './embed-start-time'

/**
 * How the public player page plays a video, read from its query string
 * (`/player/:videoId?project=…&start=30&autoplay=1`). The same link plays
 * the same way on its own and inside an embed.
 */
export type EmbedPlayerOptions = {
  /** Seconds to start from. */
  start?: number
  /** Start playing once the stream can play. */
  autoplay?: boolean
  /** Start with the sound off. */
  muted?: boolean
  /** Play again from the start when the video ends. */
  loop?: boolean
  /** `false` hides the control bar. */
  controls?: boolean
  /** Progress and volume color, as `#rrggbb`. */
  color?: string
}

/** Options as their query parameters, in the order they are written. */
export const EMBED_PLAYER_PARAMS = [
  'start',
  'autoplay',
  'muted',
  'loop',
  'controls',
  'color',
] as const

const TRUE_VALUES = ['', '1', 'true', 'yes', 'on']
const FALSE_VALUES = ['0', 'false', 'no', 'off']
const HEX_DIGITS = '0123456789abcdef'

/** `?loop`, `?loop=1` and `?loop=true` turn a flag on; `0`, `false` turn it off. */
export function parseEmbedFlag(value: unknown): boolean | undefined {
  if (typeof value === 'boolean') return value
  if (typeof value === 'number') {
    if (value === 1) return true
    if (value === 0) return false
    return undefined
  }
  if (typeof value !== 'string') return undefined
  const normalized = value.trim().toLowerCase()
  if (TRUE_VALUES.includes(normalized)) return true
  if (FALSE_VALUES.includes(normalized)) return false
  return undefined
}

/** A hex color with or without `#`, 3 or 6 digits, as `#rrggbb`. */
export function parseEmbedColor(value: unknown): string | undefined {
  if (typeof value !== 'string' && typeof value !== 'number') return undefined
  let hex = String(value).trim().toLowerCase()
  if (hex.startsWith('#')) hex = hex.slice(1)
  if (hex.length !== 3 && hex.length !== 6) return undefined
  if (![...hex].every((digit) => HEX_DIGITS.includes(digit))) return undefined
  if (hex.length === 3) {
    hex = [...hex].map((digit) => digit + digit).join('')
  }
  return `#${hex}`
}

/** Options from parsed search params; anything unreadable is left out. */
export function parseEmbedPlayerOptions(
  search: Record<string, unknown>,
): EmbedPlayerOptions {
  const start = search.start
  return {
    start: parseEmbedStartParam(
      typeof start === 'string' || typeof start === 'number'
        ? start
        : undefined,
    ),
    autoplay: parseEmbedFlag(search.autoplay),
    muted: parseEmbedFlag(search.muted),
    loop: parseEmbedFlag(search.loop),
    controls: parseEmbedFlag(search.controls),
    color: parseEmbedColor(search.color),
  }
}

/**
 * Options from a query string as someone typed it: a whole URL, `?a=1&b=2`
 * or `a=1&b=2`.
 */
export function parseEmbedPlayerQuery(text: string): EmbedPlayerOptions {
  const trimmed = text.trim()
  const queryStart = trimmed.indexOf('?')
  const query = queryStart >= 0 ? trimmed.slice(queryStart + 1) : trimmed
  const search: Record<string, string> = {}
  for (const [key, value] of new URLSearchParams(query)) search[key] = value
  return parseEmbedPlayerOptions(search)
}

/** Query parameters for the options that differ from the player's defaults. */
export function embedPlayerSearchParams(
  options: EmbedPlayerOptions,
): Array<[string, string]> {
  const params: Array<[string, string]> = []
  const start = options.start
  if (start != null && Number.isFinite(start) && start > 0) {
    params.push(['start', String(start)])
  }
  if (options.autoplay) params.push(['autoplay', '1'])
  if (options.muted) params.push(['muted', '1'])
  if (options.loop) params.push(['loop', '1'])
  if (options.controls === false) params.push(['controls', '0'])
  const color = parseEmbedColor(options.color)
  if (color) params.push(['color', color.slice(1)])
  return params
}
