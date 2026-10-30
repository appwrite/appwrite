/**
 * A row in the environment switcher: either the project itself (the default
 * environment, which has no environment document of its own) or one of the
 * environments listed under it. Every row is a project the Console can open.
 */
export interface ProjectEnvironmentOption {
  id: string
  name: string
  /** The project itself. It cannot be renamed or deleted from the switcher. */
  isDefault: boolean
}

/**
 * Dot colors for the switcher. The project itself is production and shows green;
 * every environment shows purple, whatever its name or id, so the two kinds are
 * told apart at a glance and nothing else is read into the color.
 */
export const PROJECT_ENVIRONMENT_DOT_CLASS_PRODUCTION = 'bg-emerald-500'
export const PROJECT_ENVIRONMENT_DOT_CLASS = 'bg-purple-500'

export function projectEnvironmentDotClass(
  environment: Pick<ProjectEnvironmentOption, 'isDefault'>,
): string {
  return environment.isDefault
    ? PROJECT_ENVIRONMENT_DOT_CLASS_PRODUCTION
    : PROJECT_ENVIRONMENT_DOT_CLASS
}

/**
 * Environments in the order the project saved in its prefs. An environment the
 * saved order does not know, created since it was saved, keeps its place after
 * the known ones; with no saved order the list is returned as given.
 */
export function sortProjectEnvironments<T extends { $id: string }>(
  environments: readonly T[],
  order: readonly string[],
): T[] {
  if (order.length === 0) return [...environments]
  const rank = new Map(order.map((id, index) => [id, index]))
  return [...environments].sort((a, b) => {
    const rankA = rank.get(a.$id)
    const rankB = rank.get(b.$id)
    if (rankA === undefined && rankB === undefined) return 0
    if (rankA === undefined) return 1
    if (rankB === undefined) return -1
    return rankA - rankB
  })
}

/**
 * What an environment can copy from its project, as the Console offers it. A
 * group copies its structure and settings; the ones that hold data can copy
 * that too. The API takes resource types, listed here per group; the labels
 * are wrapped in t() where they render.
 */
export type ProjectEnvironmentCopyGroupId =
  | 'databases'
  | 'storage'
  | 'auth'
  | 'functions'
  | 'sites'
  | 'messaging'
  | 'settings'

export interface ProjectEnvironmentCopyGroup {
  id: ProjectEnvironmentCopyGroupId
  label: string
  /** What the structure copy brings */
  description: string
  resources: readonly string[]
  /** What the group holds, copied only on request */
  data?: {
    label: string
    description?: string
    resources: readonly string[]
  }
}

export const PROJECT_ENVIRONMENT_COPY_GROUPS: readonly ProjectEnvironmentCopyGroup[] =
  [
    {
      id: 'databases',
      label: 'Databases',
      description: 'Databases, tables, columns and indexes',
      resources: [
        'database',
        'documentsdb',
        'vectorsdb',
        'table',
        'collection',
        'column',
        'attribute',
        'index',
      ],
      data: {
        label: 'Rows and documents',
        resources: ['row', 'document'],
      },
    },
    {
      id: 'storage',
      label: 'Storage',
      description: 'Buckets and their settings',
      resources: ['bucket'],
      data: {
        label: 'Files',
        resources: ['file'],
      },
    },
    {
      id: 'auth',
      label: 'Auth',
      description:
        'Teams, auth settings, security policies and OAuth2 providers',
      resources: ['team', 'auth-methods', 'policies', 'oauth2-provider'],
      data: {
        label: 'Users and memberships',
        description:
          'The newest 90 users, with their passwords. An environment holds 100 users at most, so 10 places stay free for test accounts.',
        resources: ['user', 'membership'],
      },
    },
    {
      id: 'functions',
      label: 'Functions',
      description: 'Functions, their variables and their active deployment',
      resources: ['function', 'environment-variable'],
      data: {
        label: 'Every deployment',
        resources: ['deployment'],
      },
    },
    {
      id: 'sites',
      label: 'Sites',
      description: 'Sites, their variables and their active deployment',
      resources: ['site', 'site-variable'],
      data: {
        label: 'Every deployment',
        resources: ['site-deployment'],
      },
    },
    {
      id: 'messaging',
      label: 'Messaging',
      description: 'Providers and topics',
      resources: ['provider', 'topic'],
    },
    {
      id: 'settings',
      label: 'Settings',
      description:
        'Platforms, SMTP, enabled services, project variables and email templates',
      resources: [
        'platform',
        'smtp',
        'project-variable',
        'project-protocols',
        'project-labels',
        'project-services',
        'project-email-template',
      ],
    },
  ]

export type ProjectEnvironmentCopySelection = Record<
  ProjectEnvironmentCopyGroupId,
  { structure: boolean; data: boolean }
>

/** Every group's structure, none of its data: a project-shaped environment without the data. */
export function defaultProjectEnvironmentCopySelection(): ProjectEnvironmentCopySelection {
  return Object.fromEntries(
    PROJECT_ENVIRONMENT_COPY_GROUPS.map((group) => [
      group.id,
      { structure: true, data: false },
    ]),
  ) as ProjectEnvironmentCopySelection
}

/**
 * The resource types to send for a selection, in group order. Data follows its
 * group: a group that is not copied brings no data, whatever its data flag says.
 */
export function projectEnvironmentCopyResources(
  selection: ProjectEnvironmentCopySelection,
): string[] {
  const resources: string[] = []

  for (const group of PROJECT_ENVIRONMENT_COPY_GROUPS) {
    const picked = selection[group.id]
    if (!picked?.structure) continue
    resources.push(...group.resources)
    if (picked.data && group.data) {
      resources.push(...group.data.resources)
    }
  }

  return Array.from(new Set(resources))
}

/**
 * How far a migration got, from its status counters: one object per resource
 * type with how many are pending, processing, succeeded, failed, skipped or
 * warned. Everything counted but pending and processing is done. The API and
 * realtime deliver the counters as a JSON string, whatever the model says.
 */
export function migrationProgress(statusCounters: unknown): number {
  if (typeof statusCounters === 'string') {
    try {
      statusCounters = JSON.parse(statusCounters)
    } catch {
      return 0
    }
  }
  if (!statusCounters || typeof statusCounters !== 'object') return 0

  let done = 0
  let total = 0

  for (const counters of Object.values(
    statusCounters as Record<string, unknown>,
  )) {
    if (!counters || typeof counters !== 'object') continue
    for (const [status, count] of Object.entries(
      counters as Record<string, unknown>,
    )) {
      if (typeof count !== 'number') continue
      total += count
      if (status !== 'pending' && status !== 'processing') done += count
    }
  }

  return total === 0 ? 0 : Math.round((done / total) * 100)
}
