import { resolveInitCurrentDay, resolveInitRecapMode } from './event-visibility'
import { getActiveLaunchEvent } from './events'
import type { LaunchEvent, LaunchEventCta } from './types'

export type InitOrgPromoPhase = 'before' | 'during' | 'after'

export type InitOrgPromoBannerContent = {
  eventId: string
  phase: InitOrgPromoPhase
  dateRangeLabel: string
  headline: string
  description: string
  cta: LaunchEventCta
  badgeLabel: string
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

export function resolveInitOrgPromoBanner(
  event: LaunchEvent | undefined,
  options?: { now?: Date; mockCurrentDay?: number | null },
): InitOrgPromoBannerContent | null {
  if (!event?.featured) return null

  const now = options?.now ?? new Date()
  const mockCurrentDay = options?.mockCurrentDay ?? null
  const phase = resolveInitOrgPromoPhase(event, now, mockCurrentDay)

  switch (phase) {
    case 'before':
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        headline: event.headline,
        description:
          'A week of product launches, live sessions, and community events. Claim your ticket to join.',
        cta: event.primaryCta,
        badgeLabel: 'Coming soon',
      }
    case 'during':
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        headline: 'Init is live',
        description:
          'Join launch week - live sessions, product drops, and community giveaways.',
        cta: { label: 'Join Init', to: '/init' },
        badgeLabel: 'Live',
      }
    case 'after':
      return {
        eventId: event.id,
        phase,
        dateRangeLabel: event.dateRangeLabel,
        headline: event.recap?.headline ?? 'Init recap',
        description:
          event.recap?.bannerMessage ??
          `${event.dateRangeLabel} has ended. Catch up on every launch and session replay.`,
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
