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

export type CompanyTimelineMilestone = {
  id: string
  date: string
  title: string
  description: string
  category: TimelineCategory
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
        href: 'https://appwrite.io/docs',
        kind: 'docs',
      },
    ],
  },
  {
    id: '2020-pre-seed',
    date: 'November 2020',
    title: 'Pre-seed funding',
    description:
      'Appwrite raised $1.25M in pre-seed funding led by Ibex Investors, Seedcamp, and the Abraham Fund. The round supported early company formation and brought foundational engineering talent from the open-source contributor community.',
    category: 'funding',
  },
  {
    id: '2021-seed',
    date: 'May 2021',
    title: 'Seed funding',
    description:
      'A $8.5M seed round led by Bessemer Venture Partners and Flybridge validated Appwrite\'s momentum and expanded the company\'s investor and board network.',
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
    links: [
      {
        id: 'product-hunt',
        label: '2022 Hall of Fame',
        href: 'https://www.producthunt.com/golden-kitty-awards/hall-of-fame?year=2022',
        kind: 'product-hunt',
      },
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
        href: 'https://appwrite.io/blog/post/public-beta',
        kind: 'blog',
      },
      {
        id: 'cloud',
        label: 'Appwrite Cloud',
        href: 'https://appwrite.io/docs/advanced/platform/cloud',
        kind: 'docs',
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
        href: 'https://appwrite.io/blog/post/announcing-appwrite-messaging',
        kind: 'blog',
      },
      {
        id: 'docs',
        label: 'Messaging docs',
        href: 'https://appwrite.io/docs/products/messaging',
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
        href: 'https://appwrite.io/blog/post/announcing-appwrite-startups-program',
        kind: 'blog',
      },
      {
        id: 'startups',
        label: 'Apply to the program',
        href: 'https://appwrite.io/startups',
        kind: 'product',
      },
    ],
  },
  {
    id: '2024-cloud-ga',
    date: 'September 2024',
    title: 'Cloud is GA!',
    description:
      'Appwrite Cloud reached general availability with production-grade reliability, expanded infrastructure, and the performance teams need to run applications at scale.',
    category: 'platform',
    links: [
      {
        id: 'cloud-ga',
        label: 'Cloud GA',
        href: 'https://appwrite.io/cloud-ga',
        kind: 'product',
      },
      {
        id: 'update',
        label: 'Cloud GA announcement',
        href: 'https://appwrite.io/blog/post/product-update-august-2025',
        kind: 'blog',
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
        href: 'https://appwrite.io/discord',
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
    links: [
      {
        id: 'announcement',
        label: 'Sites announcement',
        href: 'https://appwrite.io/blog/post/announcing-appwrite-sites',
        kind: 'blog',
      },
      {
        id: 'docs',
        label: 'Sites docs',
        href: 'https://appwrite.io/docs/products/sites',
        kind: 'docs',
      },
    ],
  },
  {
    id: '2026-realtime-queries',
    date: 'February 2026',
    title: 'Realtime Queries',
    description:
      'Realtime Queries gave developers precise control over subscription events, improving efficiency and scalability for interactive applications.',
    category: 'product',
    links: [
      {
        id: 'announcement',
        label: 'Realtime Queries announcement',
        href: 'https://appwrite.io/blog/post/announcing-realtime-queries',
        kind: 'blog',
      },
      {
        id: 'docs',
        label: 'Realtime docs',
        href: 'https://appwrite.io/docs/apis/realtime',
        kind: 'docs',
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
        href: 'https://appwrite.io/blog/post/announcing-appwrite-arena',
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
    id: '2026-platform-expansion',
    date: 'April 2026',
    title: 'Platform expansion',
    description:
      'Appwrite 1.9 expanded database and realtime capabilities, official Terraform support arrived for infrastructure-as-code workflows, and the Rust SDK broadened the language ecosystem.',
    category: 'product',
    links: [
      {
        id: 'update',
        label: 'April product update',
        href: 'https://appwrite.io/blog/post/april-product-update-mongodb-support-appwrite-190-realtime-upgrades-and-ai-tooling',
        kind: 'blog',
      },
      {
        id: 'terraform',
        label: 'Terraform provider',
        href: 'https://appwrite.io/blog/post/introducing-terraform-provider-for-appwrite',
        kind: 'blog',
      },
      {
        id: 'rust',
        label: 'Rust SDK',
        href: 'https://appwrite.io/blog/post/announcing-appwrite-rust-sdk',
        kind: 'blog',
      },
    ],
  },
  {
    id: '2026-appwrite-2',
    date: 'July 6, 2026',
    title: 'Appwrite 2.0',
    description:
      'Appwrite 2.0 introduced a refreshed platform experience and stronger foundations, powered by Hyperloop B, a new engine for the platform, and Console IV, a next-generation console rebuilt with TanStack.',
    category: 'product',
  },
  {
    id: '2026-native-databases',
    date: 'July 2026',
    title: 'Native Postgres and MySQL',
    description:
      'Appwrite introduced native Postgres and MySQL database solutions to the platform, giving teams dedicated relational engines for SQL workflows, portable schemas, and production workloads alongside Appwrite\'s managed data layer.',
    category: 'product',
  },
]

export const companyTimelineYearGroups = groupTimelineMilestonesByYear(
  companyTimelineMilestones,
)

export const companyTimelineYears = companyTimelineYearGroups.map(
  (group) => group.year,
)
