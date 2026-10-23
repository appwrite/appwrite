import { BarChart3, Database, Rocket, Smartphone, Video } from 'lucide-react'
import type {
  LaunchEventDay,
  LaunchEventPrizes,
  LaunchEventScheduleItem,
} from './types'
import { INIT_YOUTUBE_CHANNEL_HREF } from './links'

export const INIT_NOV_2026_DAYS: LaunchEventDay[] = [
  {
    day: 1,
    dateLabel: 'NOVEMBER 2',
    weekdayLabel: 'MONDAY, NOVEMBER 2',
    title: 'Introducing Appwrite Videos',
    description: 'Host, stream, and deliver video in your Appwrite project.',
    longDescription:
      'Appwrite Videos brings upload, transcoding, and playback into the platform so you can ship media experiences without stitching together separate services.',
    icon: Video,
    visual: {
      mockVisualId: 'appwrite-2',
      imageAlt: 'Appwrite Videos placeholder mock',
    },
    isLive: true,
    sessionCount: 2,
    headerNavCta: {
      label: 'Explore Appwrite Videos',
      href: '/init',
      external: false,
    },
    resources: [
      {
        id: 'init5-day1-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Videos',
        href: '/init',
        actionLabel: 'Coming soon',
      },
    ],
  },
  {
    day: 2,
    dateLabel: 'NOVEMBER 3',
    weekdayLabel: 'TUESDAY, NOVEMBER 3',
    title: 'Introducing Appwrite Analytics',
    description: 'Product analytics built into Appwrite.',
    longDescription:
      'Understand retention, funnels, and feature usage with analytics that share the same projects, permissions, and Console experience you already use.',
    icon: BarChart3,
    visual: {
      mockVisualId: 'appwrite-2',
      imageAlt: 'Appwrite Analytics placeholder mock',
    },
    sessionCount: 2,
    headerNavCta: {
      label: 'Explore Appwrite Analytics',
      href: '/init',
      external: false,
    },
    resources: [
      {
        id: 'init5-day2-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Analytics',
        href: '/init',
        actionLabel: 'Coming soon',
      },
    ],
  },
  {
    day: 3,
    dateLabel: 'NOVEMBER 4',
    weekdayLabel: 'WEDNESDAY, NOVEMBER 4',
    title: 'TablesDB upgrades: joins and aggregations',
    description: 'Relational queries and rollups on TablesDB.',
    longDescription:
      'Join related tables and run aggregations in TablesDB so you can model richer data and answer analytical questions without leaving Appwrite.',
    icon: Database,
    visual: {
      mockVisualId: 'databases',
      imageAlt: 'TablesDB joins and aggregations placeholder mock',
    },
    sessionCount: 2,
    headerNavCta: {
      label: 'Explore TablesDB',
      href: '/docs/products/databases/tables',
      external: false,
    },
    resources: [
      {
        id: 'init5-day3-announce',
        typeLabel: 'Blog',
        title: 'TablesDB joins and aggregations',
        href: '/init',
        actionLabel: 'Coming soon',
      },
      {
        id: 'init5-day3-docs',
        typeLabel: 'Docs',
        title: 'TablesDB documentation',
        href: '/docs/products/databases/tables',
        actionLabel: 'Visit docs',
      },
    ],
  },
  {
    day: 4,
    dateLabel: 'NOVEMBER 5',
    weekdayLabel: 'THURSDAY, NOVEMBER 5',
    title: 'Introducing Appwrite Distribution',
    description: 'Automate publishing to the App Store and Google Play.',
    longDescription:
      'Appwrite Distribution is a new way to automate app publishing to the Apple App Store and Google Play, from build artifacts to store submissions, inside your Appwrite workflow.',
    icon: Smartphone,
    visual: {
      mockVisualId: 's3-storage',
      imageAlt: 'Appwrite Distribution placeholder mock',
    },
    sessionCount: 2,
    headerNavCta: {
      label: 'Explore Appwrite Distribution',
      href: '/init',
      external: false,
    },
    resources: [
      {
        id: 'init5-day4-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Distribution',
        href: '/init',
        actionLabel: 'Coming soon',
      },
    ],
  },
  {
    day: 5,
    dateLabel: 'NOVEMBER 6',
    weekdayLabel: 'FRIDAY, NOVEMBER 6',
    title: 'Init week finale',
    description: 'Recap the week and celebrate with the community.',
    longDescription:
      'Join the closing stream for a fast recap of every Init 5 launch, community highlights, and the grand prize draw.',
    icon: Rocket,
    visual: {
      mockVisualId: 'firewall',
      imageAlt: 'Init week finale placeholder mock',
    },
    sessionCount: 2,
    headerNavCta: {
      label: 'Join the finale stream',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      external: true,
    },
    resources: [
      {
        id: 'init5-day5-recap',
        typeLabel: 'Blog',
        title: 'Init 5 recap',
        href: '/init',
        actionLabel: 'Coming soon',
      },
    ],
  },
]

