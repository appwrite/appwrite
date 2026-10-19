import type { LucideIcon } from 'lucide-react'
import type { ComponentType, SVGProps } from 'react'
import {
  ArrowRightLeft,
  Cloud,
  Gift,
  Globe,
  GraduationCap,
  Headphones,
  Rocket,
  Scale,
  Shield,
  Sparkles,
  TrendingUp,
  Zap,
} from 'lucide-react'
import { OpenSourceIcon } from '@/components/global/shared/icons/OpenSourceIcon'
import { marketingProductToolkit } from '@/lib/marketing/product-toolkit'

export type StartupsBenefit = {
  title: string
  description: string
  icon: LucideIcon | ComponentType<SVGProps<SVGSVGElement>>
}

export const startupsHero = {
  eyebrow: 'Startups Program',
  title: 'Build your startup with Appwrite',
  description:
    'The Appwrite Startups Program gives you an all-in-one platform to build and host your product, plus cloud credits, training, priority support, and founder swag.',
} as const

export type StartupsEligibilityCriterion = {
  title: string
  description: string
  icon: LucideIcon
}

export type StartupsEligibilityExclusion = {
  title: string
  description: string
}

export const startupsEligibility = {
  title: 'Who qualifies',
  description:
    'The Startups program is for product teams at an early stage of growth. Apply if your company meets the criteria below.',
  criteria: [
    {
      title: 'Product-focused startup',
      description:
        'You are building a software product or platform. The program is not open to agencies, consultancies, or resellers.',
      icon: Rocket,
    },
    {
      title: 'Early-stage company',
      description:
        'Your company is 5 years old or younger and has raised funding (pre-seed through Series A) or bootstrapped revenue up to $5M in annual recurring revenue.',
      icon: TrendingUp,
    },
    {
      title: 'Building on Appwrite Cloud',
      description:
        'You are starting a new project on Appwrite or migrating from another backend provider.',
      icon: ArrowRightLeft,
    },
  ] satisfies StartupsEligibilityCriterion[],
  proPlanCallout: {
    title: 'No funding or revenue yet?',
    description:
      'The Startups program is for companies with traction through funding or revenue. If you have neither, Appwrite Cloud Pro is the right place to start building.',
    ctaLabel: 'View Pro plan',
    ctaHref: '/pricing',
  },
  exclusions: {
    title: 'Outside the program',
    description:
      'The Startups program is reserved for early-stage product companies with funding or revenue. These profiles are usually not accepted.',
    items: [
      {
        title: 'Beyond early stage',
        description:
          'Companies older than 5 years, unless bootstrapped with up to $5M in annual recurring revenue.',
      },
      {
        title: 'Later-stage funding',
        description: 'VC-backed companies that have raised beyond Series A.',
      },
      {
        title: 'Bootstrapped above $5M ARR',
        description: 'Bootstrapped companies with more than $5M in annual recurring revenue.',
      },
      {
        title: 'Agencies and consultancies',
        description:
          'Businesses primarily offering services rather than building their own software product.',
      },
    ] satisfies StartupsEligibilityExclusion[],
  },
} as const

export const startupsTopBenefits: StartupsBenefit[] = [
  {
    title: 'Cloud credits',
    description: 'Save on development and cloud costs and reduce risk at an early stage.',
    icon: Cloud,
  },
  {
    title: 'Training',
    description:
      'Join workshops and training sessions to help your team build and scale with Appwrite.',
    icon: GraduationCap,
  },
  {
    title: 'Priority support',
    description: 'Get community support and priority support from the Appwrite team.',
    icon: Headphones,
  },
  {
    title: 'Founder swag',
    description: 'Get exclusive Appwrite swag for founders in the program.',
    icon: Gift,
  },
]

export const startupsPlatformBenefits: StartupsBenefit[] = [
  {
    title: 'All-in-one platform',
    description:
      'Use one platform for backend development and web hosting and reduce vendors.',
    icon: Globe,
  },
  {
    title: 'AI-powered development',
    description: "Connect your favorite AI productivity tools with Appwrite's MCP.",
    icon: Sparkles,
  },
  {
    title: 'Scale effortlessly',
    description:
      'From MVP to enterprise, our app scales automatically, letting you focus on your business goals.',
    icon: Rocket,
  },
  {
    title: 'Zero configuration development',
    description: 'Spin up your backend in minutes, deploy in seconds. Fast and simple.',
    icon: Zap,
  },
  {
    title: 'Built-in security',
    description: "Your users' data is safe from day one with Appwrite's built in security.",
    icon: Shield,
  },
  {
    title: 'Compliance',
    description: 'We adhere to all needed compliance: GDPR, HIPAA, CCPA, SOC-2.',
    icon: Scale,
  },
  {
    title: 'Open-source',
    description:
      'Your data is always yours. Want to migrate away? You can do so at any time.',
    icon: OpenSourceIcon,
  },
]

export const startupsToolkit = marketingProductToolkit

