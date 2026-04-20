/**
 * Project onboarding checklist: connect steps + per-product sub-steps.
 * Products are grouped under Build and Deploy (sidebar-aligned).
 */

export type ProductNavCategoryId = 'build' | 'deploy'

export type ProductGroupId =
  | 'auth'
  | 'database'
  | 'storage'
  | 'function'
  | 'messaging'
  | 'site'

export interface OnboardingSubStepDef {
  id: string
  /** Imperative headline: what to do next */
  label: string
  /** One line of context for the action */
  hint: string
  /** Primary button label when the step is not complete */
  cta: string
  /** Button label when complete; UI defaults to "Open" if omitted */
  ctaDone?: string
  to: string
  /** Shown when debug mode is on */
  debug: string
  isDone: (snapshot: ProjectOnboardingSnapshot) => boolean
  /**
   * When false, step is listed for navigation but excluded from global % / sidebar ring.
   */
  countsTowardProgress?: boolean
}

export interface OnboardingConnectStepDef {
  id: 'app' | 'apiKey'
  label: string
  hint: string
  cta: string
  ctaDone?: string
  to: string
  debug: string
  isDone: (snapshot: ProjectOnboardingSnapshot) => boolean
}

export interface OnboardingProductGroupDef {
  id: ProductGroupId
  label: string
  description: string
  /** UI badge; does not affect progress math */
  comingSoon?: boolean
  subSteps: OnboardingSubStepDef[]
}

export interface OnboardingProductCategoryDef {
  id: ProductNavCategoryId
  label: string
  groups: OnboardingProductGroupDef[]
}

export const ONBOARDING_CONNECT: OnboardingConnectStepDef[] = [
  {
    id: 'app',
    label: 'Register your app platform',
    hint: "Map your app's hostname or bundle ID so the SDK can reach this project.",
    cta: 'Register platform',
    ctaDone: 'Manage apps',
    to: '/projects/$projectId/apps',
    debug: 'Done when `listPlatforms` total (or platforms length) > 0.',
    isDone: (s) => s.platformTotal > 0,
  },
  {
    id: 'apiKey',
    label: 'Create a server API key',
    hint: 'Add a scoped secret for servers and CI; client apps use sessions instead.',
    cta: 'Create API key',
    ctaDone: 'Manage keys',
    to: '/projects/$projectId/api-keys',
    debug: 'Done when `listKeys` returns at least one key.',
    isDone: (s) => s.apiKeyCount > 0,
  },
]

const GROUP_AUTH: OnboardingProductGroupDef = {
  id: 'auth',
  label: 'Auth',
  description:
    'Sign users in, organize teams, and control who can access each part of your product.',
  subSteps: [
    {
      id: 'auth-users',
      label: 'Add your first user',
      hint: 'Register, import, or invite someone so Auth is in use.',
      cta: 'Add user',
      ctaDone: 'Manage users',
      to: '/projects/$projectId/auth',
      debug: 'Done when `users.list` total > 0.',
      isDone: (s) => s.userTotal > 0,
    },
    {
      id: 'auth-teams',
      label: 'Create a team',
      hint: 'Group users and assign roles for access control.',
      cta: 'Create team',
      ctaDone: 'Manage teams',
      to: '/projects/$projectId/auth',
      debug: 'Done when `teams.list` total > 0.',
      isDone: (s) => s.teamTotal > 0,
    },
  ],
}

const GROUP_DATABASE: OnboardingProductGroupDef = {
  id: 'database',
  label: 'Databases',
  description:
    'Store and query structured data - add indexes and vector search when you need them.',
  subSteps: [
    {
      id: 'db-database',
      label: 'Create a database',
      hint: 'Spin up a database (tables or documents) for your app data.',
      cta: 'Create database',
      ctaDone: 'Open databases',
      to: '/projects/$projectId/databases',
      debug:
        'Done when merged tables + documents + vector DB list total > 0.',
      isDone: (s) => s.databaseTotal > 0,
    },
    {
      id: 'db-schema',
      label: 'Define tables and load data',
      hint: 'Add collections or tables, attributes, and indexes; then insert rows.',
      cta: 'Set up schema',
      ctaDone: 'Open databases',
      to: '/projects/$projectId/databases',
      debug:
        'Same snapshot as database row until row-level counts are wired.',
      isDone: (s) => s.databaseTotal > 0,
    },
  ],
}

