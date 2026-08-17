import {
  Gift,
  Rocket,
  Ticket,
} from 'lucide-react'
import {
  INIT_JULY_2026_DAYS,
  INIT_JULY_2026_SCHEDULE,
} from './day-details'
import {
  INIT_JULY_2026_TICKET_CONFIG,
  INIT_SEP_2026_TICKET_CONFIG,
} from './ticket-config'
import { INIT_JULY_2026_PRIZES } from './prizes'
import { parseDateOnly } from './dates'
import { resolveInitCurrentDay } from './event-visibility'
import {
  getInitMockDayAfter,
  INIT_MOCK_DAY_BEFORE,
} from './mock-current-day'
import {
  INIT_PRIZES_SECTION_ID,
  INIT_TICKET_SECTION_ID,
} from './init-section-ids'
import type { LaunchEvent, LaunchEventStatus, LaunchEventHeaderNavCta } from './types'

/** Add new launch-week events here; the page resolves the active one automatically. */
export const LAUNCH_EVENTS: LaunchEvent[] = [
  {
    id: 'init-july-2026',
    slug: 'init-july-2026',
    name: 'init',
    dateRangeLabel: 'AUGUST 31 - SEPTEMBER 4',
    headline: 'Init is happening August 31 - September 4',
    description:
      'Init is happening August 31 - September 4. A week of exciting product launches, live sessions, and community events. Five days of launches, demos, and surprises.',
    startDate: '2026-08-31',
    endDate: '2026-09-04',
    status: 'active',
    featured: true,
    presenceEnabled: true,
    tickets: INIT_JULY_2026_TICKET_CONFIG,
    primaryCta: {
      label: 'Claim your ticket',
      to: '/sign-up',
      redirect: '/init',
    },
    headerNavCta: {
      beforeEvent: {
        label: 'Back to Appwrite',
        href: '/home',
        external: false,
      },
      duringAfterEvent: {
        label: 'Explore Appwrite',
        href: '/home',
        external: false,
      },
    },
    liveBanner: {
      title: 'Appwrite 2.0 launch',
      href: '/init/keynote',
    },
    onlineCount: 0,
    othersOnlineCount: 0,
    days: INIT_JULY_2026_DAYS,
    schedule: INIT_JULY_2026_SCHEDULE,
    onlineUsers: [],
    recentlyOnlineUsers: [],
    liveActivities: [
      {
        id: 'appwrite-2',
        title: 'Appwrite 2.0 launch',
        statusLabel: 'Live now',
        status: 'live',
      },
      {
        id: 'databases',
        title: 'PostgreSQL comes to Appwrite',
        statusLabel: 'Starting in 15m',
        status: 'upcoming',
      },
      {
        id: 'servers',
        title: 'VectorsDB, DocumentsDB & MySQL',
        statusLabel: 'Starting in 1h',
        status: 'scheduled',
      },
    ],
    giveaway: {
      title: 'Init giveaways',
      description:
        'Win an Appwrite hoodie and tee, light and dark Appwriter keyboards, a RUNTIME bottle, and more on days 1-4. Day 5 grand prize: 12 months of Claude Max 20x.',
      ctaLabel: 'View all prizes',
      ctaHref: `#${INIT_PRIZES_SECTION_ID}`,
      imageSrcLight: '/images/init/giveaway-swag-promo-light.jpg',
      imageSrcDark: '/images/init/giveaway-swag-promo.jpg',
      imageAlt:
        'Appwrite swag including a hoodie, cap, water bottle, tee, Appwriter keyboards, and a Claude Max 12-month subscription card',
    },
    prizes: INIT_JULY_2026_PRIZES,
    releaseAvailability: {
      unlockDay: 5,
      sectionTitle: 'Cloud and Self-host are live.',
      sectionDescription:
        'Every Init feature is available on Appwrite Cloud, and Community Edition ships the complete Appwrite 2.0 release for self-hosting.',
      lockedTitle: 'Cloud ships daily. Self-host unlocks soon.',
      lockedDescription:
        'Every Init feature goes live on Appwrite Cloud the same day. Community Edition unlocks with the complete release when Init week wraps up.',
      cloud: {
        title: 'Appwrite Cloud',
        availabilityLabel: 'Live each Init day',
        unlockDay: 1,
        description:
          "Each day's feature goes live on Cloud the day it drops.",
        ctaLabel: 'Get started',
        href: '/',
      },
      selfHosted: {
        title: 'Community Edition',
        availabilityLabel: 'Complete release',
        description:
          'Self-host the full Appwrite 2.0 package on your infrastructure.',
        ctaLabel: 'Installation docs',
        href: '/docs/advanced/self-hosting',
      },
    },
    getInvolved: [
      {
        id: 'ticket',
        title: 'Claim your ticket',
        description: 'Get access and unlock exclusive swag.',
        icon: Ticket,
        href: `#${INIT_TICKET_SECTION_ID}`,
      },
      {
        id: 'community',
        title: 'Join the conversation',
        description: 'Share feedback and connect with the community.',
        iconSrc: '/icons/discord-simple.svg',
        href: '/discord',
      },
      {
        id: 'swag',
        title: 'Earn launch rewards',
        description: 'Join daily sessions and enter the day 5 grand prize draw.',
        icon: Gift,
        href: `#${INIT_PRIZES_SECTION_ID}`,
      },
    ],
    recap: {
      headline: 'Init recap',
      description:
        'Five days of launches are in the books. Rewatch sessions, explore every announcement, and catch up on what you missed from Init August 31 - September 4.',
      bannerMessage:
        'Init August 31 - September 4 has ended. Browse the full recap below.',
      introTitle: 'Everything we shipped',
      introDescription:
        'From Appwrite 2.0 to Appwrite Firewall & Domains. Explore the full launch timeline, blog posts, and session replays.',
      getInvolvedSectionTitle: 'Keep exploring',
      getInvolved: [
        {
          id: 'explore',
          title: 'Explore Appwrite',
          description: 'Try the launches from Init week in your project today.',
          icon: Rocket,
          href: '/home',
        },
        {
          id: 'community',
          title: 'Join the conversation',
          description: 'Share feedback and stay connected with the Appwrite community.',
          iconSrc: '/icons/discord-simple.svg',
          href: '/discord',
        },
      ],
      ticket: {
        titleAuthenticated: 'Your Init ticket',
        titleGuest: 'View your Init ticket',
        descriptionAuthenticated:
          'Your personalized pass from Init week. Customize it, export a share video, or post it on socials.',
        descriptionGuest:
          'Sign in to view your personalized Init pass and share your experience from launch week.',
        descriptionCollapsedAuthenticated: 'Customize and share your Init pass.',
        descriptionCollapsedGuest: 'Sign in to view your Init pass.',
        shareButtonLabel: 'Share ticket',
      },
    },
  },
  {
    id: 'init-sep-2026',
    slug: 'init-sep-2026',
    name: 'init',
    dateRangeLabel: 'SEP 15 - 19',
    headline: 'Init returns Sep 15 - 19',
    description:
      'Init returns Sep 15 - 19. Another week of launches, workshops, and community celebrations.',
    startDate: '2026-09-15',
    endDate: '2026-09-19',
    status: 'upcoming',
    tickets: INIT_SEP_2026_TICKET_CONFIG,
    primaryCta: { label: 'Save the date', href: '/init', external: false },
    onlineCount: 0,
    othersOnlineCount: 0,
    days: [],
    schedule: [],
    onlineUsers: [],
    recentlyOnlineUsers: [],
    liveActivities: [],
    getInvolved: [
      {
        id: 'notify',
        title: 'Get notified',
        description: 'Be the first to know when registration opens.',
        icon: Ticket,
        href: '/init',
      },
    ],
  },
]

