export type TimelineCategory =
  | 'launch'
  | 'funding'
  | 'product'
  | 'platform'
  | 'community'

export type TimelineLinkKind =
  | 'blog'
  | 'docs'
  | 'github'
  | 'news'
  | 'product'
  | 'product-hunt'
  | 'youtube'

export type CompanyTimelineLink = {
  id: string
  label: string
  href: string
  kind: TimelineLinkKind
}

export type CompanyTimelineImage = {
  src: string
  alt: string
}

export type CompanyTimelineMilestone = {
  id: string
  date: string
  title: string
  description: string
  category: TimelineCategory
  images?: readonly CompanyTimelineImage[]
  links?: readonly CompanyTimelineLink[]
}

export const TIMELINE_CATEGORY_LABELS: Record<TimelineCategory, string> = {
  launch: 'Launch',
  funding: 'Funding',
  product: 'Product',
  platform: 'Platform',
  community: 'Community',
}

export const companyTimelineIntro =
  'Appwrite began in 2019 as a founder-led open-source project built to give developers a consistent, predictable layer over cloud infrastructure. What started as a side project has grown into a platform used by hundreds of thousands of developers worldwide.'

export function getTimelineMilestoneYear(date: string): number {
  const match = date.match(/\b(20\d{2})\b/)
  return match ? Number(match[1]) : 0
}

export type TimelineYearGroup = {
  year: number
  milestones: CompanyTimelineMilestone[]
}

export function groupTimelineMilestonesByYear(
  milestones: readonly CompanyTimelineMilestone[],
): TimelineYearGroup[] {
  const groups: TimelineYearGroup[] = []

  for (const milestone of milestones) {
    const year = getTimelineMilestoneYear(milestone.date)
    const lastGroup = groups.at(-1)

    if (lastGroup?.year === year) {
      lastGroup.milestones.push(milestone)
      continue
    }

    groups.push({ year, milestones: [milestone] })
  }

  return groups
}