const GROUP_STORAGE: OnboardingProductGroupDef = {
  id: 'storage',
  label: 'Storage',
  description:
    'Upload files to buckets and serve or download them with secure, scoped access.',
  subSteps: [
    {
      id: 'st-bucket',
      label: 'Create a bucket',
      hint: 'Add a bucket and set who can read or write files.',
      cta: 'Create bucket',
      ctaDone: 'Manage buckets',
      to: '/projects/$projectId/storage',
      debug: 'Done when `storage.listBuckets` total > 0.',
      isDone: (s) => s.bucketTotal > 0,
    },
    {
      id: 'st-files',
      label: 'Upload a file',
      hint: 'Put an object in a bucket; use signed URLs or previews as needed.',
      cta: 'Upload files',
      ctaDone: 'Open storage',
      to: '/projects/$projectId/storage',
      debug: 'Same check as bucket until per-bucket file totals are used.',
      isDone: (s) => s.bucketTotal > 0,
    },
  ],
}

const GROUP_FUNCTION: OnboardingProductGroupDef = {
  id: 'function',
  label: 'Functions',
  description:
    'Run backend code on HTTP requests, schedules, or events from other services.',
  subSteps: [
    {
      id: 'fn-function',
      label: 'Create a function',
      hint: 'Add serverless code and choose a runtime.',
      cta: 'Create function',
      ctaDone: 'Manage functions',
      to: '/projects/$projectId/functions',
      debug: 'Done when `functions.list` total > 0.',
      isDone: (s) => s.functionTotal > 0,
    },
    {
      id: 'fn-deploy',
      label: 'Ship a deployment',
      hint: 'Deploy your code so executions can run.',
      cta: 'Open deployments',
      ctaDone: 'View function',
      to: '/projects/$projectId/functions',
      debug:
        'Same check as function until deployment-specific totals are used.',
      isDone: (s) => s.functionTotal > 0,
    },
  ],
}

const GROUP_MESSAGING: OnboardingProductGroupDef = {
  id: 'messaging',
  label: 'Messaging',
  description:
    'Send email, push, and SMS by routing messages through topics and providers.',
  subSteps: [
    {
      id: 'msg-topic',
      label: 'Create a topic',
      hint: 'Add a channel for push, email, or SMS broadcasts.',
      cta: 'Create topic',
      ctaDone: 'Manage topics',
      to: '/projects/$projectId/messaging',
      debug: 'Done when `messaging.listTopics` total > 0.',
      isDone: (s) => s.topicTotal > 0,
    },
    {
      id: 'msg-provider',
      label: 'Add a provider',
      hint: 'Connect SMTP, FCM, APNS, or another provider to send messages.',
      cta: 'Add provider',
      ctaDone: 'Manage providers',
      to: '/projects/$projectId/messaging',
      debug: 'Done when `messaging.listProviders` total > 0.',
      isDone: (s) => s.providerTotal > 0,
    },
  ],
}

const GROUP_SITE: OnboardingProductGroupDef = {
  id: 'site',
  label: 'Sites',
  description:
    'Connect a Git repo and ship your frontend with builds, deploys, and custom domains.',
  subSteps: [
    {
      id: 'site-create',
      label: 'Create a site',
      hint: 'Connect a repository and configure your build.',
      cta: 'Create site',
      ctaDone: 'Manage sites',
      to: '/projects/$projectId/sites',
      debug: 'Done when `sites.list` total > 0.',
      isDone: (s) => s.siteTotal > 0,
    },
    {
      id: 'site-pipeline',
      label: 'Run a production deploy',
      hint: 'Ship a build to production and tune environments.',
      cta: 'Open deployments',
      ctaDone: 'View site',
      to: '/projects/$projectId/sites',
      debug: 'Same check as site until deploy-specific totals are used.',
      isDone: (s) => s.siteTotal > 0,
    },
  ],
}

export const ONBOARDING_PRODUCT_CATEGORIES: OnboardingProductCategoryDef[] = [
  {
    id: 'build',
    label: 'Build',
    groups: [
      GROUP_AUTH,
      GROUP_DATABASE,
      GROUP_STORAGE,
      GROUP_FUNCTION,
      GROUP_MESSAGING,
    ],
  },
  {
    id: 'deploy',
    label: 'Deploy',
    groups: [GROUP_SITE],
  },
]

