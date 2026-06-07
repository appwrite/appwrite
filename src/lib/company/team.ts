export const companyTeamIntro = {
  title: 'Team Appwrite',
  description:
    'We are a remote-first, AI-native team built to stay lean. We recruit exceptional talent worldwide and focus on efficient communication and human-AI collaboration to build more with less.',
} as const

export type CompanyTeamPhoto = {
  id: string
  src: string
  alt: string
  caption: string
  /** Tailwind grid placement classes for the team photo bento layout. */
  gridClassName?: string
  /** Wide group shots use a cinematic aspect ratio. */
  variant?: 'wide' | 'standard'
}

export const companyTeamPhotos: readonly CompanyTeamPhoto[] = [
  {
    id: 'camp-5-group',
    src: '/images/company/team-camp-5.png',
    alt: 'The Appwrite team at Camp 5.0',
    caption: 'Team Appwrite at Camp 5.0, Barcelona',
    gridClassName: 'sm:col-span-2 lg:col-span-6',
    variant: 'wide',
  },
  {
    id: 'camp-3-nyc',
    src: '/images/company/team-camp-3-nyc.png',
    alt: 'The Appwrite team at Camp 3.0 on a rooftop in New York City',
    caption: 'Team Appwrite at Camp 3.0, New York City',
    gridClassName: 'sm:col-span-2 lg:col-span-6',
    variant: 'wide',
  },
  {
    id: 'barcelona-cafe',
    src: '/images/company/team-barcelona-cafe.png',
    alt: 'Appwrite team members working together at a cafe in Barcelona',
    caption: 'Deep work over coffee in Barcelona during Camp',
    gridClassName: 'lg:col-span-4',
  },
  {
    id: 'init-prague-portraits',
    src: '/images/company/team-init-prague-portraits.png',
    alt: 'Appwrite team members during the first Init shoot in Prague',
    caption: 'Making of the first Init in Prague',
    gridClassName: 'lg:col-span-4',
  },
  {
    id: 'init-prague-monitor',
    src: '/images/company/team-init-prague-monitor.png',
    alt: 'On-set monitor during the first Init filming in Prague',
    caption: 'Making of the first Init in Prague',
    gridClassName: 'lg:col-span-4',
  },
] as const

export type CompanyTeamPillar = {
  id: string
  title: string
  body: string
}

export const companyTeamPillars: readonly CompanyTeamPillar[] = [
  {
    id: 'ai-native',
    title: 'AI-native team',
    body: 'Appwrite is built for developers, agents, and AI-assisted workflows, and our team works the same way. We integrate AI into how we plan, build, ship, and support, pairing human judgment with tooling that helps us move faster without adding unnecessary overhead.',
  },
  {
    id: 'lean',
    title: 'Lean and effective',
    body: 'We strive to stay a smaller team that punches above its weight. That means clear priorities, async-first communication, and workflows designed for effectiveness. We minimize bureaucracy, keep decision-making close to the work, and use AI where it saves time so people can focus on high-impact work.',
  },
  {
    id: 'global',
    title: 'Global and remote-first',
    body: 'We hire the best people wherever they are. Being remote-first keeps us thoughtful about communication across time zones and cultures. We stay humble, treat each other with respect, and work to help everyone on the team do their best work.',
  },
] as const

export type CompanyTeamFact = {
  id: string
  value: string
  label: string
}

export const companyTeamFacts: readonly CompanyTeamFact[] = [
  { id: 'countries', value: '15+', label: 'Countries' },
  { id: 'continents', value: '5', label: 'Continents' },
  { id: 'remote', value: '100%', label: 'Remote-first' },
  { id: 'structure', value: 'Flat', label: 'Org structure' },
  { id: 'communication', value: 'Efficient', label: 'Communication' },
  { id: 'ai', value: 'AI', label: 'Native' },
] as const

export const companyTeamLinks = {
  careers: 'https://appwrite.careers',
  evolutionBlog:
    'https://appwrite.io/blog/post/the-evolution-of-team-appwrite',
  cultureBlog:
    'https://appwrite.io/blog/post/building-culture-remote-camp',
} as const

export const companyTeamCta = {
  title: 'Join a lean, AI-native team',
  description:
    'Explore open roles or learn how Appwriters join from the community.',
} as const
