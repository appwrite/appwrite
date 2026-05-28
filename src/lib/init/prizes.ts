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
      dateLabel: 'JULY 6',
      scheduleItemId: 'sched-discord-kickoff',
      sessionTitle: 'Appwrite 2.0 lounge',
      platform: 'discord',
      timeLabel: '11:00 AM',
      href: 'https://appwrite.io/discord',
      prizeDescription: 'Appwrite hoodie and cap',
      visual: {
        imageAlt: 'Day 1 giveaway including an Appwrite hoodie and cap',
        imageSrcLight: '/images/init/prize-day-1-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-1-swag.jpg',
      },
    },
    {
      day: 2,
      dateLabel: 'JULY 7',
      scheduleItemId: 'sched-discord-databases',
      sessionTitle: 'Databases office hours',
      platform: 'discord',
      timeLabel: '3:00 PM',
      href: 'https://appwrite.io/discord',
      prizeDescription: 'Light Appwriter keyboard',
      visual: {
        imageAlt: 'Day 2 giveaway including a light Appwriter keyboard',
        imageSrcLight: '/images/init/prize-day-2-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-2-swag.jpg',
      },
    },
    {
      day: 3,
      dateLabel: 'JULY 8',
      scheduleItemId: 'sched-discord-servers',
      sessionTitle: 'Database compute Q&A',
      platform: 'discord',
      timeLabel: '4:00 PM',
      href: 'https://appwrite.io/discord',
      prizeDescription: 'Dark Appwriter keyboard',
      visual: {
        imageAlt: 'Day 3 giveaway including a dark Appwriter keyboard',
        imageSrcLight: '/images/init/prize-day-3-swag-light.jpg',
        imageSrcDark: '/images/init/prize-day-3-swag.jpg',
      },
    },
    {
      day: 4,
      dateLabel: 'JULY 9',
      scheduleItemId: 'sched-discord-s3',
      sessionTitle: 'Storage community hangout',
      platform: 'discord',
      timeLabel: '2:00 PM',
      href: 'https://appwrite.io/discord',
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
    dateLabel: 'JULY 10',
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
