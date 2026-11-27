/**
 * Project onboarding checklist: connect steps + per-product sub-steps.
 * Products are grouped under Build, Deploy, Observe and Protect (sidebar-aligned).
 * Groups behind a Console feature flag (Init 5 launches) are listed only while
 * the flag is on: see {@link getOnboardingProductCategories}.
 *
 * Stage completion is driven by `sdk.forConsole.projects.listStages` (SDK method
 * keys from Appwrite `app/config/onboarding.php`). A UI step is done when at least
 * one of its `sdkKeys` is `completed` or `skipped`.
 */

import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'

export type ProductNavCategoryId = 'build' | 'deploy' | 'observe' | 'protect'

/** SDK method keys tracked for registering an app platform. */
export const ONBOARDING_PLATFORM_SDK_KEYS = [
  'project.createWebPlatform',
  'project.createAndroidPlatform',
  'project.createApplePlatform',
  'project.createWindowsPlatform',
  'project.createLinuxPlatform',
] as const

/** SDK method keys tracked for creating a database (any DB API). */
export const ONBOARDING_DATABASE_SDK_KEYS = [
  'tablesDB.create',
  'documentsDB.create',
  'vectorsDB.create',
  'databases.create',
] as const

/** SDK method keys tracked for schema + first row/document. */
export const ONBOARDING_DATABASE_SCHEMA_SDK_KEYS = [
  'tablesDB.createTable',
  'tablesDB.createRow',
  'documentsDB.createCollection',
  'documentsDB.createDocument',
  'vectorsDB.createCollection',
  'vectorsDB.createDocument',
  'databases.createCollection',
  'databases.createDocument',
] as const

/** SDK method keys tracked for function deployments. */
export const ONBOARDING_FUNCTION_DEPLOY_SDK_KEYS = [
  'functions.createDeployment',
  'functions.createTemplateDeployment',
  'functions.createVcsDeployment',
  'functions.updateFunctionDeployment',
] as const

/** SDK method keys tracked for messaging providers. */
export const ONBOARDING_MESSAGING_PROVIDER_SDK_KEYS = [
  'messaging.createMailgunProvider',
  'messaging.createSendgridProvider',
  'messaging.createSesProvider',
  'messaging.createResendProvider',
  'messaging.createSmtpProvider',
  'messaging.createSMTPProvider',
  'messaging.createBirdEmailProvider',
  'messaging.createMsg91Provider',
  'messaging.createTelesignProvider',
  'messaging.createTextmagicProvider',
  'messaging.createTwilioProvider',
  'messaging.createVonageProvider',
  'messaging.createBirdSMSProvider',
  'messaging.createFcmProvider',
  'messaging.createFCMProvider',
  'messaging.createApnsProvider',
  'messaging.createAPNSProvider',
] as const

/** SDK method keys tracked for site deployments. */
export const ONBOARDING_SITE_DEPLOY_SDK_KEYS = [
  'sites.createDeployment',
  'sites.createTemplateDeployment',
  'sites.createVcsDeployment',
  'sites.updateSiteDeployment',
] as const

/** SDK method keys tracked for playing a video (any master manifest). */
export const ONBOARDING_VIDEO_PLAYBACK_SDK_KEYS = [
  'videos.getHlsManifest',
  'videos.getDashManifest',
  'videos.getCmafHlsManifest',
  'videos.getCmafDashManifest',
] as const

/** SDK method keys tracked for creating a monitor (any probe or usage). */
export const ONBOARDING_MONITOR_SDK_KEYS = [
  'monitors.createHttpMonitor',
  'monitors.createDnsMonitor',
  'monitors.createTcpMonitor',
  'monitors.createSmtpMonitor',
  'monitors.createUsageMonitor',
] as const

export type OnboardingStageStatus = 'pending' | 'completed' | 'skipped'

export type OnboardingStepState = 'pending' | 'completed' | 'skipped'