/** Steps excluded from % / sidebar (e.g. not wired in snapshot yet). */
export function subStepCountsTowardProgress(sub: OnboardingSubStepDef): boolean {
  return sub.countsTowardProgress !== false
}

export function forEachTrackedProductSubStep(
  fn: (sub: OnboardingSubStepDef) => void,
): void {
  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
    for (const group of cat.groups) {
      for (const sub of group.subSteps) {
        if (subStepCountsTowardProgress(sub)) {
          fn(sub)
        }
      }
    }
  }
}

export function getAtomicOnboardingStepCount(): number {
  let n = ONBOARDING_CONNECT.length
  forEachTrackedProductSubStep(() => {
    n += 1
  })
  return n
}

export interface ProjectOnboardingSnapshot {
  platformTotal: number
  apiKeyCount: number
  userTotal: number
  teamTotal: number
  databaseTotal: number
  bucketTotal: number
  functionTotal: number
  topicTotal: number
  providerTotal: number
  siteTotal: number
}

/**
 * Mocked onboarding snapshot.
 *
 * The previous implementation fired one `list` call per resource type
 * (`users`, `teams`, `databases`, `buckets`, `functions`, `topics`,
 * `providers`, `sites`) with `limit=1` purely to read the `total` field —
 * which produced a burst of API calls on every project page (and again on
 * every window focus). That has been removed pending a proper aggregated
 * endpoint that returns all of these counts in a single call.
 *
 * For now we return zeros so the checklist renders in its initial state.
 * `projectId` is intentionally unused — kept on the signature so the
 * eventual real implementation slots in without touching callers.
 */
export async function fetchProjectOnboardingSnapshot(
  _projectId: string,
): Promise<ProjectOnboardingSnapshot> {
  return {
    platformTotal: 0,
    apiKeyCount: 0,
    userTotal: 0,
    teamTotal: 0,
    databaseTotal: 0,
    bucketTotal: 0,
    functionTotal: 0,
    topicTotal: 0,
    providerTotal: 0,
    siteTotal: 0,
  }
}

export function buildOnboardingStepDoneMap(
  snapshot: ProjectOnboardingSnapshot,
): Map<string, boolean> {
  const map = new Map<string, boolean>()
  for (const step of ONBOARDING_CONNECT) {
    map.set(step.id, step.isDone(snapshot))
  }
  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
    for (const group of cat.groups) {
      for (const sub of group.subSteps) {
        map.set(sub.id, sub.isDone(snapshot))
      }
    }
  }
  return map
}

export type OnboardingProductBreakdownRow = {
  id: string
  label: string
  completed: number
  total: number
}

/**
 * Per-product progress: Connect (platform + API key) plus each product group
 * (Auth, Databases, Storage, Functions, Messaging, Sites), using the same tracked-step
 * rules as {@link computeOnboardingProgress}.
 */
export function computeOnboardingProductBreakdown(
  snapshot: ProjectOnboardingSnapshot,
): OnboardingProductBreakdownRow[] {
  const connectCompleted = ONBOARDING_CONNECT.filter((step) =>
    step.isDone(snapshot),
  ).length

  const rows: OnboardingProductBreakdownRow[] = [
    {
      id: 'connect',
      label: 'Connect',
      completed: connectCompleted,
      total: ONBOARDING_CONNECT.length,
    },
  ]

  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
    for (const group of cat.groups) {
      let completed = 0
      let total = 0
      for (const sub of group.subSteps) {
        if (!subStepCountsTowardProgress(sub)) continue
        total += 1
        if (sub.isDone(snapshot)) completed += 1
      }
      rows.push({
        id: group.id,
        label: group.label,
        completed,
        total,
      })
    }
  }

  return rows
}

export function computeOnboardingProgress(snapshot: ProjectOnboardingSnapshot): {
  completedSteps: number
  totalSteps: number
  progress: number
} {
  let completed = 0
  let total = 0

  for (const step of ONBOARDING_CONNECT) {
    total += 1
    if (step.isDone(snapshot)) completed += 1
  }

  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
    for (const group of cat.groups) {
      for (const sub of group.subSteps) {
        if (!subStepCountsTowardProgress(sub)) continue
        total += 1
        if (sub.isDone(snapshot)) completed += 1
      }
    }
  }

  const progress =
    total === 0 ? 0 : Math.round((completed / total) * 100)
  return { completedSteps: completed, totalSteps: total, progress }
}
