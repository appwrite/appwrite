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
  'postgresql',
  'mysql',
  'mongo',
] as const

/** Console-only SDK services - never shown in the explorer or API reference nav. */
export const INTERNAL_API_SERVICES = [] as const

/**
 * Native database engines that only appear in the console OpenAPI
 * (`platforms: ["console"]`), not in client/server specs.
 * Merged into API reference and project explorer from the console spec.
 */
export const CONSOLE_ONLY_DATABASE_API_SERVICES = [
  'postgresql',
  'mysql',
  'mongo',
] as const

export type ConsoleOnlyDatabaseApiService =
  (typeof CONSOLE_ONLY_DATABASE_API_SERVICES)[number]

/** Database API services shown only when a matching console profile feature is enabled. */
export const FEATURE_GATED_DATABASE_API_SERVICES = [
  'documentsDB',
  'vectorsDB',
  ...CONSOLE_ONLY_DATABASE_API_SERVICES,
] as const

export type FeatureGatedDatabaseApiService =
  (typeof FEATURE_GATED_DATABASE_API_SERVICES)[number]

export type DatabaseApiServiceFeatures = Pick<
  ConsoleProfileFeatures,
  | 'dedicatedDbsDocumentsDB'
  | 'dedicatedDbsVectorsDB'
  | 'nativeDbsPostgres'
  | 'nativeDbsMySQL'
  | 'nativeDbsMongo'
>

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
      services: [
        'tablesDB',
        'documentsDB',
        'vectorsDB',
        'postgresql',
        'mysql',
        'mongo',
      ],
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
 * {@link FEATURE_GATED_DATABASE_API_SERVICES} are appended when their feature flags are on.
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

export function getFeatureGatedDatabaseApiServices(
  features: DatabaseApiServiceFeatures = getActiveProfileFeatures(),
): FeatureGatedDatabaseApiService[] {
  const services: FeatureGatedDatabaseApiService[] = []
  if (features.dedicatedDbsDocumentsDB) services.push('documentsDB')
  if (features.dedicatedDbsVectorsDB) services.push('vectorsDB')
  if (features.nativeDbsPostgres) services.push('postgresql')
  if (features.nativeDbsMySQL) services.push('mysql')
  if (features.nativeDbsMongo) services.push('mongo')
  return services
}

export function isInternalApiService(serviceId: string): boolean {
  return (INTERNAL_API_SERVICES as readonly string[]).includes(serviceId)
}

export function isFeatureGatedDatabaseApiService(
  serviceId: string,
): serviceId is FeatureGatedDatabaseApiService {
  return (FEATURE_GATED_DATABASE_API_SERVICES as readonly string[]).includes(
    serviceId,
  )
}

export function isConsoleOnlyDatabaseApiService(
  serviceId: string,
): serviceId is ConsoleOnlyDatabaseApiService {
  return (CONSOLE_ONLY_DATABASE_API_SERVICES as readonly string[]).includes(
    serviceId as ConsoleOnlyDatabaseApiService,
  )
}

/** Whether a service should appear in the explorer or API reference nav for the active profile. */
export function isDatabaseApiServiceVisible(
  serviceId: string,
  features: DatabaseApiServiceFeatures = getActiveProfileFeatures(),
): boolean {
  if (isInternalApiService(serviceId)) return false
  if (!isFeatureGatedDatabaseApiService(serviceId)) return true
  return getFeatureGatedDatabaseApiServices(features).includes(serviceId)
}

export function getProjectApiExplorerAllowedServices(
  features: DatabaseApiServiceFeatures = getActiveProfileFeatures(),
): string[] {
  return [
    ...PROJECT_API_EXPLORER_BASE_ALLOWED_SERVICES,
    ...getFeatureGatedDatabaseApiServices(features),
  ]
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
  postgresql: 'PostgreSQL',
  mysql: 'MySQL',
  mongo: 'MongoDB',
}

export function filterAllowedServices(
  services: ApiExplorerService[],
  allowedServices?: readonly string[],
): ApiExplorerService[] {
  const allowed = new Set(
    allowedServices ?? getProjectApiExplorerAllowedServices(),
  )
  return services.filter(
    (service) =>
      allowed.has(service.id) && !isInternalApiService(service.id),
  )
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