export type ProductGroupId =
  | 'auth'
  | 'database'
  | 'storage'
  | 'function'
  | 'messaging'
  | 'video'
  | 'site'
  | 'analytics'
  | 'monitor'
  | 'review'

/** Console feature flags that gate a product group, as in the sidebar. */
export type OnboardingProductFeature =
  | 'videos'
  | 'analytics'
  | 'alerts'
  | 'reviews'

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
  /** Route params for dynamic segments (e.g. storage bucket placeholder). */
  params?: Record<string, string>
  /** Shown when debug mode is on */
  debug: string
  /** SDK method keys; step is done when any key is completed or skipped. */
  sdkKeys: readonly string[]
  /**
   * When false, step is listed for navigation but excluded from global % / sidebar ring.
   */
  countsTowardProgress?: boolean
}

export type OnboardingConnectDialogTab = 'cli' | 'mcp'

export interface OnboardingConnectStepDef {
  id: 'app' | 'apiKey' | 'cli' | 'mcp'
  label: string
  hint: string
  cta: string
  ctaDone?: string
  /** Console route for in-app navigation (omit when using `connectTab`). */
  to?: string
  /** Opens Connect dialog on this tab instead of navigating. */
  connectTab?: OnboardingConnectDialogTab
  debug: string
  /** SDK method keys; step is done when any key is completed or skipped. */
  sdkKeys: readonly string[]
  /**
   * When false, Build/Deploy sections stay locked until other Connect steps finish.
   * Default true.
   */
  gatesProductSections?: boolean
  /** When false, excluded from global % / sidebar ring. Default true. */
  countsTowardProgress?: boolean
}

export interface OnboardingProductGroupDef {
  id: ProductGroupId
  label: string
  description: string
  /** UI badge; does not affect progress math */
  comingSoon?: boolean
  /** Listed (and counted) only while this Console feature is on. */
  feature?: OnboardingProductFeature
  subSteps: OnboardingSubStepDef[]
}

export interface OnboardingProductCategoryDef {
  id: ProductNavCategoryId
  label: string
  groups: OnboardingProductGroupDef[]
}

