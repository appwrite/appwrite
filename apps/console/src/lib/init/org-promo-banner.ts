import { getEnvProfileFeatures } from '@/lib/console-profiles'
import { loadDebugOverrides } from '@/lib/debug-overrides'
import { resolveInitCurrentDay, resolveInitRecapMode } from './event-visibility'
import { getActiveLaunchEvent } from './events'
import {
  getInitMockCurrentDayDefault,
  getInitMockDayBannerExpired,
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
  currentDay: number,
): InitOrgPromoPhase {
  if (resolveInitRecapMode(event, currentDay)) return 'after'
  if (resolveInitCurrentDay(event, currentDay) <= 0) return 'before'
  return 'during'
}

export function isInitOrgPromoBannerExpired(options?: {
  currentDay?: number
}): boolean {
  const currentDay = options?.currentDay ?? getInitMockCurrentDayDefault()
  return currentDay >= getInitMockDayBannerExpired()
}

export function resolveInitOrgPromoBanner(
  event: LaunchEvent | undefined,
  options?: { currentDay?: number },
): InitOrgPromoBannerContent | null {
  if (!event?.featured) return null

  const currentDay = options?.currentDay ?? getInitMockCurrentDayDefault()

  if (isInitOrgPromoBannerExpired({ currentDay })) {
    return null
  }

  const phase = resolveInitOrgPromoPhase(event, currentDay)

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
  currentDay?: number
}): InitOrgPromoBannerContent | null {
  return resolveInitOrgPromoBanner(getActiveLaunchEvent(options?.now), {
    currentDay: options?.currentDay,
  })
}

/** True while launch week is in progress (not before or recap/after). */
export function isInitEventDuring(options?: {
  now?: Date
  currentDay?: number
}): boolean {
  if (!getEnvProfileFeatures().init) return false

  const event = getActiveLaunchEvent(options?.now)
  if (!event?.featured || event.days.length === 0) return false

  const phase = resolveInitOrgPromoPhase(
    event,
    options?.currentDay ?? getInitMockCurrentDayDefault(),
  )

  return phase === 'during'
}

/** Promo banner content from env profile and persisted debug day override. */
export function getStaticInitOrgPromoBannerContent(): InitOrgPromoBannerContent | null {
  if (!getEnvProfileFeatures().init) return null
  const { mockInitCurrentDay } = loadDebugOverrides()
  return getInitOrgPromoBannerContent({ currentDay: mockInitCurrentDay })
}
