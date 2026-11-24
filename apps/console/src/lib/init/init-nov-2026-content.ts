import {
  BarChart3,
  BellRing,
  GitPullRequest,
  Sparkles,
  Video,
} from 'lucide-react'
import type {
  LaunchEventDay,
  LaunchEventPrizes,
  LaunchEventScheduleItem,
} from './types'
import { INIT_YOUTUBE_CHANNEL_HREF } from './links'

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
    title: 'Introducing Appwrite Monitors & Appwrite Beep',
    description: 'Know when your project needs you, wherever you are.',
    longDescription:
      'Appwrite Monitors alert you when project usage crosses a threshold, and Appwrite Beep, our mobile app, puts those alerts on your phone.',
    icon: BellRing,
    visual: {
      mockVisualId: 'firewall',
      imageAlt: 'Appwrite Monitors & Appwrite Beep placeholder mock',
    },
    sessionCount: 1,
    resources: [
      {
        id: 'init5-day4-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Monitors & Appwrite Beep',
        href: '/init',
        actionLabel: 'Coming soon',
      },
    ],
  },
  {
    day: 5,
    dateLabel: 'DECEMBER 4',
    weekdayLabel: 'FRIDAY, DECEMBER 4',
    title: 'Introducing Appwrite Reviews',
    description: 'Code review on every pull request.',
    longDescription:
      'Appwrite Reviews reviews the pull requests in your connected GitHub repositories and posts its findings on the pull request. We close the week with the grand prize draw.',
    icon: GitPullRequest,
    visual: {
      mockVisualId: 'appwrite-2',
      imageAlt: 'Appwrite Reviews placeholder mock',
    },
    sessionCount: 1,
    resources: [
      {
        id: 'init5-day5-announce',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Reviews',
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
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-videos',
    day: 2,
    platform: 'youtube',
    title: 'Appwrite Videos launch stream',
    startsAt: '2026-12-01T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-zero',
    day: 3,
    platform: 'youtube',
    title: 'Appwrite Zero launch stream',
    startsAt: '2026-12-02T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-monitors',
    day: 4,
    platform: 'youtube',
    title: 'Appwrite Monitors & Appwrite Beep launch stream',
    startsAt: '2026-12-03T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
  },
  {
    id: 'sched-nov5-finale',
    day: 5,
    platform: 'youtube',
    title: 'Appwrite Reviews launch, plus the finale',
    startsAt: '2026-12-04T09:00:00-08:00',
    isLive: true,
    href: INIT_YOUTUBE_CHANNEL_HREF,
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
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'Init 5 hoodie and cap',
      // Init has no light theme: both themes show the image on its own dark ground.
      visual: {
        imageAlt: 'Day 1 giveaway: the Init 5 hoodie and the init_ cap',
        imageSrcLight: '/images/init/prize-day-1-swag-init-5.jpg',
        imageSrcDark: '/images/init/prize-day-1-swag-init-5.jpg',
      },
    },
    {
      day: 2,
      dateLabel: 'DECEMBER 1',
      scheduleItemId: 'sched-nov5-videos',
      sessionTitle: 'Appwrite Videos launch stream',
      platform: 'youtube',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'Light Appwriter keyboard and desk mat',
      visual: {
        imageAlt:
          'Day 2 giveaway: a light Appwriter keyboard on the Init 5 desk mat',
        imageSrcLight: '/images/init/prize-day-2-swag-init-5.jpg',
        imageSrcDark: '/images/init/prize-day-2-swag-init-5.jpg',
      },
    },
    {
      day: 3,
      dateLabel: 'DECEMBER 2',
      scheduleItemId: 'sched-nov5-zero',
      sessionTitle: 'Appwrite Zero launch stream',
      platform: 'youtube',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'Dark Appwriter keyboard, mug and stickers',
      visual: {
        imageAlt:
          'Day 3 giveaway: a dark Appwriter keyboard, the Init 5 mug and the Init 5 sticker sheet',
        imageSrcLight: '/images/init/prize-day-3-swag-init-5.jpg',
        imageSrcDark: '/images/init/prize-day-3-swag-init-5.jpg',
      },
    },
    {
      day: 4,
      dateLabel: 'DECEMBER 3',
      scheduleItemId: 'sched-nov5-monitors',
      sessionTitle: 'Appwrite Monitors & Appwrite Beep launch stream',
      platform: 'youtube',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'Init 5 tee, beanie and poster',
      visual: {
        imageAlt:
          'Day 4 giveaway: the Init 5 Five Days tee, the init_ beanie and the Init 5 poster',
        imageSrcLight: '/images/init/prize-day-4-swag-init-5.jpg',
        imageSrcDark: '/images/init/prize-day-4-swag-init-5.jpg',
      },
    },
  ],
  grandPrize: {
    day: 5,
    dateLabel: 'DECEMBER 4',
    scheduleItemId: 'sched-nov5-finale',
    sessionTitle: 'Appwrite Reviews launch, plus the finale',
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