export const ONBOARDING_CONNECT: OnboardingConnectStepDef[] = [
  {
    id: 'mcp',
    label: 'Connect your coding agent',
    hint: 'Install Appwrite MCP in Cursor, Claude Code, Codex, or VS Code so your agent can manage this project.',
    cta: 'Install MCP',
    ctaDone: 'Open MCP',
    connectTab: 'mcp',
    debug:
      'Done when `mcp.install` is completed (first successful MCP API request) or skipped.',
    sdkKeys: ['mcp.install'],
    gatesProductSections: false,
  },
  {
    id: 'app',
    label: 'Register your app platform',
    hint: "Map your app's hostname or bundle ID so the SDK can reach this project.",
    cta: 'Add platform',
    ctaDone: 'Manage apps',
    to: '/projects/$projectId/apps',
    debug: `Done when any of: ${ONBOARDING_PLATFORM_SDK_KEYS.join(', ')}.`,
    sdkKeys: ONBOARDING_PLATFORM_SDK_KEYS,
  },
  {
    id: 'apiKey',
    label: 'Create a server API key',
    hint: 'Add a scoped secret for servers and CI; client apps use sessions instead.',
    cta: 'Add API key',
    ctaDone: 'Manage keys',
    to: '/projects/$projectId/api-keys',
    debug:
      'Done when `project.createKey` or `organization.createProjectKey` is completed or skipped.',
    sdkKeys: ['project.createKey', 'organization.createProjectKey'],
  },
  {
    id: 'cli',
    label: 'Install the Appwrite CLI',
    hint: 'Use the CLI from your terminal to manage this project, deploy functions, and automate workflows.',
    cta: 'Install CLI',
    ctaDone: 'Open CLI',
    connectTab: 'cli',
    debug:
      'Done when `cli.install` is completed (first successful CLI API request) or skipped.',
    sdkKeys: ['cli.install'],
    gatesProductSections: false,
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
      to: '/projects/$projectId/auth/users',
      debug:
        'Done when any of `users.create`, `account.create`, or `account.createAnonymousSession` is completed or skipped.',
      sdkKeys: ['users.create', 'account.create', 'account.createAnonymousSession'],
    },
    {
      id: 'auth-teams',
      label: 'Create a team',
      hint: 'Group users and assign roles for access control.',
      cta: 'Create team',
      ctaDone: 'Manage teams',
      to: '/projects/$projectId/auth/teams',
      debug: 'Done when `teams.create` is completed or skipped.',
      sdkKeys: ['teams.create'],
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
      debug: `Done when any of: ${ONBOARDING_DATABASE_SDK_KEYS.join(', ')}.`,
      sdkKeys: ONBOARDING_DATABASE_SDK_KEYS,
    },
    {
      id: 'db-schema',
      label: 'Define tables and load data',
      hint: 'Add collections or tables, attributes, and indexes; then insert rows.',
      cta: 'Set up schema',
      ctaDone: 'Open databases',
      to: '/projects/$projectId/databases',
      debug: `Done when any of: ${ONBOARDING_DATABASE_SCHEMA_SDK_KEYS.join(', ')}.`,
      sdkKeys: ONBOARDING_DATABASE_SCHEMA_SDK_KEYS,
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
      to: '/projects/$projectId/storage/$bucketId',
      params: { bucketId: '-' },
      debug: 'Done when `storage.createBucket` is completed or skipped.',
      sdkKeys: ['storage.createBucket'],
    },
    {
      id: 'st-files',
      label: 'Upload a file',
      hint: 'Put an object in a bucket; use signed URLs or previews as needed.',
      cta: 'Upload files',
      ctaDone: 'Open storage',
      to: '/projects/$projectId/storage/$bucketId',
      params: { bucketId: '-' },
      debug: 'Done when `storage.createFile` is completed or skipped.',
      sdkKeys: ['storage.createFile'],
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
      debug: 'Done when `functions.create` is completed or skipped.',
      sdkKeys: ['functions.create'],
    },
    {
      id: 'fn-deploy',
      label: 'Ship a deployment',
      hint: 'Deploy your code so executions can run.',
      cta: 'Open deployments',
      ctaDone: 'View function',
      to: '/projects/$projectId/functions',
      debug: `Done when any of: ${ONBOARDING_FUNCTION_DEPLOY_SDK_KEYS.join(', ')}.`,
      sdkKeys: ONBOARDING_FUNCTION_DEPLOY_SDK_KEYS,
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
      debug: 'Done when `messaging.createTopic` is completed or skipped.',
      sdkKeys: ['messaging.createTopic'],
    },
    {
      id: 'msg-provider',
      label: 'Add a provider',
      hint: 'Connect SMTP, FCM, APNS, or another provider to send messages.',
      cta: 'Add provider',
      ctaDone: 'Manage providers',
      to: '/projects/$projectId/messaging',
      debug: `Done when any messaging provider create method is completed or skipped.`,
      sdkKeys: ONBOARDING_MESSAGING_PROVIDER_SDK_KEYS,
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
      debug: 'Done when `sites.create` is completed or skipped.',
      sdkKeys: ['sites.create'],
    },
    {
      id: 'site-pipeline',
      label: 'Run a production deploy',
      hint: 'Ship a build to production and tune environments.',
      cta: 'Open deployments',
      ctaDone: 'View site',
      to: '/projects/$projectId/sites',
      debug: `Done when any of: ${ONBOARDING_SITE_DEPLOY_SDK_KEYS.join(', ')}.`,
      sdkKeys: ONBOARDING_SITE_DEPLOY_SDK_KEYS,
    },
  ],
}

