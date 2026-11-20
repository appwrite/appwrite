import { BarChart3, BellRing, Database, Sparkles, Video } from 'lucide-react'
import type {
  LaunchEventDay,
  LaunchEventPrizes,
  LaunchEventScheduleItem,
} from './types'
import { INIT_5_YOUTUBE_PLAYLIST_HREF } from './links'

export const INIT_NOV_2026_DAYS: LaunchEventDay[] = [
  {
    day: 1,
    dateLabel: 'NOVEMBER 30',
    weekdayLabel: 'MONDAY, NOVEMBER 30',
    title: 'Introducing Appwrite Analytics',
    description: 'Privacy-friendly analytics for your websites and apps.',
    longDescription:
      'See who visits your websites and apps, track pageviews and custom events without cookies, and find out where visitors come from, right next to the rest of your project.',
    icon: BarChart3,
    visual: {
      mockVisualId: 'appwrite-2',
      imageAlt: 'Appwrite Analytics placeholder mock',
    },
    isLive: true,
    sessionCount: 1,
    headerNavCta: {
      label: 'Explore Appwrite Analytics',
      href: '/products/analytics',
      external: false,
    },
    resources: [
      {
        id: 'init5-day1-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Analytics',
        href: '/init',
        actionLabel: 'Coming soon',
      },
      {
        id: 'init5-day1-product',
        typeLabel: 'Product',
        title: 'Appwrite Analytics',
        href: '/products/analytics',
        actionLabel: 'View product',
      },
      {
        id: 'init5-day1-docs',
        typeLabel: 'Docs',
        title: 'Appwrite Analytics documentation',
        href: '/docs/products/analytics',
        actionLabel: 'Visit docs',
      },
    ],
  },
  {
    day: 2,
    dateLabel: 'DECEMBER 1',
    weekdayLabel: 'TUESDAY, DECEMBER 1',
    title: 'Introducing Appwrite Videos',
    description: 'Transcode, stream, and caption video in your project.',
    longDescription:
      'Appwrite Videos brings upload, transcoding, streaming, and captions into the platform, so you can ship video without stitching together separate services.',
    icon: Video,
    visual: {
      mockVisualId: 'appwrite-2',
      imageAlt: 'Appwrite Videos placeholder mock',
    },
    sessionCount: 1,
    headerNavCta: {
      label: 'Explore Appwrite Videos',
      href: '/products/videos',
      external: false,
    },
    resources: [
      {
        id: 'init5-day2-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Videos',
        href: '/init',
        actionLabel: 'Coming soon',
      },
      {
        id: 'init5-day2-product',
        typeLabel: 'Product',
        title: 'Appwrite Videos',
        href: '/products/videos',
        actionLabel: 'View product',
      },
    ],
  },
  {
    day: 3,
    dateLabel: 'DECEMBER 2',
    weekdayLabel: 'WEDNESDAY, DECEMBER 2',
    title: 'Introducing Appwrite Zero',
    description: 'A working Appwrite project, no signup needed.',
    longDescription:
      'Appwrite Zero hands you a ready-to-use Appwrite project without an account, so you or your agent can start building right away and claim the project when you want to keep it.',
    icon: Sparkles,
    visual: {
      mockVisualId: 'appwrite-2',
      imageAlt: 'Appwrite Zero placeholder mock',
    },
    sessionCount: 1,
    resources: [
      {
        id: 'init5-day3-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Zero',
        href: '/init',
        actionLabel: 'Coming soon',
      },
    ],
  },
  {
    day: 4,
    dateLabel: 'DECEMBER 3',
    weekdayLabel: 'THURSDAY, DECEMBER 3',
    title: 'TablesDB just got upgraded',
    description: 'New capabilities for the tables you already use.',
    longDescription:
      'TablesDB gets a major upgrade, with new ways to model and query your data in the tables you already have.',
    icon: Database,
    visual: {
      mockVisualId: 'databases',
      imageAlt: 'TablesDB upgrade placeholder mock',
    },
    sessionCount: 1,
    headerNavCta: {
      label: 'Explore TablesDB',
      href: '/products/databases',
      external: false,
    },
    resources: [
      {
        id: 'init5-day4-announce',
        typeLabel: 'Blog',
        title: 'TablesDB just got upgraded',
        href: '/init',
        actionLabel: 'Coming soon',
      },
      {
        id: 'init5-day4-product',
        typeLabel: 'Product',
        title: 'Appwrite Databases',
        href: '/products/databases',
        actionLabel: 'View product',
      },
      {
        id: 'init5-day4-docs',
        typeLabel: 'Docs',
        title: 'TablesDB documentation',
        href: '/docs/products/databases/tables',
        actionLabel: 'Visit docs',
      },
    ],
  },
  {
    day: 5,
    dateLabel: 'DECEMBER 4',
    weekdayLabel: 'FRIDAY, DECEMBER 4',
    title: 'Introducing Appwrite Monitors & Beep',
    description: 'Know when your project needs you, wherever you are.',
    longDescription:
      'Appwrite Monitors alert you when project usage crosses a threshold, and Appwrite Beep, our mobile app, puts those alerts on your phone. We close the week with the grand prize draw.',
    icon: BellRing,
    visual: {
      mockVisualId: 'firewall',
      imageAlt: 'Appwrite Monitors & Beep placeholder mock',
    },
    sessionCount: 1,
    resources: [
      {
        id: 'init5-day5-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Monitors & Beep',
        href: '/init',
        actionLabel: 'Coming soon',
      },
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
    id: 'sched-nov5-analytics',
    day: 1,
    platform: 'youtube',
    title: 'Appwrite Analytics launch stream',
    startsAt: '2026-11-30T09:00:00-08:00',
    isLive: true,
    href: INIT_5_YOUTUBE_PLAYLIST_HREF,
  },
  {
    id: 'sched-nov5-videos',
    day: 2,
    platform: 'youtube',
    title: 'Appwrite Videos launch stream',
    startsAt: '2026-12-01T09:00:00-08:00',
    isLive: true,
    href: INIT_5_YOUTUBE_PLAYLIST_HREF,
  },
  {
    id: 'sched-nov5-zero',
    day: 3,
    platform: 'youtube',
    title: 'Appwrite Zero launch stream',
    startsAt: '2026-12-02T09:00:00-08:00',
    isLive: true,
    href: INIT_5_YOUTUBE_PLAYLIST_HREF,
  },
  {
    id: 'sched-nov5-tablesdb',
    day: 4,
    platform: 'youtube',
    title: 'TablesDB upgrade stream',
    startsAt: '2026-12-03T09:00:00-08:00',
    isLive: true,
    href: INIT_5_YOUTUBE_PLAYLIST_HREF,
  },
  {
    id: 'sched-nov5-finale',
    day: 5,
    platform: 'youtube',
    title: 'Monitors & Beep launch, plus the finale',
    startsAt: '2026-12-04T09:00:00-08:00',
    isLive: true,
    href: INIT_5_YOUTUBE_PLAYLIST_HREF,
  },
  {
    id: 'sched-nov5-discord-closing',
    day: 6,
    platform: 'discord',
    title: 'Init community recap',
    startsAt: '2026-12-05T09:00:00-08:00',
    href: '/discord',
  },
  {
    id: 'sched-nov5-ama',
    day: 6,
    platform: 'reddit',
    title: 'Init 5 AMA',
    startsAt: '2026-12-05T10:00:00-08:00',
    href: 'https://www.reddit.com/r/appwrite/',
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
      dateLabel: 'NOVEMBER 30',
      scheduleItemId: 'sched-nov5-analytics',
      sessionTitle: 'Appwrite Analytics launch stream',
      platform: 'youtube',
      href: INIT_5_YOUTUBE_PLAYLIST_HREF,
      prizeDescription: 'Appwrite hoodie and cap',
      visual: {
        imageAlt: 'Day 1 giveaway including an Appwrite hoodie and cap',
        imageSrcLight: '/images/init/prize-day-1-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-1-swag.jpg',
      },
    },
    {
      day: 2,
      dateLabel: 'DECEMBER 1',
      scheduleItemId: 'sched-nov5-videos',
      sessionTitle: 'Appwrite Videos launch stream',
      platform: 'youtube',
      href: INIT_5_YOUTUBE_PLAYLIST_HREF,
      prizeDescription: 'Light Appwriter keyboard',
      visual: {
        imageAlt: 'Day 2 giveaway including a light Appwriter keyboard',
        imageSrcLight: '/images/init/prize-day-2-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-2-swag.jpg',
      },
    },
    {
      day: 3,
      dateLabel: 'DECEMBER 2',
      scheduleItemId: 'sched-nov5-zero',
      sessionTitle: 'Appwrite Zero launch stream',
      platform: 'youtube',
      href: INIT_5_YOUTUBE_PLAYLIST_HREF,
      prizeDescription: 'Dark Appwriter keyboard',
      visual: {
        imageAlt: 'Day 3 giveaway including a dark Appwriter keyboard',
        imageSrcLight: '/images/init/prize-day-3-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-3-swag.jpg',
      },
    },
    {
      day: 4,
      dateLabel: 'DECEMBER 3',
      scheduleItemId: 'sched-nov5-tablesdb',
      sessionTitle: 'TablesDB upgrade stream',
      platform: 'youtube',
      href: INIT_5_YOUTUBE_PLAYLIST_HREF,
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
    dateLabel: 'DECEMBER 4',
    scheduleItemId: 'sched-nov5-finale',
    sessionTitle: 'Monitors & Beep launch, plus the finale',
    platform: 'youtube',
    href: INIT_5_YOUTUBE_PLAYLIST_HREF,
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
