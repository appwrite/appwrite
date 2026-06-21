import { findMethodByOperationId } from './parse-spec'
import { groupServicesByProduct } from './services'
import type { ApiExplorerMethod, ApiExplorerService } from './types'

export function getDefaultExplorerMethod(
  services: ApiExplorerService[],
): ApiExplorerMethod | undefined {
  if (services.length === 0) return undefined

  const productGroups = groupServicesByProduct(services)
  for (const group of productGroups) {
    for (const service of group.services) {
      const firstMethod = service.methods[0]
      if (firstMethod) return firstMethod
    }
  }

  return services[0]?.methods[0]
}

export function resolveExplorerSelection(input: {
  visibleServices: ApiExplorerService[]
  initialServiceId?: string | null
  initialOperationId?: string | null
  preservedOperationId?: string | null
}): {
  method: ApiExplorerMethod | undefined
  serviceId: string | null
} {
  const { visibleServices, initialServiceId, initialOperationId, preservedOperationId } =
    input

  if (visibleServices.length === 0) {
    return { method: undefined, serviceId: null }
  }

  const initialMethod = findMethodByOperationId(
    visibleServices,
    initialOperationId ?? undefined,
  )
  if (initialMethod) {
    return { method: initialMethod, serviceId: initialMethod.service }
  }

  const preservedMethod = findMethodByOperationId(
    visibleServices,
    preservedOperationId ?? undefined,
  )
  if (preservedMethod) {
    return { method: preservedMethod, serviceId: preservedMethod.service }
  }

  const serviceFromUrl = initialServiceId
    ? visibleServices.find((service) => service.id === initialServiceId)
    : undefined

  if (serviceFromUrl?.methods[0]) {
    return {
      method: serviceFromUrl.methods[0],
      serviceId: serviceFromUrl.id,
    }
  }

  const defaultMethod = getDefaultExplorerMethod(visibleServices)
  if (defaultMethod) {
    return { method: defaultMethod, serviceId: defaultMethod.service }
  }

  return {
    method: undefined,
    serviceId: serviceFromUrl?.id ?? visibleServices[0]?.id ?? null,
  }
}