const GROUP_VIDEO: OnboardingProductGroupDef = {
  id: 'video',
  label: 'Videos',
  feature: 'videos',
  description:
    'Upload video once and stream it everywhere, with adaptive renditions and captions.',
  subSteps: [
    {
      id: 'video-upload',
      label: 'Upload a video',
      hint: 'Add a video file; Appwrite transcodes it into streaming renditions.',
      cta: 'Upload video',
      ctaDone: 'Manage videos',
      to: '/projects/$projectId/videos',
      debug: 'Done when `videos.create` is completed or skipped.',
      sdkKeys: ['videos.create'],
    },
    {
      id: 'video-captions',
      label: 'Add captions',
      hint: 'Attach captions in any language so every viewer can follow along.',
      cta: 'Add captions',
      ctaDone: 'Open videos',
      to: '/projects/$projectId/videos',
      debug: 'Done when `videos.createCaption` is completed or skipped.',
      sdkKeys: ['videos.createCaption'],
    },
    {
      id: 'video-play',
      label: 'Play it in your app',
      hint: 'Point your player at the HLS or DASH manifest to start streaming.',
      cta: 'Set up playback',
      ctaDone: 'Open videos',
      to: '/projects/$projectId/videos',
      debug: `Done when any of: ${ONBOARDING_VIDEO_PLAYBACK_SDK_KEYS.join(', ')}.`,
      sdkKeys: ONBOARDING_VIDEO_PLAYBACK_SDK_KEYS,
    },
  ],
}

const GROUP_ANALYTICS: OnboardingProductGroupDef = {
  id: 'analytics',
  label: 'Analytics',
  feature: 'analytics',
  description:
    'See who visits your sites and apps, where they come from, and what they do.',
  subSteps: [
    {
      id: 'analytics-property',
      label: 'Add a property',
      hint: 'Track a website, mobile app, or AI agent as its own property.',
      cta: 'Add property',
      ctaDone: 'Manage properties',
      to: '/projects/$projectId/analytics',
      // A site with analytics on gets its property without this call, so its
      // first event counts too.
      debug:
        'Done when `analytics.createProperty` or `analytics.createEvent` is completed or skipped.',
      sdkKeys: ['analytics.createProperty', 'analytics.createEvent'],
    },
    {
      id: 'analytics-event',
      label: 'Record your first visit',
      hint: 'Turn on analytics for a site, or send page views and events from your app.',
      cta: 'Start tracking',
      ctaDone: 'View traffic',
      to: '/projects/$projectId/analytics',
      debug: 'Done when `analytics.createEvent` is completed or skipped.',
      sdkKeys: ['analytics.createEvent'],
    },
  ],
}

const GROUP_MONITOR: OnboardingProductGroupDef = {
  id: 'monitor',
  label: 'Monitors',
  feature: 'alerts',
  description:
    'Watch your endpoints and usage, and get alerted the moment something needs you.',
  subSteps: [
    {
      id: 'monitor-create',
      label: 'Create a monitor',
      hint: 'Check an endpoint, a domain, or a usage limit on a schedule.',
      cta: 'Create monitor',
      ctaDone: 'Manage monitors',
      to: '/projects/$projectId/monitors',
      debug: `Done when any of: ${ONBOARDING_MONITOR_SDK_KEYS.join(', ')}.`,
      sdkKeys: ONBOARDING_MONITOR_SDK_KEYS,
    },
  ],
}

const GROUP_REVIEW: OnboardingProductGroupDef = {
  id: 'review',
  label: 'Code review',
  feature: 'reviews',
  description:
    'Get an AI review on every pull request, with findings that learn from your team.',
  subSteps: [
    {
      id: 'review-repository',
      label: 'Turn on code review',
      hint: 'Add a repository, and its next pull request gets a review.',
      cta: 'Add repository',
      ctaDone: 'Manage repositories',
      to: '/projects/$projectId/reviews/repositories',
      debug:
        'Done when `reviews.createRepository` or `reviews.updateInstallation` is completed or skipped.',
      sdkKeys: ['reviews.createRepository', 'reviews.updateInstallation'],
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
      GROUP_VIDEO,
    ],
  },
  {
    id: 'deploy',
    label: 'Deploy',
    groups: [GROUP_SITE],
  },
  {
    id: 'observe',
    label: 'Observe',
    groups: [GROUP_ANALYTICS, GROUP_MONITOR],
  },
  {
    id: 'protect',
    label: 'Protect',
    groups: [GROUP_REVIEW],
  },
]

