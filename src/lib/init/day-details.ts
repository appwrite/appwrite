import {
  Cloud,
  Database,
  Megaphone,
  Shield,
} from 'lucide-react'
import type { LaunchEventDay, LaunchEventScheduleItem } from './types'

export const INIT_JULY_2026_DAYS: LaunchEventDay[] = [
  {
    day: 1,
    dateLabel: 'AUGUST 31',
    weekdayLabel: 'MONDAY, AUGUST 31',
    title: 'Announcing Appwrite 2.0',
    description: 'The next chapter of Appwrite is here.',
    longDescription:
      'Meet Appwrite 2.0 - a refreshed platform experience, stronger foundations, and the start of everything we are shipping during Init week.',
    icon: Megaphone,
    visual: {
      mockVisualId: 'appwrite-2',
      imageAlt: 'Appwrite logo and 2.0',
    },
    isLive: true,
    sessionCount: 2,
    headerNavCta: {
      label: 'Discover Appwrite 2.0',
      href: '/home',
      external: false,
    },
    resources: [
      {
        id: 'day1-announce',
        typeLabel: 'Blog',
        title: 'Announcing Appwrite 2.0',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day1-hyperloop',
        typeLabel: 'Blog',
        title: 'Hyperloop B - New engine behind Appwrite 2.0',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day1-console-iv',
        typeLabel: 'Blog',
        title: 'Console IV - Next-gen Appwrite console, rebuilt with TanStack',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day1-terminal',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Terminal',
        href: '/blog/post/announcing-console-terminal',
        actionLabel: 'Read article',
      },
      {
        id: 'day1-explorer',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Explorer',
        href: '/blog/post/announcing-appwrite-explorer',
        actionLabel: 'Read article',
      },
    ],
  },
  {
    day: 2,
    dateLabel: 'SEPTEMBER 1',
    weekdayLabel: 'TUESDAY, SEPTEMBER 1',
    title: 'PostgreSQL comes to Appwrite',
    description: 'Native PostgreSQL, managed inside Appwrite.',
    longDescription:
      'Run PostgreSQL on Appwrite with full SQL control, familiar extensions, and the tooling you already use - without leaving the platform.',
    icon: Database,
    visual: {
      mockVisualId: 'postgres',
      imageAlt: 'PostgreSQL SQL editor mock with query and results',
    },
    sessionCount: 2,
    headerNavCta: {
      label: 'Explore PostgreSQL',
      href: '/docs/products/databases',
      external: false,
    },
    resources: [
      {
        id: 'day2-blog',
        typeLabel: 'Blog',
        title: 'PostgreSQL comes to Appwrite',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day2-docs',
        typeLabel: 'Docs',
        title: 'PostgreSQL documentation',
        href: '/docs/products/databases',
        actionLabel: 'Visit docs',
      },
    ],
  },
  {
    day: 3,
    dateLabel: 'SEPTEMBER 2',
    weekdayLabel: 'WEDNESDAY, SEPTEMBER 2',
    title: 'VectorsDB, DocumentsDB & MySQL',
    description: 'Three new database types for modern apps.',
    longDescription:
      'VectorsDB, DocumentsDB, and MySQL expand what you can build on Appwrite - from vector search and flexible documents to familiar SQL workloads.',
    icon: Database,
    visual: {
      mockVisualId: 'databases',
      imageAlt: 'Database types mock with TablesDB, DocumentsDB, VectorsDB, and native PostgreSQL and MySQL',
    },
    sessionCount: 2,
    headerNavCta: {
      label: 'Explore new database types',
      href: '/docs/products/databases',
      external: false,
    },
    resources: [
      {
        id: 'day3-vectorsdb-blog',
        typeLabel: 'Blog',
        title: 'VectorsDB comes to Appwrite',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day3-documentsdb-blog',
        typeLabel: 'Blog',
        title: 'DocumentsDB comes to Appwrite',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day3-mysql-blog',
        typeLabel: 'Blog',
        title: 'MySQL comes to Appwrite',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day3-five-database-types-blog',
        typeLabel: 'Blog',
        title: 'Five database types in Appwrite, and why we built them',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day3-docs',
        typeLabel: 'Docs',
        title: 'Databases documentation',
        href: '/docs/products/databases',
        actionLabel: 'Visit docs',
      },
    ],
  },
  {
    day: 4,
    dateLabel: 'SEPTEMBER 3',
    weekdayLabel: 'THURSDAY, SEPTEMBER 3',
    title: 'S3 support for Storage',
    description: 'Access Appwrite Storage with S3-compatible APIs.',
    longDescription:
      'Appwrite Storage now exposes a project-scoped S3 endpoint with SigV4 signing. Use rclone, Terraform, AWS CLI, and other S3 tooling against your buckets without rebuilding upload flows.',
    icon: Cloud,
    visual: {
      mockVisualId: 's3-storage',
      imageAlt: 'Appwrite Storage S3 proxy mock with project endpoint, credentials, and compatible tools',
    },
    sessionCount: 2,
    headerNavCta: {
      label: 'Try S3 for Storage',
      href: '/docs/products/storage',
      external: false,
    },
    resources: [
      {
        id: 'day4-blog',
        typeLabel: 'Blog',
        title: 'S3 support for Appwrite Storage',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day4-docs',
        typeLabel: 'Docs',
        title: 'Storage & S3',
        href: '/docs/products/storage',
        actionLabel: 'Visit docs',
      },
    ],
  },
  {
    day: 5,
    dateLabel: 'SEPTEMBER 4',
    weekdayLabel: 'FRIDAY, SEPTEMBER 4',
    title: 'Appwrite Firewall & Domains',
    description: 'Protect traffic and own your domains in Appwrite.',
    longDescription:
      'Appwrite Firewall filters abuse before it reaches your APIs, Functions, and Sites. Appwrite Domains lets you buy hostnames, manage DNS, and connect custom domains with automatic TLS from the Console.',
    icon: Shield,
    visual: {
      mockVisualId: 'firewall',
      imageAlt: 'Appwrite Firewall mock with traffic stats, flow strip, and rule list',
    },
    sessionCount: 3,
    headerNavCta: {
      label: 'Explore Firewall & Domains',
      href: '/docs/products/firewall',
      external: false,
    },
    resources: [
      {
        id: 'day5-firewall-blog',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Firewall',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day5-domains-blog',
        typeLabel: 'Blog',
        title: 'Introducing Appwrite Domains',
        href: '/blog',
        actionLabel: 'Read article',
      },
      {
        id: 'day5-firewall-docs',
        typeLabel: 'Docs',
        title: 'Firewall documentation',
        href: '/docs/products/firewall',
        actionLabel: 'Visit docs',
      },
      {
        id: 'day5-domains-docs',
        typeLabel: 'Docs',
        title: 'Domains documentation',
        href: '/docs/products/domains',
        actionLabel: 'Visit docs',
      },
    ],
  },
]

