import type { LucideIcon } from 'lucide-react'
import {
  BarChart3,
  Gift,
  Link2,
  Share2,
  Sparkles,
  Wallet,
} from 'lucide-react'
import {
  AFFILIATE_ATTRIBUTION_DAYS,
  AFFILIATE_REWARD_AMOUNT_USD,
} from '@/lib/react-query/hooks/affiliates'

export type AffiliatesFeature = {
  title: string
  description: string
  icon: LucideIcon
}

export type AffiliatesStep = {
  title: string
  description: string
}

export type AffiliatesRewardHighlight = {
  value: string
  label: string
}

export type AffiliatesFaqItem = {
  question: string
  answer: string
  links?: { label: string; href: string }[]
}

export const affiliatesHero = {
  eyebrow: 'Affiliates Program',
  title: 'Earn credits by referring developers',
  description:
    'Share Appwrite with other builders. When someone you invite upgrades to Pro, you earn organization credits to use on Appwrite Cloud.',
} as const

export const affiliatesTopBenefits: AffiliatesFeature[] = [
  {
    title: 'Simple invite links',
    description:
      'Create shareable links for each campaign or channel and track clicks automatically.',
    icon: Link2,
  },
  {
    title: 'Long attribution window',
    description: `Signups stay attributed to you for ${AFFILIATE_ATTRIBUTION_DAYS} days after they join.`,
    icon: Share2,
  },
  {
    title: 'Credits you can spend',
    description: `Earn $${AFFILIATE_REWARD_AMOUNT_USD} in organization credits for each referred Pro upgrade.`,
    icon: Wallet,
  },
  {
    title: 'Built into the console',
    description:
      'Manage links, referrals, and rewards from your Appwrite account. No separate dashboard required.',
    icon: BarChart3,
  },
]

export const affiliatesSteps: AffiliatesStep[] = [
  {
    title: 'Create an Appwrite account',
    description:
      'Sign up for Appwrite Cloud, or sign in if you already have an account. Affiliates is available from your account.',
  },
  {
    title: 'Open Affiliates',
    description:
      'Go to Account, then Affiliates. From there you can create invite links and track referrals.',
  },
  {
    title: 'Create and share your first link',
    description: `Generate an invite link for each campaign or channel. Signups stay attributed for ${AFFILIATE_ATTRIBUTION_DAYS} days, and you earn $${AFFILIATE_REWARD_AMOUNT_USD} when a referral upgrades to Pro.`,
  },
]

export const affiliatesRewards = {
  title: 'How rewards work',
  description:
    'Rewards are simple, transparent, and paid as credits you can use on Appwrite Cloud.',
  highlights: [
    {
      value: `$${AFFILIATE_REWARD_AMOUNT_USD}`,
      label: 'Credits added to your organization for each Pro upgrade',
    },
    {
      value: `${AFFILIATE_ATTRIBUTION_DAYS} days`,
      label: 'Time after signup during which a Pro upgrade still counts for you',
    },
    {
      value: 'Pro',
      label: 'Only referrals who upgrade to Pro generate a reward',
    },
  ] satisfies AffiliatesRewardHighlight[],
} as const

export const affiliatesWhyJoin: AffiliatesFeature[] = [
  {
    title: 'Help developers discover Appwrite',
    description:
      'Recommend a backend you already trust and help more builders ship faster.',
    icon: Sparkles,
  },
  {
    title: 'Get rewarded for referrals',
    description:
      'Turn community advocacy into credits that offset your own Appwrite usage.',
    icon: Gift,
  },
  {
    title: 'Track what works',
    description:
      'See clicks, signups, and conversions so you know which channels perform best.',
    icon: BarChart3,
  },
]

export const affiliatesFaqItems: AffiliatesFaqItem[] = [
  {
    question: 'What is the Appwrite Affiliates program?',
    answer:
      'The Affiliates program lets you create invite links and earn organization credits when people you refer join Appwrite Cloud and upgrade to Pro.',
  },
  {
    question: 'How do I join?',
    answer:
      'Sign in to Appwrite Cloud and open Affiliates in your account. From there you can create invite links and track referrals.',
    links: [{ label: 'Open Affiliates', href: '/account/affiliates' }],
  },
  {
    question: 'How much do I earn?',
    answer: `You receive $${AFFILIATE_REWARD_AMOUNT_USD} in organization credits for each referred user who upgrades to Pro.`,
  },
  {
    question: 'How does attribution work if someone clicks more than one invite link?',
    answer:
      'We use last-click attribution. Credit goes to the last invite link clicked before signup. Earlier clicks still appear in analytics, but only the last affiliate earns the reward if that user upgrades to Pro.',
  },
  {
    question: 'How long does attribution last?',
    answer: `After someone signs up through an attributed invite link, Pro upgrades count for that affiliate for ${AFFILIATE_ATTRIBUTION_DAYS} days.`,
  },
  {
    question: 'When do I get rewarded?',
    answer:
      'A reward is created when a referred user upgrades to Pro within the attribution window. Credits are added to your organization after the reward is claimed or applied.',
  },
  {
    question: 'Who can join the Affiliates program?',
    answer:
      'The Affiliates program is available on Appwrite Cloud. Create an account, then open Affiliates from your account settings to get started.',
  },
  {
    question: 'Can I create more than one invite link?',
    answer:
      'Yes. Create separate links for different campaigns, posts, or communities so you can compare performance.',
  },
  {
    question: 'Where can I use the credits?',
    answer:
      'Affiliate rewards are added as organization credits on Appwrite Cloud and can be used toward eligible Cloud charges.',
  },
]

export const affiliatesDashboard = {
  title: 'Track everything from your dashboard',
  description:
    'See clicks, attributed signups, and Pro conversions for each invite link. Claim credits when rewards are ready, without leaving the Appwrite Console.',
} as const

export const affiliatesCta = {
  title: 'Start earning with the Affiliates program',
  description:
    'Create your first invite link, share Appwrite with developers, and earn credits when they upgrade to Pro.',
} as const