/**
 * The categories this Console shows: groups whose feature is off are dropped,
 * and so is a category left empty. Progress counts only these, so a project
 * can still reach 100% while a product is switched off.
 */
export function getOnboardingProductCategories(
  isEnabled: (feature: OnboardingProductFeature) => boolean,
): OnboardingProductCategoryDef[] {
  return ONBOARDING_PRODUCT_CATEGORIES.map((category) => ({
    ...category,
    groups: category.groups.filter(
      (group) => !group.feature || isEnabled(group.feature),
    ),
  })).filter((category) => category.groups.length > 0)
}

/** Steps excluded from % / sidebar (e.g. not wired in snapshot yet). */
export function subStepCountsTowardProgress(sub: OnboardingSubStepDef): boolean {
  return sub.countsTowardProgress !== false
}

export function connectStepCountsTowardProgress(
  step: OnboardingConnectStepDef,
): boolean {
  return step.countsTowardProgress !== false
}

export function connectStepGatesProductSections(
  step: OnboardingConnectStepDef,
): boolean {
  return step.gatesProductSections !== false
}

export function forEachTrackedProductSubStep(
  categories: readonly OnboardingProductCategoryDef[],
  fn: (sub: OnboardingSubStepDef) => void,
): void {
  for (const cat of categories) {
    for (const group of cat.groups) {
      for (const sub of group.subSteps) {
        if (subStepCountsTowardProgress(sub)) {
          fn(sub)
        }
      }
    }
  }
}

export function getAtomicOnboardingStepCount(
  categories: readonly OnboardingProductCategoryDef[],
): number {
  let n = 0
  for (const step of ONBOARDING_CONNECT) {
    if (connectStepCountsTowardProgress(step)) n += 1
  }
  forEachTrackedProductSubStep(categories, () => {
    n += 1
  })
  return n
}

export interface ProjectOnboardingSnapshot {
  /** SDK method key → stage status from `projects.listStages`. */
  stagesBySdk: Record<string, OnboardingStageStatus | string>
}

function stageStatusFulfillsStep(status: string | undefined): boolean {
  return status === 'completed' || status === 'skipped'
}

export function getOnboardingStepState(
  snapshot: ProjectOnboardingSnapshot,
  sdkKeys: readonly string[],
): OnboardingStepState {
  let hasCompleted = false
  let hasSkipped = false
  for (const key of sdkKeys) {
    const status = snapshot.stagesBySdk[key]
    if (status === 'completed') hasCompleted = true
    else if (status === 'skipped') hasSkipped = true
  }
  if (hasCompleted) return 'completed'
  if (hasSkipped) return 'skipped'
  return 'pending'
}

export function isOnboardingStepDone(
  snapshot: ProjectOnboardingSnapshot,
  sdkKeys: readonly string[],
): boolean {
  return sdkKeys.some((key) =>
    stageStatusFulfillsStep(snapshot.stagesBySdk[key]),
  )
}

function stagesToSnapshot(stages: Models.Stage[]): ProjectOnboardingSnapshot {
  const stagesBySdk: Record<string, OnboardingStageStatus | string> = {}
  for (const stage of stages) {
    if (stage.sdk) {
      stagesBySdk[stage.sdk] = stage.status
    }
  }
  return { stagesBySdk }
}

export async function fetchProjectOnboardingSnapshot(
  projectId: string,
): Promise<ProjectOnboardingSnapshot> {
  const response = await sdk.forConsole.projects.listStages({ projectId })
  return stagesToSnapshot(response.stages ?? [])
}

