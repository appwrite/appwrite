/**
 * Project onboarding checklist: connect steps + per-product sub-steps.
 * Products are grouped under Build and Deploy (sidebar-aligned).
 *
 * Stage completion is driven by `sdk.forConsole.projects.listStages` (SDK method
 * keys from Appwrite `app/config/onboarding.php`). A UI step is done when at least
 * one of its `sdkKeys` is `completed` or `skipped`.
 */

import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'

export type ProductNavCategoryId = 'build' | 'deploy'

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
  'databases.create',
] as const

/** SDK method keys tracked for schema + first row/document. */
export const ONBOARDING_DATABASE_SCHEMA_SDK_KEYS = [
  'tablesDB.createTable',
  'tablesDB.createRow',
  'documentsDB.createCollection',
  'documentsDB.createDocument',
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
  'messaging.createMsg91Provider',
  'messaging.createTelesignProvider',
  'messaging.createTextmagicProvider',
  'messaging.createTwilioProvider',
  'messaging.createVonageProvider',
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

export type OnboardingStageStatus = 'pending' | 'completed' | 'skipped'

export type OnboardingStepState = 'pending' | 'completed' | 'skipped'

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

export interface OnboardingConnectStepDef {
  id: 'app' | 'apiKey'
  label: string
  hint: string
  cta: string
  ctaDone?: string
  to: string
  debug: string
  /** SDK method keys; step is done when any key is completed or skipped. */
  sdkKeys: readonly string[]
}

/**
 * Optional Connect checklist row: install Appwrite MCP in a coding agent.
 * Completion is client-local (localStorage), not an API stage.
 */
export const ONBOARDING_AGENT_STEP = {
  id: 'agent' as const,
  label: 'Connect your coding agent',
  hint: 'Install Appwrite MCP in Cursor, Claude Code, Codex, or VS Code so your agent can manage this project.',
  cta: 'Install MCP',
  ctaDone: 'Open MCP',
  debug: 'Done when the user opens Connect → MCP or skips this step (local only).',
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
    debug: 'Done when `project.createKey` is completed or skipped.',
    sdkKeys: ['project.createKey'],
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
      to: '/projects/$projectId/auth',
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
 * (Auth, Databases, Storage, Functions, Messaging, Sites), using the same tracked-step
 * rules as {@link computeOnboardingProgress}.
 */
export function computeOnboardingProductBreakdown(
  snapshot: ProjectOnboardingSnapshot,
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

  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
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

export function computeOnboardingProgress(snapshot: ProjectOnboardingSnapshot): {
  completedSteps: number
  totalSteps: number
  progress: number
} {
  let completed = 0
  let total = 0

  for (const step of ONBOARDING_CONNECT) {
    total += 1
    if (isOnboardingStepDone(snapshot, step.sdkKeys)) completed += 1
  }

  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
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
