import { optionalEnv, requireEnv } from '../config'

export const X_API_BASE = 'https://api.x.com/2'

export type XTweet = {
  id: string
  text: string
  created_at?: string
  public_metrics?: {
    retweet_count: number
    reply_count: number
    like_count: number
    quote_count: number
  }
}

export type XUser = {
  id: string
  username: string
  name?: string
}

type XTimelineResponse = {
  data?: XTweet[]
  meta?: { next_token?: string; result_count?: number }
  errors?: Array<{ message: string }>
}

type XUserResponse = {
  data?: XUser
  errors?: Array<{ message: string }>
}

export function getBearerToken(): string {
  return requireEnv('SOCIALS_X_BEARER_TOKEN')
}

export async function xFetch<T>(path: string, params?: Record<string, string | number>): Promise<T> {
  const url = new URL(`${X_API_BASE}${path}`)
  if (params) {
    for (const [key, value] of Object.entries(params)) {
      url.searchParams.set(key, String(value))
    }
  }

  const response = await fetch(url, {
    headers: { Authorization: `Bearer ${getBearerToken()}` },
  })

  const body = (await response.json()) as T & { errors?: Array<{ message: string }> }

  if (!response.ok) {
    const detail = body.errors?.map((error) => error.message).join('; ') || response.statusText
    throw new Error(`X API request failed (${response.status}): ${detail}`)
  }

  if (body.errors?.length) {
    throw new Error(body.errors.map((error) => error.message).join('; '))
  }

  return body
}

export async function resolveUserId(userId?: string, username?: string): Promise<string> {
  if (userId) return userId

  const resolvedUsername = username ?? optionalEnv('SOCIALS_X_USERNAME')
  if (resolvedUsername) {
    const result = await xFetch<XUserResponse>(`/users/by/username/${resolvedUsername}`, {
      'user.fields': 'username,name',
    })
    if (!result.data?.id) {
      throw new Error(`Could not resolve X user @${resolvedUsername}`)
    }
    return result.data.id
  }

  const defaultUserId = optionalEnv('SOCIALS_X_USER_ID')
  if (defaultUserId) return defaultUserId

  throw new Error(
    'Provide --user-id, --username, or set SOCIALS_X_USER_ID / SOCIALS_X_USERNAME in .env',
  )
}

export async function resolveUser(username: string): Promise<XUser> {
  const result = await xFetch<XUserResponse>(`/users/by/username/${username}`, {
    'user.fields': 'username,name',
  })
  if (!result.data?.id) {
    throw new Error(`Could not resolve X user @${username}`)
  }
  return result.data
}

export function clampTimelineLimit(limit: number): number {
  if (limit < 5) return 5
  if (limit > 100) return 100
  return limit
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

export async function fetchUserTimeline(options: {
  userId: string
  since?: Date
  until?: Date
  maxPages?: number
  excludeRetweets?: boolean
  onPage?: (page: number, count: number) => void
}): Promise<XTweet[]> {
  const {
    userId,
    since,
    until,
    maxPages = 50,
    excludeRetweets = true,
    onPage,
  } = options

  const allTweets: XTweet[] = []
  let paginationToken: string | undefined

  for (let page = 0; page < maxPages; page += 1) {
    const params: Record<string, string | number> = {
      max_results: 100,
      'tweet.fields': 'created_at,public_metrics,text',
    }

    if (since) params.start_time = since.toISOString()
    if (until) params.end_time = until.toISOString()
    if (excludeRetweets) params.exclude = 'retweets'
    if (paginationToken) params.pagination_token = paginationToken

    const result = await xFetch<XTimelineResponse>(`/users/${userId}/tweets`, params)
    const tweets = result.data ?? []

    allTweets.push(...tweets)
    onPage?.(page + 1, allTweets.length)

    paginationToken = result.meta?.next_token
    if (!paginationToken || tweets.length === 0) break

    await sleep(1100)
  }

  return allTweets
}

export async function fetchRecentPosts(userId: string, limit: number): Promise<XTweet[]> {
  const result = await xFetch<XTimelineResponse>(`/users/${userId}/tweets`, {
    max_results: clampTimelineLimit(limit),
    'tweet.fields': 'created_at,public_metrics,text',
    exclude: 'retweets',
  })

  return result.data ?? []
}

export function monthsAgo(months: number): Date {
  const date = new Date()
  date.setMonth(date.getMonth() - months)
  return date
}

export function weeksAgo(weeks: number): Date {
  const date = new Date()
  date.setDate(date.getDate() - weeks * 7)
  return date
}

export type SinceUnit = 'weeks' | 'months' | 'custom'

export type ParsedSinceRange = {
  since: Date
  label: string
  unit: SinceUnit
  amount?: number
  durationDays: number
}

function durationDaysSince(date: Date): number {
  return Math.max(1, (Date.now() - date.getTime()) / (24 * 60 * 60 * 1000))
}

function parseCountUnit(value: string, unit: 'weeks' | 'months'): ParsedSinceRange | null {
  const patterns =
    unit === 'weeks'
      ? /^(\d+)\s*(?:w|wk|week|weeks)$/i
      : /^(\d+)\s*(?:m|mo|month|months)$/i

  const match = value.match(patterns)
  if (!match) return null

  const amount = Number.parseInt(match[1], 10)
  if (!Number.isFinite(amount) || amount < 1) {
    throw new Error(
      `--since must be a positive number of ${unit} (e.g. ${unit === 'weeks' ? '4w' : '6m'})`,
    )
  }

  const since = unit === 'weeks' ? weeksAgo(amount) : monthsAgo(amount)

  return {
    since,
    label: `${amount} ${unit === 'weeks' ? 'week' : 'month'}${amount === 1 ? '' : 's'}`,
    unit,
    amount,
    durationDays: durationDaysSince(since),
  }
}

export function parseSinceArg(value: string): ParsedSinceRange {
  const trimmed = value.trim()

  const weeks = parseCountUnit(trimmed, 'weeks')
  if (weeks) return weeks

  const months = parseCountUnit(trimmed, 'months')
  if (months) return months

  if (/^\d+$/.test(trimmed)) {
    const amount = Number.parseInt(trimmed, 10)
    if (!Number.isFinite(amount) || amount < 1) {
      throw new Error('Numeric --since value must be a positive number of months')
    }
    const since = monthsAgo(amount)
    return {
      since,
      label: `${amount} month${amount === 1 ? '' : 's'}`,
      unit: 'months',
      amount,
      durationDays: durationDaysSince(since),
    }
  }

  const parsed = new Date(trimmed)
  if (Number.isNaN(parsed.getTime())) {
    throw new Error(
      '--since must be an ISO date, a number of months (e.g. 6, 6m), or weeks (e.g. 4w)',
    )
  }

  return {
    since: parsed,
    label: `since ${parsed.toISOString().slice(0, 10)}`,
    unit: 'custom',
    durationDays: durationDaysSince(parsed),
  }
}
