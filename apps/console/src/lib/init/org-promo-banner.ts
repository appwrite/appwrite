import { getEnvProfileFeatures } from '@/lib/console-profiles'
import { loadDebugOverrides } from '@/lib/debug-overrides'
import { resolveInitCurrentDay, resolveInitRecapMode } from './event-visibility'
import { getActiveLaunchEvent } from './events'
import { resolveInitBrowseDisplayCurrentDay } from './init-event-browse'
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
  /** Launch day on air while the event is live (1-indexed). */
  day?: number
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
  options?: { currentDay?: number; preview?: boolean },
): InitOrgPromoBannerContent | null {
  // Only the featured event promotes itself, unless this browser previews one.
  if (!event || (!event.featured && !options?.preview)) return null

  const currentDay = options?.currentDay ?? getInitMockCurrentDayDefault()

  if (isInitOrgPromoBannerExpired({ currentDay })) {
    return null
  }

  const phase = resolveInitOrgPromoPhase(event, currentDay)
  const copy = event.promoBanner

  switch (phase) {
    case 'before':
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        message:
          copy?.before ??
          'A week of launches, live sessions, and community events. Claim your ticket to join.',
        cta: { label: event.primaryCta.label, to: '/init' },
      }
    case 'during': {
      const day = resolveInitCurrentDay(event, currentDay)
      // Today's launch is revealed on its own day, so it can lead the banner.
      const today = copy
        ? event.days.find((entry) => entry.day === day)?.title
        : undefined
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        message:
          today ??
          copy?.during ??
          'Launch week is live. Follow daily drops, live sessions, and giveaways.',
        cta: { label: 'Join Init', to: '/init' },
        badgeLabel: 'Live',
        day: copy ? day : undefined,
      }
    }
    case 'after':
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        message:
          copy?.after ??
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
  activeEventId?: string | null
}): InitOrgPromoBannerContent | null {
  const now = options?.now ?? new Date()
  const event = getActiveLaunchEvent(now, {
    activeEventId: options?.activeEventId,
  })
  if (!event) return null

  const currentDay = options?.currentDay ?? getInitMockCurrentDayDefault()
  return resolveInitOrgPromoBanner(event, {
    // An upcoming event previewed after the last one ended starts "before".
    currentDay: resolveInitBrowseDisplayCurrentDay(event, currentDay, now),
    preview: Boolean(options?.activeEventId),
  })
}

/** True while launch week is in progress (not before or recap/after). */
export function isInitEventDuring(options?: {
  now?: Date
  currentDay?: number
  activeEventId?: string | null
}): boolean {
  if (!getEnvProfileFeatures().init) return false

  const event = getActiveLaunchEvent(options?.now, {
    activeEventId: options?.activeEventId,
  })
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
  const { mockInitCurrentDay, mockInitActiveEventId } = loadDebugOverrides()
  return getInitOrgPromoBannerContent({
    currentDay: mockInitCurrentDay,
    activeEventId: mockInitActiveEventId,
  })
}
