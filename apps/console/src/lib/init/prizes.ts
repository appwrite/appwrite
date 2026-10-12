import { INIT_YOUTUBE_CHANNEL_HREF } from './links'
import type { LaunchEventPrizes } from './types'

export const INIT_JULY_2026_PRIZES: LaunchEventPrizes = {
  sectionTitle: 'Prizes and giveaways',
  sectionDescription:
    'Daily swag on days 1–4. Share your ticket on social to enter the day 5 grand prize.',
  dailyHeading: 'Daily swag giveaways',
  dailyPrizeLabel: 'Appwrite Init swag',
  grandPrizeHeading: 'Grand prize',
  dailyGiveaways: [
    {
      day: 1,
      dateLabel: 'AUGUST 31',
      scheduleItemId: 'sched-keynote',
      sessionTitle: 'Appwrite 2.0 launch stream',
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
      dateLabel: 'SEPTEMBER 1',
      scheduleItemId: 'sched-yt-databases',
      sessionTitle: 'PostgreSQL deep dive',
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
      dateLabel: 'SEPTEMBER 2',
      scheduleItemId: 'sched-yt-servers',
      sessionTitle: 'VectorsDB, DocumentsDB & MySQL deep dive',
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
      dateLabel: 'SEPTEMBER 3',
      scheduleItemId: 'sched-yt-s3',
      sessionTitle: 'S3 for Appwrite Storage',
      platform: 'youtube',
      href: INIT_YOUTUBE_CHANNEL_HREF,
      prizeDescription: 'RUNTIME bottle and Appwrite tee',
      visual: {
        imageAlt: 'Day 4 giveaway including a RUNTIME water bottle and Appwrite tee',
        imageSrcLight: '/images/init/prize-day-4-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-4-swag.jpg',
      },
    },
  ],
  grandPrize: {
    day: 5,
    dateLabel: 'SEPTEMBER 4',
    scheduleItemId: 'sched-yt-firewall-oauth-domains',
    sessionTitle: 'Firewall, OAuth, and Domains launch stream',
    platform: 'youtube',
    href: INIT_YOUTUBE_CHANNEL_HREF,
    title: 'Claude Max 20x · 12 months free',
    description: 'Expanded Claude Code access for one winner.',
    eligibility: 'Share Init on social during the week to enter.',
    visual: {
      imageAlt: 'Claude Max 20x plan with a free 12-month subscription grand prize card',
      imageSrcLight: '/images/init/prize-grand-claude-max-light.jpg',
      imageSrcDark: '/images/init/prize-grand-claude-max.jpg',
    },
  },
}
