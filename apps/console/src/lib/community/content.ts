import type { LucideIcon } from 'lucide-react'
import {
  Bug,
  FileText,
  HeartHandshake,
  Lightbulb,
  Mic,
  Video,
} from 'lucide-react'

export type CommunityHelpCard = {
  title: string
  description: string
  icon: LucideIcon
}

export type CommunityProject = {
  title: string
  description: string
  image: string
  href: string
}

export const communityHero = {
  eyebrow: 'Community',
  title: 'Built by a community of 800+ contributors',
  description:
    "Inspire and get inspired. Join Appwrite's community of maintainers and contributors and help us make Appwrite better for developers worldwide.",
} as const

export const communityContributors = {
  title: 'The power of open source benefits us all',
  description:
    'See contributors of Appwrite since 2019 and discover how you can start contributing.',
  contributorsUrl: 'https://github.com/appwrite/appwrite/graphs/contributors',
} as const

export const communityGetInvolved = {
  title: 'Get involved',
  description:
    'With every contribution, Appwrite gets better for all of us. Start contributing today.',
  issuesTitle: 'Check our Open Issues',
  issuesDescription: 'Anyone can join and help Appwrite become better.',
  issuesUrl: 'https://github.com/appwrite/appwrite/issues',
} as const

export const communityHelpCards: CommunityHelpCard[] = [
  {
    title: 'Create content',
    description: 'Help others discover Appwrite with videos and blogs.',
    icon: Video,
  },
  {
    title: 'Present at meetups',
    description: 'Share your experience and represent Appwrite in public.',
    icon: Mic,
  },
  {
    title: 'Report bugs',
    description: 'Find bugs and submit PRs to fix them.',
    icon: Bug,
  },
  {
    title: 'Submit new ideas',
    description: 'Suggest features, integrations, or SDKs for our roadmap.',
    icon: Lightbulb,
  },
  {
    title: 'Improve documentation',
    description: 'Find improvements in our docs and improve accessibility.',
    icon: FileText,
  },
  {
    title: 'Helping others',
    description: 'Support developers with their projects and contributions.',
    icon: HeartHandshake,
  },
]

export const communityShowcase = {
  title: 'Inspire and get inspired',
  description:
    'Visit our showcase website built with Appwrite to find inspiration for your projects or to showcase what you have built.',
  href: 'https://builtwith.appwrite.io',
} as const

export const communityProjects: CommunityProject[] = [
  {
    title: 'Refetch.io',
    description: 'Open-source alternative to Hacker News.',
    image:
      'https://cloud.appwrite.io/v1/storage/buckets/thumbnails/files/68b984b5000e9ce4e9e6/preview?width=1280&output=webp&project=builtWithAppwrite',
    href: 'https://builtwith.appwrite.io/projects/68b69752de6ca9dd5313/',
  },
  {
    title: 'Auth UI',
    description: 'Appwrite-powered authentication screens generator for any application.',
    image:
      'https://cloud.appwrite.io/v1/storage/buckets/thumbnails/files/64803bb4f34eb4b05ee3/preview?width=800&output=webp&project=builtWithAppwrite',
    href: 'https://builtwith.appwrite.io/projects/6467cedd4502d0e29205/',
  },
  {
    title: 'uCanEarn',
    description: 'Appwrite-powered platform where you can sell your digital products online.',
    image: '/images/community/projects/ucanearn.avif',
    href: 'https://builtwith.appwrite.io/projects/648606ad9cd179190b28/',
  },
]

export const communityPlatforms = {
  title: 'Visit the community',
  description: "Discover Appwrite's community across platforms and join the fun.",
} as const

export const communityCta = {
  title: 'Start building with Appwrite today',
} as const