export const startupsCaseStudies = [
  {
    id: 'devkind',
    logo: '/images/logos/trusted-by/devkind.svg',
    headline: 'DevKind reduced development time by 60% and lowered server costs by 40%',
    blurb: 'A special thanks to Appwrite for providing robust features and seamless functionality.',
    name: 'Hassan Ahmed',
    title: 'Engineer at DevKind',
    avatar: '/images/testimonials/hassan.avif',
    storyUrl: '/blog/post/customer-story-storealert',
  },
  {
    id: 'langx',
    logo: '/images/logos/trusted-by/langx.svg',
    headline: 'LangX handled millions of requests using Appwrite',
    blurb: 'With its comprehensive suite of services, Appwrite emerged as an ideal choice for my needs.',
    name: 'Xue',
    title: 'Founder at LangX',
    avatar: '/images/testimonials/xue.avif',
    storyUrl: '/blog/post/customer-stories-langx',
  },
  {
    id: 'k-collect',
    logo: '/images/logos/trusted-by/k-collect.svg',
    logoMask: true,
    headline: 'K-Collect reduced infrastructure costs by 700%',
    blurb: 'A major impact that Appwrite made was the amount of time and stress saved.',
    name: "Ryan O'Connor",
    title: 'Founder at K-Collect',
    avatar: '/images/testimonials/ryan.avif',
    storyUrl: '/blog/post/customer-stories-kcollect',
  },
] as const

export const startupsFormBullets = [
  'Appwrite Cloud Pro for 12 months',
  'Cloud credits for Appwrite Cloud',
  'Unlimited team members',
  'Premium email support',
  'Workshops and training sessions for your team',
  'Private Slack channel with the Appwrite team',
  'Dedicated program manager',
  'Exclusive founder swag',
] as const

export const STARTUPS_FORM_ID = 'apply'

export type StartupsApplyStep = {
  title: string
  description: string
  href?: string
  label?: string
  external?: boolean
}

export const startupsApplySteps: StartupsApplyStep[] = [
  {
    title: 'Submit your application',
    description:
      'Share your name, email, company name, and website using the application form below.',
    href: `#${STARTUPS_FORM_ID}`,
    label: 'Go to application form',
  },
  {
    title: 'We review your eligibility',
    description:
      'Our team evaluates your application against the program criteria and follows up by email.',
  },
  {
    title: 'Get onboarded',
    description:
      'If accepted, we activate your program benefits and share next steps to build on Appwrite Cloud.',
    href: '/docs',
    label: 'Go to Appwrite Docs',
    external: true,
  },
]

export type StartupsFaqItem = {
  question: string
  answer: string
  links?: { label: string; href: string }[]
}

export const startupsFaqItems: StartupsFaqItem[] = [
  {
    question: 'How do I apply?',
    answer:
      'Complete the application form on this page with your full name, email, company name, and website.',
  },
  {
    question: 'What happens after I apply?',
    answer:
      'Our team reviews your application against the program criteria and follows up by email with onboarding steps if you are accepted.',
  },
  {
    question: 'Who is eligible to apply?',
    answer:
      'We welcome product-focused startups that are 5 years old or younger and have raised funding (pre-seed through Series A) or bootstrapped revenue up to $5M in annual recurring revenue. Companies with no funding and no revenue are not eligible. The program is not open to agencies, consultancies, or resellers.',
  },
  {
    question: 'What if we have no funding or revenue yet?',
    answer:
      'The Startups program requires evidence of traction through funding or revenue. If your company has neither, start with Appwrite Cloud Pro instead of applying here.',
    links: [{ label: 'View Pro plan', href: '/pricing' }],
  },
  {
    question: 'Does the Appwrite Startups program include web hosting?',
    answer:
      'Yes. Accepted startups can host websites and web apps with Appwrite Sites as part of the program.',
  },
  {
    question: "What's included in the Appwrite Startups program?",
    answer:
      'Program members receive Appwrite Cloud Pro for 12 months, cloud credits, unlimited team members, premium email support, training workshops, a private Slack channel with our team, a dedicated program manager, and founder swag.',
  },
  {
    question: 'What kind of support do we get?',
    answer:
      'You have access to the Appwrite community for technical questions. Program members also receive a private Slack channel with our support team and a dedicated program manager for non-technical questions.',
  },
  {
    question: 'What are the limits of the Pro plan?',
    answer:
      'The Appwrite Pro plan includes generous limits designed for production applications. Review our pricing page for a full overview of included resources and add-ons.',
  },
  {
    question: 'Are OTP SMS costs covered by Appwrite?',
    answer:
      'OTP SMS messages are billed per message sent. You can use mock phone numbers to test OTP functionality without incurring costs. See our documentation for regional SMS rates and pricing details.',
  },
  {
    question: 'What happens if we scale overnight?',
    answer:
      'Appwrite is built to handle large traffic spikes and grow with your application. During a major launch, we can also provide increased support and on-call help.',
  },
  {
    question: 'What if we need more resources than the Startups program offers?',
    answer:
      'If your needs grow beyond the program, contact us to discuss Enterprise options and a tailored plan for your organization.',
  },
  {
    question: 'I am already using another backend provider. How do I migrate to Appwrite?',
    answer:
      'Appwrite includes a migration tool to help you move from other platforms. Our team can also assist if you need additional help during your transition.',
  },
  {
    question: 'I need to sign a BAA. Can I do this with Appwrite?',
    answer:
      'Yes. We can provide a Business Associate Agreement for organizations that require it.',
  },
]