/** Init 5 week schedule (Pacific Time, PST in November). */
export const INIT_NOV_2026_SCHEDULE: LaunchEventScheduleItem[] = [
  {
    id: 'sched-nov5-keynote',
    day: 1,
    platform: 'youtube',
    title: 'Appwrite Videos launch stream',
    startsAt: '2026-11-02T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-day1-ama',
    day: 1,
    platform: 'reddit',
    title: 'Appwrite Videos AMA',
    startsAt: '2026-11-02T10:00:00-08:00',
    href: 'https://www.reddit.com/r/appwrite/',
  },
  {
    id: 'sched-nov5-analytics',
    day: 2,
    platform: 'youtube',
    title: 'Appwrite Analytics deep dive',
    startsAt: '2026-11-03T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-day2-ama',
    day: 2,
    platform: 'reddit',
    title: 'Appwrite Analytics AMA',
    startsAt: '2026-11-03T10:00:00-08:00',
    href: 'https://www.reddit.com/r/appwrite/',
  },
  {
    id: 'sched-nov5-tablesdb',
    day: 3,
    platform: 'youtube',
    title: 'TablesDB joins and aggregations',
    startsAt: '2026-11-04T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-day3-ama',
    day: 3,
    platform: 'reddit',
    title: 'TablesDB AMA',
    startsAt: '2026-11-04T10:00:00-08:00',
    href: 'https://www.reddit.com/r/appwrite/',
  },
  {
    id: 'sched-nov5-distribution',
    day: 4,
    platform: 'youtube',
    title: 'Appwrite Distribution launch stream',
    startsAt: '2026-11-05T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-day4-ama',
    day: 4,
    platform: 'reddit',
    title: 'Appwrite Distribution AMA',
    startsAt: '2026-11-05T10:00:00-08:00',
    href: 'https://www.reddit.com/r/appwrite/',
  },
  {
    id: 'sched-nov5-finale',
    day: 5,
    platform: 'youtube',
    title: 'Init 5 finale stream',
    startsAt: '2026-11-06T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-day5-ama',
    day: 5,
    platform: 'reddit',
    title: 'Init 5 week AMA',
    startsAt: '2026-11-06T10:00:00-08:00',
    href: 'https://www.reddit.com/r/appwrite/',
  },
  {
    id: 'sched-nov5-discord-closing',
    day: 6,
    platform: 'discord',
    title: 'Init community recap',
    startsAt: '2026-11-07T09:00:00-08:00',
    href: '/discord',
  },
]

export const INIT_NOV_2026_PRIZES: LaunchEventPrizes = {
  sectionTitle: 'Prizes and giveaways',
  sectionDescription:
    'Daily swag on days 1-4. Share your ticket on social to enter the day 5 grand prize.',
  dailyHeading: 'Daily swag giveaways',
  dailyPrizeLabel: 'Appwrite Init swag',
  grandPrizeHeading: 'Grand prize',
  dailyGiveaways: [
    {
      day: 1,
      dateLabel: 'NOVEMBER 2',
      scheduleItemId: 'sched-nov5-keynote',
      sessionTitle: 'Appwrite Videos launch stream',
      platform: 'youtube',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'Appwrite hoodie and cap',
      visual: {
        imageAlt: 'Day 1 giveaway including an Appwrite hoodie and cap',
        imageSrcLight: '/images/init/prize-day-1-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-1-swag.jpg',
      },
    },
    {
      day: 2,
      dateLabel: 'NOVEMBER 3',
      scheduleItemId: 'sched-nov5-analytics',
      sessionTitle: 'Appwrite Analytics deep dive',
      platform: 'youtube',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'Light Appwriter keyboard',
      visual: {
        imageAlt: 'Day 2 giveaway including a light Appwriter keyboard',
        imageSrcLight: '/images/init/prize-day-2-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-2-swag.jpg',
      },
    },
    {
      day: 3,
      dateLabel: 'NOVEMBER 4',
      scheduleItemId: 'sched-nov5-tablesdb',
      sessionTitle: 'TablesDB joins and aggregations',
      platform: 'youtube',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'Dark Appwriter keyboard',
      visual: {
        imageAlt: 'Day 3 giveaway including a dark Appwriter keyboard',
        imageSrcLight: '/images/init/prize-day-3-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-3-swag.jpg',
      },
    },
    {
      day: 4,
      dateLabel: 'NOVEMBER 5',
      scheduleItemId: 'sched-nov5-distribution',
      sessionTitle: 'Appwrite Distribution launch stream',
      platform: 'youtube',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'RUNTIME bottle and Appwrite tee',
      visual: {
        imageAlt:
          'Day 4 giveaway including a RUNTIME water bottle and Appwrite tee',
        imageSrcLight: '/images/init/prize-day-4-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-4-swag.jpg',
      },
    },
  ],
  grandPrize: {
    day: 5,
    dateLabel: 'NOVEMBER 6',
    scheduleItemId: 'sched-nov5-finale',
    sessionTitle: 'Init 5 finale stream',
    platform: 'youtube',
    href: INIT_YOUTUBE_CHANNEL_HREF,
    title: 'Grand prize · details coming soon',
    description: 'Full prize details will be announced before Init 5.',
    eligibility: 'Share Init on social during the week to enter.',
    visual: {
      imageAlt: 'Init 5 grand prize placeholder',
      imageSrcLight: '/images/init/prize-grand-claude-max-light.jpg',
      imageSrcDark: '/images/init/prize-grand-claude-max.jpg',
    },
  },
}