function deriveStatus(event: LaunchEvent, now = new Date()): LaunchEventStatus {
  if (event.status !== 'upcoming' && event.status !== 'past') {
    const start = parseDateOnly(event.startDate)
    const end = parseDateOnly(event.endDate)
    end.setHours(23, 59, 59, 999)

    if (now < start) return 'upcoming'
    if (now > end) return 'past'
    return 'active'
  }
  return event.status
}

/** Prefer a featured event, then in-range dates, then next upcoming. */
export function getActiveLaunchEvent(now = new Date()): LaunchEvent | undefined {
  const featured = LAUNCH_EVENTS.find((event) => event.featured)
  if (featured) {
    return { ...featured, status: deriveStatus(featured, now) }
  }

  const withDerivedStatus = LAUNCH_EVENTS.map((event) => ({
    ...event,
    status: deriveStatus(event, now),
  }))

  const active = withDerivedStatus.find((event) => event.status === 'active')
  if (active) return active

  const upcoming = withDerivedStatus
    .filter((event) => event.status === 'upcoming')
    .sort(
      (a, b) =>
        parseDateOnly(a.startDate).getTime() - parseDateOnly(b.startDate).getTime(),
    )
  if (upcoming[0]) return upcoming[0]

  const past = withDerivedStatus
    .filter((event) => event.status === 'past')
    .sort(
      (a, b) =>
        parseDateOnly(b.endDate).getTime() - parseDateOnly(a.endDate).getTime(),
    )
  return past[0]
}

