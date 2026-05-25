import type { LucideIcon } from 'lucide-react'

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

export interface LaunchEventDay {
  day: number
  dateLabel: string
  weekdayLabel: string
  title: string
  description: string
  longDescription: string
  icon: LucideIcon
  isLive?: boolean
  sessionCount: number
  announcementVideo?: LaunchEventDayVideo
  resources: LaunchEventDayResource[]
  footerVideos: LaunchEventDayVideo[]
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

export interface LaunchEventLiveBanner {
  title: string
  href?: string
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
  getInvolved: LaunchEventInvolvement[]
}
