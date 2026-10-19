import type { CoverApiEndpointMethod } from '@/lib/cover-generator/types'
import {
  COVER_BADGE_COLOR_IDS,
  COVER_BADGE_COLORS,
  DEFAULT_COVER_BADGE_COLOR,
} from '@/lib/cover-generator/themes'

/** Default content and limits for the extra cover templates. */

export const COVER_ANNOUNCEMENT_DEFAULTS = {
  badge: 'New',
  title: 'Introducing Appwrite Sites',
  subtitle: 'Deploy static sites and SSR apps straight from your repository',
} as const

export const COVER_BIG_TYPE_DEFAULTS = {
  title: 'build',
} as const

export const COVER_CHECKLIST_ITEMS = { min: 2, max: 5, default: 4 } as const

export const COVER_CHECKLIST_DEFAULTS = {
  title: 'Everything you need to ship',
  subtitle: 'One platform for your entire backend',
  items: [
    'Auth, databases, and storage',
    'Serverless functions at the edge',
    'Realtime subscriptions built in',
    'Messaging across every channel',
    'Encryption and compliance by default',
  ] as readonly string[],
} as const

export function getCoverChecklistItemKey(index: number): `item${number}` {
  return `item${index}`
}

export function getCoverChecklistItemKeys(): `item${number}`[] {
  return Array.from({ length: COVER_CHECKLIST_ITEMS.max }, (_, index) =>
    getCoverChecklistItemKey(index),
  )
}

export const COVER_NUMBERED_STEPS = { min: 2, max: 4, default: 3 } as const

export const COVER_NUMBERED_STEPS_DEFAULTS = {
  eyebrow: 'Get started',
  title: 'Ship your first project in minutes',
  stepTitles: [
    'Create a project',
    'Install the SDK',
    'Ship to production',
    'Invite your team',
  ] as readonly string[],
  stepDescriptions: [
    'Spin up a project in your preferred region.',
    'Add Appwrite to your app with one package.',
    'Deploy when you are ready, scale when you need.',
    'Collaborate with roles and permissions.',
  ] as readonly string[],
} as const

export function getCoverStepTitleKey(index: number): `stepTitle${number}` {
  return `stepTitle${index}`
}

export function getCoverStepDescriptionKey(index: number): `stepDescription${number}` {
  return `stepDescription${index}`
}

export function getCoverStepTitleKeys(): `stepTitle${number}`[] {
  return Array.from({ length: COVER_NUMBERED_STEPS.max }, (_, index) =>
    getCoverStepTitleKey(index),
  )
}

export function getCoverStepDescriptionKeys(): `stepDescription${number}`[] {
  return Array.from({ length: COVER_NUMBERED_STEPS.max }, (_, index) =>
    getCoverStepDescriptionKey(index),
  )
}

export const COVER_API_ENDPOINT_METHODS: readonly CoverApiEndpointMethod[] = [
  'GET',
  'POST',
  'PUT',
  'PATCH',
  'DELETE',
]

export const COVER_API_ENDPOINT_DEFAULTS = {
  title: 'Query your data with a single call',
  subtitle: 'REST and GraphQL APIs generated for every table',
  method: 'GET' as CoverApiEndpointMethod,
  path: '/v1/tablesdb/{databaseId}/tables/{tableId}/rows',
  status: '200 OK',
  frameWidthPercent: 82,
} as const

export function parseCoverApiEndpointMethod(value: string | null | undefined): CoverApiEndpointMethod {
  const normalized = value?.trim().toUpperCase()
  if (
    normalized &&
    (COVER_API_ENDPOINT_METHODS as readonly string[]).includes(normalized)
  ) {
    return normalized as CoverApiEndpointMethod
  }
  return COVER_API_ENDPOINT_DEFAULTS.method
}

export const COVER_CODE_DIFF_DEFAULTS = {
  title: 'What changed in 1.9',
  fileName: 'src/auth/session.ts',
  code: [
    '- const session = await account.get()',
    "+ const session = await account.getSession('current')",
    "+ await account.updatePrefs({ theme: 'dark' })",
    '  return session',
  ].join('\n'),
  frameWidthPercent: 82,
} as const

/** Max diff lines painted inside the window before clipping with an ellipsis row. */
export const COVER_CODE_DIFF_MAX_LINES = 9

export const COVER_STATUS_PILL_DEFAULTS = {
  eyebrow: 'Appwrite Sites',
  status: 'Generally available',
  title: 'Static and SSR hosting for every app',
} as const

export const COVER_COUNTDOWN_DEFAULTS = {
  eyebrow: 'Appwrite 2.0',
  title: 'The next chapter arrives in',
  days: 12,
  hours: 8,
  minutes: 34,
  seconds: 56,
  dateLabel: 'March 4, 2027 · 5PM UTC',
} as const

export const COVER_LOGO_MARQUEE_ICON_COUNT = 8

