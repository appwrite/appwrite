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

export type InitDayMockVisualId =
  | 'appwrite-2'
  | 'postgres'
  | 'databases'
  | 's3-storage'
  | 'firewall'

export interface LaunchEventDayVisual {
  mockVisualId: InitDayMockVisualId
  /** Accessible label for the mock UI frame. */
  imageAlt: string
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
  /** True after the launch week ends - all days unlocked, no live state. */
  isRecapMode: boolean
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
  theme?: 'light' | 'dark'
  /** ISO 3166-1 alpha-2 country code from locale API. */
  countryCode?: string
}

export interface InitCommunityCountry {
  code: string
  count: number
}

export type LaunchEventUserPresence = 'online' | 'recent'

export interface LaunchEventInvolvement {
  id: string
  title: string
  description: string
  icon?: LucideIcon
  /** Brand icon path (e.g. Discord) - rendered instead of `icon` when set. */
  iconSrc?: string
  href?: string
}

export interface LaunchEventCta {
  label: string
  href?: string
  /** Internal TanStack Router path (e.g. `/sign-up`). */
  to?: string
  /** Redirect search param for auth routes (e.g. `/init`). */
  redirect?: string
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
  ctaLabel?: string
  ctaHref?: string
}

export interface LaunchEventPrizeVisual {
  imageAlt: string
  imageSrcLight?: string
  imageSrcDark?: string
}

/** One Init session per day that includes an Appwrite swag giveaway. */
export interface LaunchEventDailyPrize {
  day: number
  dateLabel: string
  scheduleItemId: string
  sessionTitle: string
  platform: LaunchSchedulePlatform
  timeLabel: string
  href?: string
  prizeDescription: string
  visual?: LaunchEventPrizeVisual
}

export interface LaunchEventGrandPrize {
  day: number
  dateLabel: string
  title: string
  description: string
  eligibility: string
  scheduleItemId?: string
  sessionTitle?: string
  platform?: LaunchSchedulePlatform
  timeLabel?: string
  href?: string
  visual?: LaunchEventPrizeVisual
}

export interface LaunchEventPrizes {
  sectionTitle?: string
  sectionDescription?: string
  dailyHeading?: string
  dailyPrizeLabel?: string
  grandPrizeHeading?: string
  dailyGiveaways: LaunchEventDailyPrize[]
  grandPrize: LaunchEventGrandPrize
}

export interface LaunchEventRecapTicketCopy {
  titleAuthenticated: string
  titleGuest: string
  descriptionAuthenticated: string
  descriptionGuest: string
  descriptionCollapsedAuthenticated: string
  descriptionCollapsedGuest: string
  shareButtonLabel?: string
}

export interface LaunchEventReleaseOption {
  title: string
  description: string
  ctaLabel: string
  href: string
  external?: boolean
  /**
   * Init day when this option becomes available.
   * Defaults to the section `unlockDay` when omitted.
   */
  unlockDay?: number
  /** Compact availability line (e.g. "Live each Init day"). */
  availabilityLabel?: string
}

/** Stage contrasting Cloud (day-by-day) and self-host (full release) availability. */
export interface LaunchEventReleaseAvailability {
  /**
   * Init day when the full release path (typically self-host) unlocks.
   * Cloud may unlock earlier via its own `unlockDay`.
   */
  unlockDay: number
  sectionTitle: string
  sectionDescription?: string
  /** Title while the full-release path is still locked. */
  lockedTitle: string
  lockedDescription: string
  cloud: LaunchEventReleaseOption
  selfHosted: LaunchEventReleaseOption
}

export interface LaunchEventRecap {
  /** Hero headline when the event week has ended. */
  headline: string
  /** Hero description in recap mode. */
  description: string
  /** Top banner message below the header. */
  bannerMessage?: string
  /** Section above the day timeline in recap mode. */
  introTitle?: string
  introDescription?: string
  /** Replaces "Ways to get involved" in recap mode. */
  getInvolvedSectionTitle?: string
  /** Cards shown instead of live-event getInvolved links. */
  getInvolved?: LaunchEventInvolvement[]
  /** Ticket section copy overrides. */
  ticket?: LaunchEventRecapTicketCopy
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
  /** Daily swag sessions and grand prize details for the bottom prizes section. */
  prizes?: LaunchEventPrizes
  /** Copy and labels shown after the launch week ends. */
  recap?: LaunchEventRecap
  /** Availability cards shown after the final Init day. */
  releaseAvailability?: LaunchEventReleaseAvailability
  getInvolved: LaunchEventInvolvement[]
}