export function getLaunchEventBySlug(slug: string): LaunchEvent | undefined {
  return LAUNCH_EVENTS.find((event) => event.slug === slug)
}

const DEFAULT_INIT_HEADER_NAV_BEFORE: LaunchEventHeaderNavCta = {
  label: 'Back to Appwrite',
  href: '/home',
  external: false,
}

const DEFAULT_INIT_HEADER_NAV_DURING_AFTER: LaunchEventHeaderNavCta = {
  label: 'Explore Appwrite',
  href: '/home',
  external: false,
}

function dayHeaderNavCtaToHeaderNav(
  dayCta: NonNullable<LaunchEvent['days'][number]['headerNavCta']>,
): LaunchEventHeaderNavCta {
  return {
    label: dayCta.label,
    href: dayCta.href,
    external: dayCta.external ?? true,
  }
}

/** Match debug “mock current day” to header CTA phase (calendar may still be before the event). */
function resolveInitHeaderNavPhase(
  event: LaunchEvent | undefined,
  calendarStatus: LaunchEventStatus,
  mockCurrentDay: number | null,
): LaunchEventStatus {
  if (mockCurrentDay === null || !event) return calendarStatus

  const maxDay = event.days.reduce((max, day) => Math.max(max, day.day), 0)
  const mockAfterDay = getInitMockDayAfter(maxDay)

  if (mockCurrentDay <= INIT_MOCK_DAY_BEFORE) return 'upcoming'
  if (mockCurrentDay >= mockAfterDay) return 'past'
  return 'active'
}

/** Header ghost button on `/init` - before event vs per-day during vs after. */
export function resolveInitHeaderNavCta(options?: {
  event?: LaunchEvent
  now?: Date
  mockCurrentDay?: number | null
}): LaunchEventHeaderNavCta {
  const now = options?.now ?? new Date()
  const activeEvent = options?.event ?? getActiveLaunchEvent(now)
  const overrides = activeEvent?.headerNavCta
  const mockCurrentDay = options?.mockCurrentDay ?? null
  const calendarStatus = activeEvent?.status ?? 'upcoming'
  const phase = resolveInitHeaderNavPhase(activeEvent, calendarStatus, mockCurrentDay)

  if (phase === 'upcoming') {
    return { ...DEFAULT_INIT_HEADER_NAV_BEFORE, ...overrides?.beforeEvent }
  }

  if (phase === 'active' && activeEvent) {
    const currentDay = resolveInitCurrentDay(activeEvent, now, mockCurrentDay)
    const day = activeEvent.days.find((entry) => entry.day === currentDay)
    if (day?.headerNavCta) {
      return dayHeaderNavCtaToHeaderNav(day.headerNavCta)
    }
  }

  return { ...DEFAULT_INIT_HEADER_NAV_DURING_AFTER, ...overrides?.duringAfterEvent }
}