export const companyTimelineMilestones: readonly CompanyTimelineMilestone[] = [
  {
    id: '2019-launch',
    date: 'September 2019',
    title: 'Open-source launch',
    description:
      'Appwrite launched publicly as an open-source backend platform. Designed from the outset to abstract cloud complexity through familiar APIs and protocols, the project gained rapid traction in its first month, including strong visibility across developer communities.',
    category: 'launch',
    images: [
      {
        src: '/images/company/first-release-console.jpg',
        alt: 'The very first Appwrite Console',
      },
    ],
    links: [
      {
        id: 'github',
        label: 'View on GitHub',
        href: 'https://github.com/appwrite/appwrite',
        kind: 'github',
      },
      {
        id: 'docs',
        label: 'Getting started',
        href: '/docs',
        kind: 'docs',
      },
    ],
  },
  {
    id: '2021-seed',
    date: 'May 2021',
    title: 'Seed funding',
    description:
      'Appwrite raised $10M in seed funding led by Bessemer Venture Partners and Flybridge, with participation from Ibex Investors, Seedcamp, and the Abraham Fund. The round supported company formation, brought foundational engineering talent from the open-source contributor community, and expanded the investor and board network.',
    category: 'funding',
    links: [
      {
        id: 'zdnet',
        label: 'Seed round coverage',
        href: 'https://www.zdnet.com/article/open-source-backend-as-a-service-appwrite-gets-10m-seed-funding-to-commercialize-traction/',
        kind: 'news',
      },
    ],
  },
  {
    id: '2021-series-a',
    date: 'December 2021',
    title: 'Series A',
    description:
      'Appwrite completed a $27M Series A led by Tiger Global, with participation from existing investors, to accelerate product development and global growth.',
    category: 'funding',
    links: [
      {
        id: 'venturebeat',
        label: 'Series A coverage',
        href: 'https://venturebeat.com/business/appwrite-an-open-source-backend-as-a-service-provider-raises-27m',
        kind: 'news',
      },
    ],
  },
  {
    id: '2022-oss-fund',
    date: 'May 2022',
    title: 'Open source fund',
    description:
      'Following the Series A, Appwrite established a $50,000 fund to support independent open-source projects, reinforcing the company\'s commitment to the broader ecosystem.',
    category: 'community',
    links: [
      {
        id: 'oss-fund',
        label: 'OSS fund announcement',
        href: 'https://dev.to/appwrite/announcing-the-appwrite-oss-fund-4ilg',
        kind: 'news',
      },
    ],
  },
  {
    id: '2022-1-0',
    date: 'September 2022',
    title: 'Appwrite 1.0',
    description:
      'The first stable release marked a major milestone after years of community-driven development, spanning thousands of commits and contributions from developers worldwide.',
    category: 'product',
    links: [
      {
        id: 'release',
        label: '1.0 release notes',
        href: 'https://github.com/appwrite/appwrite/releases/tag/1.0.0',
        kind: 'github',
      },
    ],
  },
  {
    id: '2022-console',
    date: 'November 2022',
    title: 'Console 2.0',
    description:
      'Appwrite shipped a redesigned Console with a new in-house design system and a developer experience built specifically for managing production backends at scale.',
    category: 'product',
    links: [
      {
        id: 'launch-video',
        label: 'Console 2.0 launch',
        href: 'https://www.youtube.com/watch?v=XfT1gvC7orc',
        kind: 'youtube',
      },
    ],
  },
  {
    id: '2022-golden-kitty',
    date: 'December 2022',
    title: 'Golden Kitty Award',
    description:
      'Appwrite won Product Hunt\'s Golden Kitty Award for Best Developer Tool, recognizing the platform\'s impact among makers and the broader developer community.',
    category: 'community',
    images: [
      {
        src: '/images/company/golden-kitty-winners.jpg',
        alt: 'Appwrite won Best Developer Tool at the 2022 Golden Kitty Awards',
      },
      {
        src: '/images/company/golden-kitty-product-hunt.jpg',
        alt: 'Appwrite on Product Hunt as #1 Product of the Week',
      },
    ],
    links: [
    ],
  },
  {
    id: '2023-cloud-beta',
    date: 'April 2023',
    title: 'Cloud public beta',
    description:
      'Appwrite Cloud entered public beta, making the platform available without self-hosting and opening the door to managed infrastructure for teams of every size.',
    category: 'platform',
    links: [
      {
        id: 'public-beta',
        label: 'Public beta announcement',
        href: '/blog/post/public-beta',
        kind: 'blog',
      },
      {
        id: 'cloud',
        label: 'Appwrite Cloud',
        href: '/docs/advanced/platform/cloud',
        kind: 'docs',
      },
    ],
  },
  {
    id: '2023-rebrand',
    date: 'September 2023',
    title: 'Brand refresh',
    description:
      'Appwrite unveiled a refreshed brand identity, including a new logo, redesigned website, and improved documentation, reflecting the company\'s evolution from a backend service into an all-in-one open-source development platform.',
    category: 'platform',
    images: [
      {
        src: '/images/company/rebrand-before-after.jpg',
        alt: 'Side-by-side comparison of the previous and refreshed Appwrite logos',
      },
      {
        src: '/images/company/rebrand-logo-sketches.jpg',
        alt: 'Early hand-drawn logo sketches from the Appwrite rebrand process',
      },
    ],
    links: [
      {
        id: 'announcement',
        label: 'Rebrand announcement',
        href: '/blog/post/meet-the-new-appwrite',
        kind: 'blog',
      },
      {
        id: 'logo',
        label: 'The new logo',
        href: '/blog/post/the-journey-and-meaning-behind-our-new-logo',
        kind: 'blog',
      },
      {
        id: 'website',
        label: 'Designing the new website',
        href: '/blog/post/designing-the-new-appwrite-website',
        kind: 'blog',
      },
    ],
  },
  {
    id: '2024-messaging',
    date: 'February 2024',
    title: 'Appwrite Messaging',
    description:
      'Messaging expanded the platform with email, push, and SMS capabilities, giving teams native tools for user communication and notifications.',
    category: 'product',
    links: [
      {
        id: 'announcement',
        label: 'Messaging announcement',
        href: '/blog/post/announcing-appwrite-messaging',
        kind: 'blog',
      },
      {
        id: 'docs',
        label: 'Messaging docs',
        href: '/docs/products/messaging',
        kind: 'docs',
      },
    ],
  },
  {
    id: '2024-startups',
    date: 'April 2024',
    title: 'Startups program',
    description:
      'The Appwrite Startups Program launched to support early-stage teams with credits, guidance, and infrastructure as they build on the platform.',
    category: 'community',
    links: [
      {
        id: 'announcement',
        label: 'Program announcement',
        href: '/blog/post/announcing-appwrite-startups-program',
        kind: 'blog',
      },
      {
        id: 'startups',
        label: 'Apply to the program',
        href: '/startups',
        kind: 'product',
      },
    ],
  },
  {
    id: '2024-github-stars',
    date: 'November 2024',
    title: '50,000 GitHub stars',
    description:
      'Appwrite surpassed 50,000 GitHub stars, reflecting sustained adoption and the strength of its global open-source community.',
    category: 'community',
    links: [
      {
        id: 'github',
        label: 'Star on GitHub',
        href: 'https://github.com/appwrite/appwrite',
        kind: 'github',
      },
      {
        id: 'discord',
        label: 'Join the community',
        href: '/discord',
        kind: 'product',
      },
    ],
  },
  {
    id: '2025-sites',
    date: 'May 18, 2025',
    title: 'Appwrite Sites',
    description:
      'Appwrite Sites launched as an open-source application hosting product, letting teams develop, deploy, and scale web apps from the same platform as their backend services.',
    category: 'product',
    images: [
      {
        src: '/images/company/sites-empty-state.jpg',
        alt: 'The Sites view in Console, ready to deploy your first web app',
      },
      {
        src: '/images/company/sites-create-templates.jpg',
        alt: 'Creating a site from starter templates and popular frameworks',
      },
    ],
    links: [
      {
        id: 'docs',
        label: 'Sites docs',
        href: '/docs/products/sites',
        kind: 'docs',
      },
    ],
  },
  {
    id: '2025-cloud-ga',
    date: 'September 2025',
    title: 'Cloud is GA!',
    description:
      'Appwrite Cloud reached general availability with production-grade reliability, expanded infrastructure, and the performance teams need to run applications at scale.',
    category: 'platform',
    images: [
      {
        src: '/images/company/cloud-ga-journey.jpg',
        alt: 'The Appwrite Cloud GA journey from private beta to launch',
      },
    ],
    links: [
      {
        id: 'cloud-ga',
        label: 'Cloud GA',
        href: '/blog/post/product-update-august-2025',
        kind: 'product',
      },
    ],
  },
  {
    id: '2026-arena',
    date: 'March 2026',
    title: 'Appwrite Arena',
    description:
      'Appwrite Arena launched as an open benchmark for evaluating how effectively AI models understand and work with Appwrite APIs and workflows.',
    category: 'platform',
    links: [
      {
        id: 'announcement',
        label: 'Arena announcement',
        href: '/blog/post/announcing-appwrite-arena',
        kind: 'blog',
      },
      {
        id: 'arena',
        label: 'View leaderboard',
        href: 'https://arena.appwrite.io',
        kind: 'product',
      },
    ],
  },
  {
    id: '2026-presences',
    date: 'May 25, 2026',
    title: 'Appwrite Presences',
    description:
      'Appwrite Presences introduced a Realtime API for short-lived user statuses, with built-in channels, automatic expiry, and permission-aware subscriptions.',
    category: 'product',
    links: [
      {
        id: 'announcement',
        label: 'Presences announcement',
        href: '/blog/post/announcing-presences-api',
        kind: 'blog',
      },
      {
        id: 'docs',
        label: 'Presences docs',
        href: '/docs/apis/realtime/presences',
        kind: 'docs',
      },
    ],
  },
  {
    id: '2026-appwrite-2',
    date: 'August 31, 2026',
    title: 'Appwrite 2.0',
    description:
      'Appwrite 2.0 introduced a refreshed platform experience and stronger foundations, powered by Hyperloop B, a new engine for the platform, and Console IV, a next-generation console rebuilt with TanStack.',
    category: 'product',
  },
  {
    id: '2026-native-databases',
    date: 'September 2026',
    title: 'Native PostgreSQL and MySQL',
    description:
      'Appwrite introduced native PostgreSQL and MySQL database solutions to the platform, giving teams dedicated relational engines for SQL workflows, portable schemas, and production workloads alongside Appwrite\'s managed data layer.',
    category: 'product',
  },
]

export const companyTimelineYearGroups = groupTimelineMilestonesByYear(
  companyTimelineMilestones,
)

export const companyTimelineYears = companyTimelineYearGroups.map(
  (group) => group.year,
)