export const COVER_LOGO_MARQUEE_DEFAULTS = {
  title: 'Works with your stack',
  subtitle: 'First-class SDKs and integrations for every framework',
  icons: [
    '/icons/react.svg',
    '/icons/nextjs.svg',
    '/icons/vue.svg',
    '/icons/svelte.svg',
    '/icons/node.svg',
    '/icons/python.svg',
    '/icons/flutter.svg',
    '/icons/swift.svg',
  ] as readonly string[],
} as const

export function getCoverLogoMarqueeIconKey(index: number): `icon${number}` {
  return `icon${index}`
}

export function getCoverLogoMarqueeIconKeys(): `icon${number}`[] {
  return Array.from({ length: COVER_LOGO_MARQUEE_ICON_COUNT }, (_, index) =>
    getCoverLogoMarqueeIconKey(index),
  )
}

export const COVER_STATS_GRID = { min: 2, max: 4, default: 4 } as const

export const COVER_STATS_GRID_DEFAULTS = {
  title: 'Appwrite Cloud by the numbers',
  values: ['99.99%', '120K+', '40+', '1B+'] as readonly string[],
  labels: [
    'Uptime SLA',
    'Developers',
    'Regions',
    'API calls / month',
  ] as readonly string[],
} as const

export function getCoverStatValueKey(index: number): `statValue${number}` {
  return `statValue${index}`
}

export function getCoverStatLabelKey(index: number): `statLabel${number}` {
  return `statLabel${index}`
}

export function getCoverStatValueKeys(): `statValue${number}`[] {
  return Array.from({ length: COVER_STATS_GRID.max }, (_, index) =>
    getCoverStatValueKey(index),
  )
}

export function getCoverStatLabelKeys(): `statLabel${number}`[] {
  return Array.from({ length: COVER_STATS_GRID.max }, (_, index) =>
    getCoverStatLabelKey(index),
  )
}

export const COVER_METRIC_DELTA_TREND_POINTS = 8

export const COVER_METRIC_DELTA_DEFAULTS = {
  label: 'Monthly active users',
  value: '128K',
  delta: '+24.5%',
  deltaTone: 'up' as const,
  trend: [32, 45, 41, 58, 66, 61, 78, 92] as readonly number[],
} as const

export function getCoverMetricTrendKey(index: number): `trend${number}` {
  return `trend${index}`
}

export function getCoverMetricTrendKeys(): `trend${number}`[] {
  return Array.from(
    { length: COVER_METRIC_DELTA_TREND_POINTS },
    (_, index) => getCoverMetricTrendKey(index),
  )
}

export const COVER_DONUT_CHART_DEFAULTS = {
  title: 'Test coverage across services',
  subtitle: 'Monorepo-wide average, updated nightly',
  percent: 86,
  centerLabel: 'coverage',
  color: DEFAULT_COVER_BADGE_COLOR,
} as const

export const COVER_BADGE_COLOR_OPTIONS = COVER_BADGE_COLOR_IDS.map(
  (id) => ({
    value: id,
    label: COVER_BADGE_COLORS[id].label,
    swatchClass: COVER_BADGE_COLORS[id].swatchClass,
  }),
)

export const COVER_PROGRESS_BAR_DEFAULTS = {
  title: 'Migration progress',
  percent: 72,
  label: 'of projects moved to Appwrite Cloud',
  color: DEFAULT_COVER_BADGE_COLOR,
} as const

export const COVER_QUOTE_DEFAULTS = {
  quote:
    'Appwrite took our backend from months of work to an afternoon. It is the fastest way we have ever shipped.',
  authorName: 'Sara Lindqvist',
  authorRole: 'CTO, Northwind Labs',
} as const

export const COVER_BLOG_POST_DEFAULTS = {
  category: 'Engineering',
  title: 'How we cut cold starts by 80% across every region',
  authorName: 'Eldad Fux',
  date: 'Aug 22, 2026',
  readTime: '6 min read',
} as const

export const COVER_PODCAST_EPISODE_DEFAULTS = {
  episode: '42',
  title: 'The future of backend as a service',
  duration: '38 min',
  host: 'The Appwrite Podcast',
} as const

/** Deterministic waveform bar heights (0-100) for the podcast template. */
export const COVER_PODCAST_WAVEFORM_BARS = [
  28, 52, 74, 44, 88, 62, 36, 70, 94, 58, 40, 80, 66, 30, 50, 86, 72, 46, 60,
  90, 54, 34, 76, 64, 42, 82, 56, 38, 68, 92, 48, 62, 78, 36, 58, 84, 44, 70,
  52, 26,
] as const

export const COVER_EVENT_DEFAULTS = {
  month: 'MAR',
  day: '4',
  title: 'Appwrite Summit 2027',
  location: 'San Francisco + Online',
  cta: 'Register now',
} as const

export const COVER_PROFILE_CARD_DEFAULTS = {
  name: 'Eldad Fux',
  role: 'Founder & CEO, Appwrite',
  handle: '@eldadfux',
} as const

export const COVER_SOCIAL_POST_DEFAULTS = {
  name: 'Appwrite',
  handle: '@appwrite',
  time: '2h',
  text: 'Appwrite 1.9 is here: Sites hosting, faster Functions cold starts, and a brand new Console experience.',
  likes: '1.2K',
  comments: '184',
} as const
