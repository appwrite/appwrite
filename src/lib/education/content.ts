import type { LucideIcon } from 'lucide-react'
import { Beaker, BookOpen, MessageCircle } from 'lucide-react'

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
    'Join the Appwrite Education program in collaboration with the GitHub Student Developer Pack. Students access Appwrite Cloud for free throughout their studies.',
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
      'Get free access to build with Appwrite’s Education plan, valid throughout your student career.',
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
    'Earn free access through GitHub Education to build your next project on Appwrite Cloud. Sign up for the GitHub Student Developer Pack to receive Appwrite Cloud for the duration of your studies.',
    'This credit is available only for users who are verified through the GitHub program as students. The plan is valid until you graduate from GitHub Education.',
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
    href: '/sign-up',
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
      "If you're a student with the GitHub Student Developer Pack, you can access the Appwrite Education plan for free while in school to help you build your next project.",
  },
  {
    question: 'What does the Education plan offer?',
    answer:
      'Students with access to the Education plan can create 2 projects with equal usage limits as the Appwrite Pro plan (minus email support) at no cost. We also have a special channel for Education program members in the Appwrite Discord server for support, which will feature exclusive events, hackathons, etc.',
  },
  {
    question: 'Who is eligible to apply?',
    answer:
      "Any student enrolled in the GitHub Student Developer Pack can apply for free and receive Appwrite's Education plan until graduation.",
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
      'Your access to the Appwrite Education plan is valid until you finish your studies and graduate from the GitHub Student Developer Pack.',
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
    'Develop your developer skills with Appwrite Pro, join a vibrant community of open-source contributors, and start building with a vast array of frameworks.',
} as const
