import type { LucideIcon } from 'lucide-react'
import type { InitEventTicketConfig } from '@/lib/init/ticket-types'

export type LaunchEventStatus = 'upcoming' | 'active' | 'past'

export type LaunchActivityStatus = 'live' | 'upcoming' | 'scheduled'

export type LaunchSchedulePlatform = 'youtube' | 'discord' | 'reddit'

export interface LaunchEventDayResource {
  id: string
  typeLabel: string
  title: string
  href: string
  actionLabel: string
}

export interface LaunchEventDayVideo {
  id: string
  label: string
  href?: string
}

export interface LaunchEventDayVisual {
  imageAlt: string
  imageSrcLight: string
  imageSrcDark: string
  /** Intrinsic pixel dimensions — used to preserve artwork aspect ratio in the card. */
  aspectWidth: number
  aspectHeight: number
}

export interface LaunchEventDay {
  day: number
  dateLabel: string
  weekdayLabel: string
  title: string
  description: string
  longDescription: string
  icon: LucideIcon
  visual?: LaunchEventDayVisual
  isLive?: boolean
  sessionCount: number
  resources: LaunchEventDayResource[]
  footerVideos?: LaunchEventDayVideo[]
  /** Header CTA beside the logo while this day is active during the event. */
  headerNavCta?: LaunchEventCta
}

/** Public placeholder for a launch day that has not unlocked yet (no sensitive content). */
export interface LaunchEventDayLocked {
  day: number
  dateLabel: string
  weekdayLabel: string
  isLocked: true
}

export type LaunchEventDayView = LaunchEventDay | LaunchEventDayLocked

export function isLaunchEventDayLocked(
  day: LaunchEventDayView,
): day is LaunchEventDayLocked {
  return 'isLocked' in day && day.isLocked === true
}

/** Launch event prepared for display with day-based visibility applied. */
export type InitDisplayEvent = LaunchEvent & {
  currentDay: number
  days: LaunchEventDayView[]
}

export interface LaunchEventScheduleItem {
  id: string
  day: number
  title: string
  platform: LaunchSchedulePlatform
  timeLabel: string
  href?: string
  isLive?: boolean
}

export interface LaunchEventActivity {
  id: string
  title: string
  statusLabel: string
  status: LaunchActivityStatus
}

export interface LaunchEventOnlineUser {
  id: string
  name: string
  activity: string
  isLive?: boolean
}

export type LaunchEventUserPresence = 'online' | 'recent'

export interface LaunchEventInvolvement {
  id: string
  title: string
  description: string
  icon: LucideIcon
  href?: string
}

export interface LaunchEventCta {
  label: string
  href?: string
  external?: boolean
}

/** Ghost nav button beside the logo on `/init` (before vs during/after the event). */
export interface LaunchEventHeaderNavCta {
  label: string
  /** Internal TanStack Router path (e.g. `/`). */
  to?: string
  /** External URL when `to` is not set. */
  href?: string
  external?: boolean
}

export interface LaunchEventHeaderNavCtaConfig {
  beforeEvent?: Partial<LaunchEventHeaderNavCta>
  duringAfterEvent?: Partial<LaunchEventHeaderNavCta>
}

export interface LaunchEventLiveBanner {
  title: string
  href?: string
}

export interface LaunchEventGiveaway {
  title: string
  description: string
  imageAlt: string
  imageSrcLight: string
  imageSrcDark: string
}

export interface LaunchEvent {
  id: string
  slug: string
  name: string
  dateRangeLabel: string
  headline: string
  description: string
  startDate: string
  endDate: string
  status: LaunchEventStatus
  /** When set, this event is shown on `/init` regardless of date ordering. */
  featured?: boolean
  /** When true, online sidebar and hero counts use the console Presences API. */
  presenceEnabled?: boolean
  /** Ticket type rules and matchers for this event's pass artwork. */
  tickets: InitEventTicketConfig
  days: LaunchEventDay[]
  schedule: LaunchEventScheduleItem[]
  liveActivities: LaunchEventActivity[]
  onlineUsers: LaunchEventOnlineUser[]
  recentlyOnlineUsers: LaunchEventOnlineUser[]
  onlineCount: number
  othersOnlineCount: number
  liveBanner?: LaunchEventLiveBanner
  primaryCta: LaunchEventCta
  secondaryCta?: LaunchEventCta
  /** Header back/exit CTA beside the logo on `/init`. */
  headerNavCta?: LaunchEventHeaderNavCtaConfig
  giveaway?: LaunchEventGiveaway
  getInvolved: LaunchEventInvolvement[]
}
