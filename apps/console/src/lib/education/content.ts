import type { LucideIcon } from 'lucide-react'
import { Beaker, BookOpen, MessageCircle } from 'lucide-react'
import { EDUCATION_JOIN_PATH } from '@/lib/education/paths'

export type EducationFaqItem = {
  question: string
  answer: string
}

export type EducationFeature = {
  title: string
  description: string
  icon: LucideIcon
}

export const educationHero = {
  eyebrow: 'Education Program',
  title: 'Build your next project with Appwrite',
  description:
    'Join the Appwrite Education program in collaboration with the GitHub Student Developer Pack. Verified students get six months of Appwrite Cloud with Pro resources for free.',
  githubEducationUrl: 'https://github.com/education',
} as const

export const educationFeatureCards: EducationFeature[] = [
  {
    title: 'Develop your skills',
    description:
      'Get access to Appwrite Cloud and build your entire backend with Appwrite.',
    icon: BookOpen,
  },
  {
    title: 'Build with any framework',
    description:
      'Get six months of free access to build with Appwrite’s Education plan.',
    icon: Beaker,
  },
  {
    title: 'Join a vibrant community',
    description: 'Get community support in the Appwrite Discord server.',
    icon: MessageCircle,
  },
]

export const educationKickstart = {
  title: 'Kickstart your developer journey with Appwrite',
  paragraphs: [
    'Earn free access through GitHub Education to build your next project on Appwrite Cloud. Sign up for the GitHub Student Developer Pack to get six months of Appwrite Cloud with Pro resources.',
    "The Education plan is available only to students verified through the GitHub Student Developer Pack. It lasts six months, then you can choose the plan that fits what you're building next.",
  ],
  image: '/images/education/kickstart.avif',
} as const

export const educationSteps = [
  {
    title: 'Enroll to the GitHub Student Developer Pack',
    description: 'Sign up for the Student Developer pack and explore the benefits.',
    href: 'https://github.com/education',
    label: 'Enroll on GitHub Education',
    external: true,
  },
  {
    title: 'Access the Education plan',
    description:
      'Create your Appwrite account through the Education program sign up page. Once verified, the Education plan will be applied to your account.',
    href: EDUCATION_JOIN_PATH,
    label: 'Sign up',
    external: false,
  },
  {
    title: 'Start from our docs',
    description:
      'Once your Appwrite account is created, go to our Docs and get started with Appwrite Cloud.',
    href: '/docs',
    label: 'Go to Appwrite Docs',
    external: false,
  },
] as const

export const educationCommunity = {
  title: 'Get help from the open source community',
  description:
    'Join a growing community of developers and students who use Appwrite to build their products. Gain access to a wealth of knowledge, support, and shared experiences needed to grow and advance your tech career.',
  discordUrl: '/discord',
} as const

export const educationFaqItems: EducationFaqItem[] = [
  {
    question: 'What is the Appwrite Education Program?',
    answer:
      "If you're a student with the GitHub Student Developer Pack, you can use the Appwrite Education plan free for six months to build your next project.",
  },
  {
    question: 'What does the Education plan offer?',
    answer:
      'Students with access to the Education plan can create 2 projects with equal usage limits as the Appwrite Pro plan (minus email support) at no cost. We also have a special channel for Education program members in the Appwrite Discord server for support, which will feature exclusive events, hackathons, etc.',
  },
  {
    question: 'Who is eligible to apply?',
    answer:
      "Any student enrolled in the GitHub Student Developer Pack can apply for free and receive Appwrite's Education plan for six months.",
  },
  {
    question: 'How do I apply?',
    answer:
      "If you're already enrolled in the GitHub Student Developer Pack, click the 'Sign up' button on this page and fill in your details. If you're not enrolled with GitHub Education yet, first apply for the GitHub Student Developer Pack, then come back and sign up to Appwrite Cloud here.",
  },
  {
    question: 'What happens after I sign up?',
    answer:
      'Appwrite Cloud will automatically verify your GitHub Student Developer Pack membership and apply the Education plan to your account. You can then start using Appwrite right away.',
  },
  {
    question: "I'm already an Appwrite user. Can I still apply?",
    answer:
      'This program is open to all Appwrite users who are verified members of the GitHub Student Developer Pack.',
  },
  {
    question: 'How long do the Appwrite Education program benefits last?',
    answer:
      'The Education plan lasts six months from the day you join, and each account can join once. The Console reminds you five weeks before your term ends.',
  },
  {
    question: 'What happens when my Education plan ends?',
    answer:
      "Before your term ends, choose the plan that fits what you're building next: Free for learning and side projects, Pro for apps that need more resources, or Start for students in India and Nepal. Your projects, data, and settings stay exactly as they are. If you don't choose a plan by the end date, your organization will be disabled until you select one. Projects are only deleted later, according to our standard retention process.",
  },
  {
    question: 'Does the Education plan include any add-ons?',
    answer: 'No, the Education plan does not cover any add-ons.',
  },
  {
    question: 'Can I use the Education plan for commercial purposes?',
    answer:
      'No, you may not use the Education plan for any non-educational or commercial purposes.',
  },
]

export const educationCta = {
  title: 'Start building like a team of hundreds with Appwrite',
  description:
    'Grow your skills with Pro resources on Appwrite Cloud, join a community of open-source contributors, and build with the framework of your choice.',
} as const
