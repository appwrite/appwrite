const TIME_UNITS = [
  { seconds: 86_400, singular: 'day', plural: 'days' },
  { seconds: 3_600, singular: 'hour', plural: 'hours' },
  { seconds: 60, singular: 'minute', plural: 'minutes' },
  { seconds: 1, singular: 'second', plural: 'seconds' },
] as const

type RateLimitDimension = 'endpoint' | 'ip' | 'user' | 'email' | 'challenge'

const RATE_KEY_DIMENSIONS: Record<string, RateLimitDimension> = {
  url: 'endpoint',
  ip: 'ip',
  userId: 'user',
  email: 'email',
  challengeId: 'challenge',
}

function formatRateLimitWindow(seconds: number): string {
  for (const unit of TIME_UNITS) {
    if (seconds % unit.seconds !== 0) continue

    const count = seconds / unit.seconds
    if (count === 1) return unit.singular
    return `${count} ${unit.plural}`
  }

  const label = seconds === 1 ? 'second' : 'seconds'
  return `${seconds} ${label}`
}

export function formatRateLimit(
  limit: number,
  windowSeconds: number = 3600,
): string {
  const requestLabel = limit === 1 ? 'request' : 'requests'
  return `${limit} ${requestLabel} per ${formatRateLimitWindow(windowSeconds)}`
}

function parseRateKeyDimensions(rateKey: string): RateLimitDimension[] {
  const seen = new Set<RateLimitDimension>()
  const dimensions: RateLimitDimension[] = []

  for (const segment of rateKey.split(',')) {
    const trimmed = segment.trim()
    if (!trimmed) continue

    const colonIndex = trimmed.indexOf(':')
    const rawKey = colonIndex === -1 ? trimmed : trimmed.slice(0, colonIndex).trim()
    const dimension = RATE_KEY_DIMENSIONS[rawKey]
    if (!dimension || seen.has(dimension)) continue

    seen.add(dimension)
    dimensions.push(dimension)
  }

  return dimensions
}

function normalizeRateKeys(rateKey?: string | string[]): string[] {
  if (!rateKey) return []
  return (Array.isArray(rateKey) ? rateKey : [rateKey]).filter(Boolean)
}

function formatScopePhrase(dimensions: RateLimitDimension[]): string | null {
  const has = (dimension: RateLimitDimension) => dimensions.includes(dimension)

  if (has('endpoint') && has('ip')) {
    return 'each IP address calling this endpoint'
  }
  if (has('endpoint') && has('user')) {
    return 'each user calling this endpoint'
  }
  if (has('endpoint') && has('email')) {
    return 'each email address using this endpoint'
  }
  if (has('endpoint') && has('challenge')) {
    return 'each verification challenge on this endpoint'
  }
  if (has('ip') && has('user')) {
    return 'each user from the same IP address'
  }
  if (has('ip') && has('email')) {
    return 'each email address from the same IP address'
  }
  if (has('endpoint')) {
    return 'this endpoint'
  }
  if (has('ip')) {
    return 'each IP address'
  }
  if (has('user')) {
    return 'each user'
  }
  if (has('email')) {
    return 'each email address'
  }
  if (has('challenge')) {
    return 'each verification challenge'
  }

  return null
}

function joinNaturalList(items: string[]): string {
  if (items.length === 0) return ''
  if (items.length === 1) return items[0]!
  if (items.length === 2) return `${items[0]} and ${items[1]}`
  return `${items.slice(0, -1).join(', ')}, and ${items.at(-1)}`
}

function buildScopedRateLimitText(
  limit: number,
  windowSeconds: number,
  scopePhrase: string,
): string {
  const windowLabel = formatRateLimitWindow(windowSeconds)
  const requestLabel = limit === 1 ? 'request' : 'requests'
  return `Up to ${limit} ${requestLabel} per ${windowLabel} for ${scopePhrase}.`
}

function buildHumanRateLimitText(
  limit: number,
  windowSeconds: number = 3600,
  rateKey?: string | string[],
): string {
  const keys = normalizeRateKeys(rateKey)
  const windowLabel = formatRateLimitWindow(windowSeconds)
  const requestLabel = limit === 1 ? 'request' : 'requests'

  if (keys.length === 0) {
    return `Up to ${limit} ${requestLabel} per ${windowLabel}.`
  }

  const scopePhrases = keys
    .map(parseRateKeyDimensions)
    .map(formatScopePhrase)
    .filter((phrase): phrase is string => Boolean(phrase))

  if (scopePhrases.length === 0) {
    return `Up to ${limit} ${requestLabel} per ${windowLabel}.`
  }

  if (scopePhrases.length === 1) {
    return buildScopedRateLimitText(limit, windowSeconds, scopePhrases[0]!)
  }

  return `Up to ${limit} ${requestLabel} per ${windowLabel}, counted separately for ${joinNaturalList(scopePhrases)}.`
}

export type RateLimitDescription = {
  text: string
  /** Shown when the endpoint has a client rate limit. */
  apiKeyNote?: string
}

export const API_KEY_RATE_LIMIT_BYPASS_NOTE =
  'Server API key requests bypass this rate limit.'

export function getRateLimitDescription(
  limit: number,
  windowSeconds: number = 3600,
  rateKey?: string | string[],
): RateLimitDescription {
  return {
    text: buildHumanRateLimitText(limit, windowSeconds, rateKey),
    apiKeyNote: limit > 0 ? API_KEY_RATE_LIMIT_BYPASS_NOTE : undefined,
  }
}

export function formatRateLimitDescription(
  limit: number,
  windowSeconds: number = 3600,
  rateKey?: string | string[],
): string {
  const description = getRateLimitDescription(limit, windowSeconds, rateKey)
  const lines = [description.text]

  if (description.apiKeyNote) {
    lines.push(description.apiKeyNote)
  }

  return lines.join('\n\n')
}
