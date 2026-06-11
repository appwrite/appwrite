import { parseDateOnly } from './dates'
import { resolveInitCurrentDay, resolveInitRecapMode } from './event-visibility'
import { getActiveLaunchEvent } from './events'
import {
  getInitMockDayBannerExpired,
  INIT_ORG_PROMO_BANNER_DAYS_AFTER_EVENT,
} from './mock-current-day'
import type { LaunchEvent, LaunchEventCta } from './types'

export type InitOrgPromoPhase = 'before' | 'during' | 'after'

export type InitOrgPromoBannerContent = {
  eventId: string
  phase: InitOrgPromoPhase
  dateRangeLabel: string
  message: string
  cta: LaunchEventCta
  badgeLabel?: string
}

function resolveInitOrgPromoPhase(
  event: LaunchEvent,
  now: Date,
  mockCurrentDay: number | null,
): InitOrgPromoPhase {
  if (resolveInitRecapMode(event, now, mockCurrentDay)) return 'after'
  const currentDay = resolveInitCurrentDay(event, now, mockCurrentDay)
  if (currentDay <= 0) return 'before'
  return 'during'
}

export function isInitOrgPromoBannerExpired(
  event: LaunchEvent,
  options?: { now?: Date; mockCurrentDay?: number | null },
): boolean {
  const now = options?.now ?? new Date()
  const mockCurrentDay = options?.mockCurrentDay ?? null

  if (mockCurrentDay !== null) {
    return mockCurrentDay >= getInitMockDayBannerExpired()
  }

  const end = parseDateOnly(event.endDate)
  end.setHours(23, 59, 59, 999)

  const hideAfter = new Date(end)
  hideAfter.setDate(
    hideAfter.getDate() + INIT_ORG_PROMO_BANNER_DAYS_AFTER_EVENT,
  )

  return now > hideAfter
}

export function resolveInitOrgPromoBanner(
  event: LaunchEvent | undefined,
  options?: { now?: Date; mockCurrentDay?: number | null },
): InitOrgPromoBannerContent | null {
  if (!event?.featured) return null

  const now = options?.now ?? new Date()
  const mockCurrentDay = options?.mockCurrentDay ?? null

  if (isInitOrgPromoBannerExpired(event, { now, mockCurrentDay })) {
    return null
  }

  const phase = resolveInitOrgPromoPhase(event, now, mockCurrentDay)

  switch (phase) {
    case 'before':
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        message:
          'A week of launches, live sessions, and community events. Claim your ticket to join.',
        cta: { label: event.primaryCta.label, to: '/init' },
      }
    case 'during':
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        message:
          'Launch week is live. Follow daily drops, live sessions, and giveaways.',
        cta: { label: 'Join Init', to: '/init' },
        badgeLabel: 'Live',
      }
    case 'after':
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        message:
          event.recap?.bannerMessage ??
          'Init week has ended. Explore every launch and session replay.',
        cta: { label: 'View recap', to: '/init' },
        badgeLabel: 'Recap',
      }
  }
}

export function getInitOrgPromoBannerContent(options?: {
  now?: Date
  mockCurrentDay?: number | null
}): InitOrgPromoBannerContent | null {
  return resolveInitOrgPromoBanner(getActiveLaunchEvent(options?.now), options)
}
