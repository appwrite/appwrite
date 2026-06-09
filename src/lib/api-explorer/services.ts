import type {
  ApiExplorerProductGroupDefinition,
  ApiExplorerService,
  ApiExplorerServiceProductGroup,
} from './types'
import {
  getActiveProfileFeatures,
  type ConsoleProfileFeatures,
} from '@/lib/console-profiles'

/**
 * Canonical Appwrite API services and display labels.
 * Mirrors website/src/lib/utils/references.ts service order and names.
 */
export const API_SERVICE_ORDER = [
  'account',
  'users',
  'teams',
  'databases',
  'tablesDB',
  'sites',
  'storage',
  'functions',
  'messaging',
  'tokens',
  'locale',
  'avatars',
  'presences',
  'project',
  'health',
  'graphql',
  'proxy',
  'migrations',
  'vcs',
  'console',
  'backups',
  'usage',
  'webhooks',
  'organization',
  'activities',
  'advisor',
  'documentsDB',
  'vectorsDB',
] as const

/**
 * Appwrite product groupings for the explorer services panel.
 * Only services returned by {@link getProjectApiExplorerAllowedServices} are shown.
 */
export const API_EXPLORER_PRODUCT_GROUPS: ApiExplorerProductGroupDefinition[] =
  [
    {
      id: 'auth',
      label: 'Auth',
      services: ['account', 'users', 'teams', 'presences'],
    },
    {
      id: 'databases',
      label: 'Databases',
      services: ['tablesDB', 'documentsDB', 'vectorsDB'],
    },
    {
      id: 'sites',
      label: 'Sites',
      services: ['sites'],
    },
    {
      id: 'storage',
      label: 'Storage',
      services: ['storage', 'tokens'],
    },
    {
      id: 'functions',
      label: 'Functions',
      services: ['functions'],
    },
    {
      id: 'messaging',
      label: 'Messaging',
      services: ['messaging'],
    },
    {
      id: 'platform',
      label: 'Platform',
      services: ['project', 'webhooks', 'proxy'],
    },
    {
      id: 'utilities',
      label: 'Utilities',
      services: ['locale', 'avatars'],
    },
  ]

/**
 * Base services visible in the project-scoped explorer.
 * {@link documentsDB} and {@link vectorsDB} are appended when their feature flags are on.
 */
export const PROJECT_API_EXPLORER_BASE_ALLOWED_SERVICES: readonly string[] = [
  'account',
  'users',
  'teams',
  'tokens',
  'tablesDB',
  'storage',
  'functions',
  'messaging',
  'sites',
  'avatars',
  'locale',
  'project',
  'webhooks',
  'proxy',
  'presences',
]

export function getProjectApiExplorerAllowedServices(
  features: Pick<
    ConsoleProfileFeatures,
    'dedicatedDbsDocumentsDB' | 'dedicatedDbsVectorsDB'
  > = getActiveProfileFeatures(),
): string[] {
  const allowed = [...PROJECT_API_EXPLORER_BASE_ALLOWED_SERVICES]
  if (features.dedicatedDbsDocumentsDB) allowed.push('documentsDB')
  if (features.dedicatedDbsVectorsDB) allowed.push('vectorsDB')
  return allowed
}

export const API_SERVICE_LABELS: Record<string, string> = {
  account: 'Account',
  avatars: 'Avatars',
  databases: 'Databases',
  tablesDB: 'TablesDB',
  functions: 'Functions',
  messaging: 'Messaging',
  health: 'Health',
  locale: 'Locale',
  presences: 'Presences',
  storage: 'Storage',
  teams: 'Teams',
  users: 'Users',
  sites: 'Sites',
  tokens: 'Tokens',
  project: 'Project',
  graphql: 'GraphQL',
  proxy: 'Proxy',
  migrations: 'Migrations',
  vcs: 'VCS',
  console: 'Console',
  backups: 'Backups',
  usage: 'Usage',
  webhooks: 'Webhooks',
  organization: 'Organization',
  activities: 'Activities',
  advisor: 'Advisor',
  documentsDB: 'DocumentsDB',
  vectorsDB: 'VectorsDB',
}

export function filterAllowedServices(
  services: ApiExplorerService[],
  allowedServices?: readonly string[],
): ApiExplorerService[] {
  const allowed = new Set(
    allowedServices ?? getProjectApiExplorerAllowedServices(),
  )
  return services.filter((service) => allowed.has(service.id))
}

export function groupServicesByProduct(
  services: ApiExplorerService[],
  productGroups: ApiExplorerProductGroupDefinition[] = API_EXPLORER_PRODUCT_GROUPS,
): ApiExplorerServiceProductGroup[] {
  const serviceById = new Map(services.map((service) => [service.id, service]))
  const assigned = new Set<string>()
  const groups: ApiExplorerServiceProductGroup[] = []

  for (const group of productGroups) {
    const groupServices = group.services
      .map((serviceId) => serviceById.get(serviceId))
      .filter((service): service is ApiExplorerService => Boolean(service))

    for (const service of groupServices) assigned.add(service.id)

    if (groupServices.length > 0) {
      groups.push({
        id: group.id,
        label: group.label,
        services: groupServices,
      })
    }
  }

  const ungrouped = services
    .filter((service) => !assigned.has(service.id))
    .sort((a, b) => compareServices(a.id, b.id))

  if (ungrouped.length > 0) {
    groups.push({
      id: 'other',
      label: 'Other',
      services: ungrouped,
    })
  }

  return groups
}

export function getServiceLabel(serviceId: string): string {
  const knownLabel = API_SERVICE_LABELS[serviceId]
  if (knownLabel) return knownLabel

  const caseInsensitiveKey = Object.keys(API_SERVICE_LABELS).find(
    (key) => key.toLowerCase() === serviceId.toLowerCase(),
  )
  if (caseInsensitiveKey) return API_SERVICE_LABELS[caseInsensitiveKey]

  return serviceId
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/[-_]/g, ' ')
    .split(' ')
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export function compareServices(a: string, b: string): number {
  const aIndex = API_SERVICE_ORDER.indexOf(a as (typeof API_SERVICE_ORDER)[number])
  const bIndex = API_SERVICE_ORDER.indexOf(b as (typeof API_SERVICE_ORDER)[number])
  if (aIndex !== -1 && bIndex !== -1) return aIndex - bIndex
  if (aIndex !== -1) return -1
  if (bIndex !== -1) return 1
  return a.localeCompare(b, undefined, { sensitivity: 'base' })
}
