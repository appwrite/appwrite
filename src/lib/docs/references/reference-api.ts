import { createIsomorphicFn } from '@tanstack/react-start'
import type { ApiExplorerProjectPlatform } from '@/lib/api-explorer/types'
import type { ReferencePlatform, ReferenceService, ReferenceVersion } from './constants'
import type {
  ApiReferenceModelData,
  ApiReferenceServiceData,
} from './types'
import type { OpenApiSpec } from '@/lib/api-explorer/types'

export const REFERENCE_API_PATHS = {
  service: '/references-api/service',
  navCounts: '/references-api/nav-counts',
  model: '/references-api/model',
  openApiSpec: '/references-api/open-api-spec',
} as const

type ReferenceNavCountsResponse = Array<[ReferenceService, number]>

// SSR invokes the same handlers without making a loopback HTTP request.
// The fixed origin only makes the internal Request URL absolute; it is not fetched.
const fetchReferenceResponse = createIsomorphicFn()
  .server(async (url: string): Promise<Response> => {
    const handlers = await import('@/server/api-reference/http-handlers')
    const request = new Request(new URL(url, 'http://localhost'))
    switch (new URL(request.url).pathname) {
      case REFERENCE_API_PATHS.service:
        return handlers.handleApiReferenceServiceRequest(request)
      case REFERENCE_API_PATHS.model:
        return handlers.handleApiReferenceModelRequest(request)
      case REFERENCE_API_PATHS.navCounts:
        return handlers.handleReferenceNavCountsRequest(request)
      case REFERENCE_API_PATHS.openApiSpec:
        return handlers.handleReferenceOpenApiSpecRequest(request)
      default:
        throw new Error('Unknown reference API path')
    }
  })
  .client((url: string): Promise<Response> =>
    fetch(url, { headers: { Accept: 'application/json' } }),
  )

async function fetchReferenceJson<T>(url: string): Promise<T> {
  const response = await fetchReferenceResponse(url)

  if (response.status === 404) {
    throw new Error('API_REFERENCE_NOT_FOUND')
  }

  if (!response.ok) {
    let message = `Reference API request failed (${response.status})`
    try {
      const body = (await response.json()) as { error?: string }
      if (body.error) message = body.error
    } catch {
      // Ignore malformed error bodies.
    }
    throw new Error(message)
  }

  return (await response.json()) as T
}

function buildReferenceApiUrl(
  path: string,
  params: Record<string, string>,
): string {
  const search = new URLSearchParams(params)
  return `${path}?${search.toString()}`
}

export async function fetchApiReferenceService(
  version: ReferenceVersion,
  platform: ReferencePlatform,
  service: ReferenceService,
): Promise<ApiReferenceServiceData> {
  return fetchReferenceJson<ApiReferenceServiceData>(
    buildReferenceApiUrl(REFERENCE_API_PATHS.service, {
      version,
      platform,
      service,
    }),
  )
}

export async function fetchApiReferenceModel(
  version: ReferenceVersion,
  model: string,
): Promise<ApiReferenceModelData> {
  return fetchReferenceJson<ApiReferenceModelData>(
    buildReferenceApiUrl(REFERENCE_API_PATHS.model, { version, model }),
  )
}

export async function fetchReferenceNavServiceCounts(
  version: ReferenceVersion,
  mode: ApiExplorerProjectPlatform,
): Promise<Map<ReferenceService, number>> {
  const entries = await fetchReferenceJson<ReferenceNavCountsResponse>(
    buildReferenceApiUrl(REFERENCE_API_PATHS.navCounts, { version, mode }),
  )
  return new Map(entries)
}

export async function fetchReferenceOpenApiSpec(
  version: ReferenceVersion,
  mode: ApiExplorerProjectPlatform,
): Promise<OpenApiSpec> {
  return fetchReferenceJson<OpenApiSpec>(
    buildReferenceApiUrl(REFERENCE_API_PATHS.openApiSpec, { version, mode }),
  )
}
