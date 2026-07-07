import {
  buildInitTicketRenderData,
  getInitTicketNumberForUser,
  type InitTicketRenderData,
} from '@/lib/init/ticket-render-data'
import {
  DEFAULT_INIT_TICKET_PREFS,
  parseInitTicketPrefs,
  type InitTicketPrefs,
} from '@/lib/init/ticket-prefs'
import { parseInitTicketStack } from '@/lib/init/ticket-stack'
import {
  isInitTicketTypeId,
  type InitTicketTypeId,
} from '@/lib/init/ticket-types'
import type { InitDisplayEvent } from '@/lib/init/types'

type InitTicketImageEvent = Pick<
  InitDisplayEvent,
  'dateRangeLabel' | 'slug' | 'tickets'
>

function parsePrefsJson(value: string | null): InitTicketPrefs | null {
  if (!value) return null
  try {
    return parseInitTicketPrefs(JSON.parse(value))
  } catch {
    return null
  }
}

function mergeTicketPrefsFromSearch(
  searchParams: URLSearchParams,
): InitTicketPrefs {
  const parsedPrefs = parsePrefsJson(searchParams.get('prefs'))
  const stackParam = searchParams.get('stack')
  const stack = stackParam
    ? parseInitTicketStack(
        stackParam
          .split(',')
          .map((item) => item.trim())
          .filter(Boolean),
      )
    : (parsedPrefs?.stack ?? DEFAULT_INIT_TICKET_PREFS.stack)
  const displayName =
    searchParams.get('name')?.trim() || parsedPrefs?.displayName
  const holderTitle =
    searchParams.get('title')?.trim() || parsedPrefs?.holderTitle

  return {
    ...DEFAULT_INIT_TICKET_PREFS,
    ...parsedPrefs,
    stack: stack.length ? stack : DEFAULT_INIT_TICKET_PREFS.stack,
    ...(displayName ? { displayName } : {}),
    ...(holderTitle ? { holderTitle } : {}),
  }
}

function parseTicketType(
  searchParams: URLSearchParams,
): InitTicketTypeId | undefined {
  const type = searchParams.get('type')
  return type && isInitTicketTypeId(type) ? type : undefined
}

function parseThemeUsesDarkImage(searchParams: URLSearchParams): boolean {
  const theme = searchParams.get('theme')?.trim().toLowerCase()
  return theme === 'dark'
}

export function buildInitTicketImageRenderData(
  event: InitTicketImageEvent,
  searchParams: URLSearchParams,
): InitTicketRenderData {
  const prefs = mergeTicketPrefsFromSearch(searchParams)
  const ticketNumber =
    searchParams.get('ticketNumber')?.trim() ||
    getInitTicketNumberForUser(searchParams.get('userId'))
  const githubUsername =
    searchParams.get('github')?.trim().replace(/^@+/, '') || undefined

  return buildInitTicketRenderData({
    event,
    prefs,
    themeUsesDarkImage: parseThemeUsesDarkImage(searchParams),
    mockTypeId: parseTicketType(searchParams),
    fallbackHolderName: 'Your name',
    githubUsername,
    ticketNumber,
  })
}

export async function renderInitTicketImagePng(
  data: InitTicketRenderData,
): Promise<Uint8Array> {
  const { renderInitTicketImageWithOg } = await import(
    '@/lib/init/og/render-init-ticket-image'
  )
  return renderInitTicketImageWithOg(data)
}

export type { InitTicketRenderData }