export async function skipOnboardingSteps(
  projectId: string,
  sdkKeys: readonly string[],
): Promise<void> {
  await Promise.all(
    sdkKeys.map((stageId) =>
      sdk.forConsole.projects.updateStage({
        projectId,
        stageId,
        skip: true,
      }),
    ),
  )
}

export function buildOnboardingStepStateMap(
  snapshot: ProjectOnboardingSnapshot,
): Map<string, OnboardingStepState> {
  const map = new Map<string, OnboardingStepState>()
  for (const step of ONBOARDING_CONNECT) {
    map.set(step.id, getOnboardingStepState(snapshot, step.sdkKeys))
  }
  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
    for (const group of cat.groups) {
      for (const sub of group.subSteps) {
        map.set(sub.id, getOnboardingStepState(snapshot, sub.sdkKeys))
      }
    }
  }
  return map
}

/** @deprecated Prefer {@link buildOnboardingStepStateMap} for UI that distinguishes skipped. */
export function buildOnboardingStepDoneMap(
  snapshot: ProjectOnboardingSnapshot,
): Map<string, boolean> {
  const map = new Map<string, boolean>()
  for (const [id, state] of buildOnboardingStepStateMap(snapshot)) {
    map.set(id, state !== 'pending')
  }
  return map
}

export function getOnboardingGroupState(
  subStepIds: readonly string[],
  stepStates: Map<string, OnboardingStepState>,
): OnboardingStepState {
  if (subStepIds.length === 0) return 'pending'
  const states = subStepIds.map((id) => stepStates.get(id) ?? 'pending')
  if (states.some((s) => s === 'pending')) return 'pending'
  if (states.every((s) => s === 'skipped')) return 'skipped'
  if (states.every((s) => s === 'completed')) return 'completed'
  if (states.some((s) => s === 'completed')) return 'completed'
  return 'skipped'
}

export type OnboardingProductBreakdownRow = {
  id: string
  label: string
  completed: number
  total: number
}

/**
 * Per-product progress: Connect (platform + API key) plus each product group
 * shown in `categories`, using the same tracked-step rules as
 * {@link computeOnboardingProgress}.
 */
export function computeOnboardingProductBreakdown(
  snapshot: ProjectOnboardingSnapshot,
  categories: readonly OnboardingProductCategoryDef[],
): OnboardingProductBreakdownRow[] {
  const connectCompleted = ONBOARDING_CONNECT.filter((step) =>
    isOnboardingStepDone(snapshot, step.sdkKeys),
  ).length

  const rows: OnboardingProductBreakdownRow[] = [
    {
      id: 'connect',
      label: 'Connect',
      completed: connectCompleted,
      total: ONBOARDING_CONNECT.length,
    },
  ]

  for (const cat of categories) {
    for (const group of cat.groups) {
      let completed = 0
      let total = 0
      for (const sub of group.subSteps) {
        if (!subStepCountsTowardProgress(sub)) continue
        total += 1
        if (isOnboardingStepDone(snapshot, sub.sdkKeys)) completed += 1
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

export function computeOnboardingProgress(
  snapshot: ProjectOnboardingSnapshot,
  categories: readonly OnboardingProductCategoryDef[],
): {
  completedSteps: number
  totalSteps: number
  progress: number
} {
  let completed = 0
  let total = 0

  for (const step of ONBOARDING_CONNECT) {
    if (!connectStepCountsTowardProgress(step)) continue
    total += 1
    if (isOnboardingStepDone(snapshot, step.sdkKeys)) completed += 1
  }

  for (const cat of categories) {
    for (const group of cat.groups) {
      for (const sub of group.subSteps) {
        if (!subStepCountsTowardProgress(sub)) continue
        total += 1
        if (isOnboardingStepDone(snapshot, sub.sdkKeys)) completed += 1
      }
    }
  }

  const progress =
    total === 0 ? 0 : Math.round((completed / total) * 100)
  return { completedSteps: completed, totalSteps: total, progress }
}