/**
 * Sessions are authored in Pacific Time: livestreams at 9:00 AM with Reddit AMAs
 * an hour later, except day 2, which runs in the afternoon. Init week falls
 * entirely within PDT, so every entry carries the same -07:00 offset. Times are
 * stored as absolute instants and rendered in the viewer's own zone - see
 * `schedule-time.ts`.
 */
export const INIT_JULY_2026_SCHEDULE: LaunchEventScheduleItem[] = [
  {
    id: 'sched-keynote',
    day: 1,
    platform: 'youtube',
    title: 'Appwrite 2.0 launch stream',
    startsAt: '2026-08-31T09:00:00-07:00',
    isLive: true,
  },
  {
    id: 'sched-reddit-ama',
    day: 1,
    platform: 'reddit',
    title: 'Appwrite 2.0 AMA',
    startsAt: '2026-08-31T10:00:00-07:00',
    href: 'https://reddit.com/r/appwrite',
  },
  {
    id: 'sched-yt-databases',
    day: 2,
    platform: 'youtube',
    title: 'PostgreSQL deep dive',
    // Day 2 runs later than the rest of the week.
    startsAt: '2026-09-01T13:30:00-07:00',
  },
  {
    id: 'sched-reddit-databases-ama',
    day: 2,
    platform: 'reddit',
    title: 'PostgreSQL AMA',
    startsAt: '2026-09-01T14:30:00-07:00',
    href: 'https://reddit.com/r/appwrite',
  },
  {
    id: 'sched-yt-servers',
    day: 3,
    platform: 'youtube',
    title: 'VectorsDB, DocumentsDB & MySQL deep dive',
    startsAt: '2026-09-02T09:00:00-07:00',
  },
  {
    id: 'sched-reddit-servers-ama',
    day: 3,
    platform: 'reddit',
    title: 'VectorsDB, DocumentsDB & MySQL AMA',
    startsAt: '2026-09-02T10:00:00-07:00',
    href: 'https://reddit.com/r/appwrite',
  },
  {
    id: 'sched-yt-s3',
    day: 4,
    platform: 'youtube',
    title: 'S3 for Appwrite Storage',
    startsAt: '2026-09-03T09:00:00-07:00',
  },
  {
    id: 'sched-reddit-s3-ama',
    day: 4,
    platform: 'reddit',
    title: 'S3 for Storage AMA',
    startsAt: '2026-09-03T10:00:00-07:00',
    href: 'https://reddit.com/r/appwrite',
  },
  {
    id: 'sched-yt-firewall',
    day: 5,
    platform: 'youtube',
    title: 'Appwrite Firewall & Domains launch stream',
    startsAt: '2026-09-04T09:00:00-07:00',
  },
  {
    id: 'sched-reddit-recap-ama',
    day: 5,
    platform: 'reddit',
    title: 'Init week AMA',
    startsAt: '2026-09-04T10:00:00-07:00',
    href: 'https://reddit.com/r/appwrite',
  },
  /**
   * The community recap lands the day after the final launch day. It exists only
   * in the schedule - the day timeline, event end date, and prizes section all
   * still treat Init as a five-day week.
   */
  {
    id: 'sched-discord-closing',
    day: 6,
    platform: 'discord',
    title: 'Init community recap',
    startsAt: '2026-09-05T09:00:00-07:00',
    href: '/discord',
  },
]
