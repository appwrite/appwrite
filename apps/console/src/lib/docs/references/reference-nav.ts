import {
  compareServices,
  getServiceLabel,
  isDatabaseApiServiceVisible,
  type DatabaseApiServiceFeatures,
} from '@/lib/api-explorer/services'
import {
  getDefaultReferencePlatform,
  getReferencePlatformForMode,
  isReferencePlatform,
  isReferenceService,
  isReferenceVersion,
  SERVICE_LABELS,
  type ReferencePlatform,
  type ReferenceService,
  type ReferenceVersion,
} from './constants'
import { API_REFERENCE_NAV_SERVICE_ORDER, API_REFERENCE_PRODUCT_GROUPS } from './product-groups'

export type ParsedApiReferencePath = {
  version: ReferenceVersion
  platform?: ReferencePlatform
  service?: ReferenceService
}

export type ReferenceNavService = {
  id: ReferenceService
  label: string
  methodCount: number
  href: string
}

export type ReferenceNavProductGroup = {
  id: string
  label: string
  services: ReferenceNavService[]
}

const SERVICE_PATH_PATTERN =
  /^\/docs\/references\/([^/]+)\/(client-[^/]+|server-[^/]+)\/([^/]+)$/

export function parseApiReferencePath(pathname: string): ParsedApiReferencePath | null {
  const normalized = pathname.replace(/\/+$/, '') || '/'

  const serviceMatch = normalized.match(SERVICE_PATH_PATTERN)
  if (serviceMatch) {
    const [, version, platform, service] = serviceMatch
    if (
      !isReferenceVersion(version) ||
      !isReferencePlatform(platform) ||
      !isReferenceService(service)
    ) {
      return null
    }
    return { version, platform, service }
  }

  const modelMatch = normalized.match(/^\/docs\/references\/([^/]+)\/models\/[^/]+$/)
  if (modelMatch) {
    const version = modelMatch[1]
    if (!isReferenceVersion(version)) return null
    return { version }
  }

  if (normalized === '/docs/references' || normalized.startsWith('/docs/references/')) {
    const versionMatch = normalized.match(/^\/docs\/references\/([^/]+)(?:\/|$)/)
    const version =
      versionMatch && isReferenceVersion(versionMatch[1]) ? versionMatch[1] : 'cloud'
    return { version }
  }

  return null
}

export function resolveReferenceVersionFromPath(pathname: string): ReferenceVersion {
  return parseApiReferencePath(pathname)?.version ?? 'cloud'
}

export function buildReferenceServiceHref(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  service: ReferenceService,
): string {
  return `/docs/references/${version}/${platform}/${service}`
}

function toReferenceNavService(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  serviceId: ReferenceService,
  serviceCounts: Map<ReferenceService, number>,
): ReferenceNavService {
  return {
    id: serviceId,
    label: SERVICE_LABELS[serviceId] ?? getServiceLabel(serviceId),
    methodCount: serviceCounts.get(serviceId) ?? 0,
    href: buildReferenceServiceHref(version, platform, serviceId),
  }
}

/** Flat service list in website sidebar order. */
export function buildReferenceNavServices(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  serviceCounts: Map<ReferenceService, number>,
  features?: DatabaseApiServiceFeatures,
): ReferenceNavService[] {
  const services: ReferenceNavService[] = []
  const assigned = new Set<ReferenceService>()

  for (const serviceId of API_REFERENCE_NAV_SERVICE_ORDER) {
    if (!serviceCounts.has(serviceId)) continue
    if (!isDatabaseApiServiceVisible(serviceId, features)) continue
    assigned.add(serviceId)
    services.push(toReferenceNavService(version, platform, serviceId, serviceCounts))
  }

  const remaining = Array.from(serviceCounts.keys())
    .filter((serviceId): serviceId is ReferenceService => {
      return (
        isReferenceService(serviceId) &&
        !assigned.has(serviceId) &&
        isDatabaseApiServiceVisible(serviceId, features)
      )
    })
    .sort(compareServices)
    .map((serviceId) =>
      toReferenceNavService(version, platform, serviceId, serviceCounts),
    )

  return [...services, ...remaining]
}

/** Product-grouped services for the API reference sidebar accordion. */
export function buildReferenceNavProductGroups(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  serviceCounts: Map<ReferenceService, number>,
  features?: DatabaseApiServiceFeatures,
): ReferenceNavProductGroup[] {
  const allServices = buildReferenceNavServices(
    version,
    platform,
    serviceCounts,
    features,
  )
  const serviceById = new Map(allServices.map((service) => [service.id, service]))
  const assigned = new Set<ReferenceService>()
  const groups: ReferenceNavProductGroup[] = []

  for (const group of API_REFERENCE_PRODUCT_GROUPS) {
    const services: ReferenceNavService[] = []
    for (const serviceId of group.services) {
      const service = serviceById.get(serviceId)
      if (!service) continue
      assigned.add(serviceId)
      services.push(service)
    }
    if (services.length > 0) {
      groups.push({ id: group.id, label: group.label, services })
    }
  }

  const remaining = allServices.filter((service) => !assigned.has(service.id))
  if (remaining.length > 0) {
    groups.push({ id: 'other', label: 'Other', services: remaining })
  }

  return groups
}

export function findReferenceNavProductGroupForService(
  groups: ReferenceNavProductGroup[],
  serviceId?: ReferenceService,
): ReferenceNavProductGroup | undefined {
  if (!serviceId) return undefined
  return groups.find((group) =>
    group.services.some((service) => service.id === serviceId),
  )
}

export function findFirstReferenceNavService(
  services: ReferenceNavService[],
): ReferenceNavService | undefined {
  return services[0]
}

export {
  getDefaultReferencePlatform,
  getReferencePlatformForMode,
}
