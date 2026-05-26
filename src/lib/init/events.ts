import {
  Gift,
  MessageCircle,
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
import { parseDateOnly } from './dates'
import type { LaunchEvent, LaunchEventStatus, LaunchEventHeaderNavCta } from './types'

/** Add new launch-week events here; the page resolves the active one automatically. */
export const LAUNCH_EVENTS: LaunchEvent[] = [
  {
    id: 'init-july-2026',
    slug: 'init-july-2026',
    name: 'init',
    dateRangeLabel: 'JULY 6 - 10',
    headline: 'Init is happening July 6 - 10',
    description:
      'Init is happening July 6 - 10. A week of exciting product launches, live sessions, and community events. Five days of launches, demos, and surprises.',
    startDate: '2026-07-06',
    endDate: '2026-07-10',
    status: 'active',
    featured: true,
    presenceEnabled: true,
    tickets: INIT_JULY_2026_TICKET_CONFIG,
    primaryCta: { label: 'View event', href: 'https://appwrite.io/init' },
    secondaryCta: { label: 'Claim your ticket', href: 'https://appwrite.io/init/ticket' },
    headerNavCta: {
      beforeEvent: { label: 'Back to Appwrite', to: '/' },
      duringAfterEvent: {
        label: 'Try Appwrite 2.0',
        href: 'https://appwrite.io',
        external: true,
      },
    },
    liveBanner: {
      title: 'Appwrite 2.0 launch',
      href: 'https://appwrite.io/init/keynote',
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
        title: 'DocumentsDB & VectorsDB',
        statusLabel: 'Starting in 15m',
        status: 'upcoming',
      },
      {
        id: 'servers',
        title: 'Dedicated DBs',
        statusLabel: 'Starting in 1h',
        status: 'scheduled',
      },
    ],
    giveaway: {
      title: 'Win exclusive Init swag',
      description:
        'Hoodies, caps, bottles, and more. Join our daily events, claim your Init ticket, and enter for a chance to take home Init swag.',
      imageSrcLight: '/images/init/giveaway-swag-light.png',
      imageSrcDark: '/images/init/giveaway-swag-dark.png',
      imageAlt:
        'Init giveaway merchandise including a black hoodie, cap, white water bottle, and folded tee',
    },
    getInvolved: [
      {
        id: 'ticket',
        title: 'Claim your ticket',
        description: 'Get access and unlock exclusive swag.',
        icon: Ticket,
        href: 'https://appwrite.io/init/ticket',
      },
      {
        id: 'community',
        title: 'Join the conversation',
        description: 'Share feedback and connect with the community.',
        icon: MessageCircle,
        href: 'https://appwrite.io/discord',
      },
      {
        id: 'swag',
        title: 'Earn launch rewards',
        description: 'Complete challenges during the week to win prizes.',
        icon: Gift,
        href: 'https://appwrite.io/init/rewards',
      },
    ],
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
    primaryCta: { label: 'Save the date', href: 'https://appwrite.io/init' },
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
        href: 'https://appwrite.io/init',
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
  to: '/',
}

const DEFAULT_INIT_HEADER_NAV_DURING_AFTER: LaunchEventHeaderNavCta = {
  label: 'Try Appwrite 2.0',
  href: 'https://appwrite.io',
  external: true,
}

/** Header ghost button on `/init` — before event vs during/after. */
export function resolveInitHeaderNavCta(
  event?: LaunchEvent,
  now = new Date(),
): LaunchEventHeaderNavCta {
  const activeEvent = event ?? getActiveLaunchEvent(now)
  const status = activeEvent?.status ?? 'upcoming'
  const overrides = activeEvent?.headerNavCta

  if (status === 'upcoming') {
    return { ...DEFAULT_INIT_HEADER_NAV_BEFORE, ...overrides?.beforeEvent }
  }

  return { ...DEFAULT_INIT_HEADER_NAV_DURING_AFTER, ...overrides?.duringAfterEvent }
}
