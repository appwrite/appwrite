import type { LucideIcon } from 'lucide-react'
import {
  Code2,
  Layers,
  Lightbulb,
  Megaphone,
  Percent,
  Rocket,
  Sparkles,
  GraduationCap,
  Headphones,
} from 'lucide-react'

export type PartnerBenefit = {
  title: string
  description: string
  icon: LucideIcon
}

export const partnersHero = {
  eyebrow: 'Partners Program',
  title: 'Boost businesses with Appwrite',
  description:
    'Join the Appwrite Partners program and grow your business. Deliver powerful solutions to clients, increase revenue, and expand your reach.',
  catalogUrl: '/partners',
} as const

export const partnerBenefits: PartnerBenefit[] = [
  {
    title: 'Co-marketing',
    description:
      'We will have a dedicated partner catalog, an official partner badge, and other visibility opportunities.',
    icon: Megaphone,
  },
  {
    title: 'Training',
    description:
      'We provide in-depth training and workshops to help you master Appwrite for your clients.',
    icon: GraduationCap,
  },
  {
    title: 'Support',
    description:
      'You will get access to the Appwrite engineering team to get the support you need.',
    icon: Headphones,
  },
  {
    title: 'Early access',
    description:
      'You will get early access to new features and products and the ability to influence our roadmap.',
    icon: Sparkles,
  },
  {
    title: 'Innovation',
    description:
      "Empower your team and elevate your customers' experiences with the newest technology.",
    icon: Lightbulb,
  },
  {
    title: 'Discounts',
    description:
      'Volume discounts are available in case you handle the bill for your clients.',
    icon: Percent,
  },
]

export const partnerWhyAppwrite: PartnerBenefit[] = [
  {
    title: 'Developer experience',
    description:
      'Appwrite is built for and by developers, with a strong focus on your experience. Never worry about scaling or security again.',
    icon: Code2,
  },
  {
    title: 'Ship faster',
    description:
      'Appwrite reduces the time and resources spent building a backend infrastructure from scratch.',
    icon: Rocket,
  },
  {
    title: 'All in one platform',
    description: 'Everything you need to develop, deploy, and scale your applications.',
    icon: Layers,
  },
]

export const partnerTiers = [
  { title: 'Platinum', badge: '/images/partners/badges/platinum.svg' },
  { title: 'Gold', badge: '/images/partners/badges/gold.svg' },
  { title: 'Silver', badge: '/images/partners/badges/silver.svg' },
] as const

export const partnerWays = [
  {
    title: 'Experts',
    description:
      'For agencies, consultancies, freelancers, and integrators who want to provide a scalable backend solution for their clients. Partner with Appwrite to provide a highly custom solution with the newest technology.',
    href: '/partners',
    label: 'Find a Partner',
  },
  {
    title: 'Integrations',
    description:
      'For innovative software companies striving to create solutions that integrate seamlessly with our platform. Partner with Appwrite to create a better developer experience.',
    href: '/integrations',
    label: 'Find an Integration',
  },
] as const

export const partnerStats = [
  { value: '650k+', label: 'Community members' },
  { value: '50k+', label: 'GitHub stars' },
  { value: '900+', label: 'OSS Contributors' },
  { value: '300', label: 'Top GitHub projects' },
] as const

export const partnerFormBullets = [
  'Grow your business',
  'Work with the latest technology',
  'Deliver your clients a great experience',
] as const

export const PARTNERS_FORM_ID = 'apply'
