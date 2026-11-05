import type { ApiExplorerProjectPlatform } from '@/lib/api-explorer/types'
import {
  isReferencePlatform,
  isReferenceService,
  isReferenceVersion,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import {
  isReferenceNotFoundError,
} from '@/lib/docs/references/errors'

const JSON_HEADERS = {
  'Content-Type': 'application/json; charset=utf-8',
  'Cache-Control': 'public, max-age=300, stale-while-revalidate=3600',
} as const

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: JSON_HEADERS })
}

function notFoundResponse(): Response {
  return jsonResponse({ error: 'API reference not found' }, 404)
}

function badRequestResponse(message: string): Response {
  return jsonResponse({ error: message }, 400)
}

function internalErrorResponse(error: unknown): Response {
  const message =
    error instanceof Error ? error.message : 'Failed to load API reference data'
  console.error('[api-reference]', error)
  return jsonResponse({ error: message }, 500)
}

async function handleReferenceError(
  run: () => Promise<Response>,
): Promise<Response> {
  try {
    return await run()
  } catch (error) {
    if (isReferenceNotFoundError(error)) {
      return notFoundResponse()
    }
    return internalErrorResponse(error)
  }
}

export async function handleApiReferenceServiceRequest(
  request: Request,
): Promise<Response> {
  return handleReferenceError(async () => {
    const url = new URL(request.url)
    const version = url.searchParams.get('version') ?? ''
    const platform = url.searchParams.get('platform') ?? ''
    const service = url.searchParams.get('service') ?? ''

    if (
      !isReferenceVersion(version) ||
      !isReferencePlatform(platform) ||
      !isReferenceService(service)
    ) {
      return badRequestResponse('Invalid API reference service parameters')
    }

    const { loadApiReferenceService } = await import('./load-service')
    const result = await loadApiReferenceService(version, platform, service)
    if (!result) {
      return notFoundResponse()
    }

    return jsonResponse(result)
  })
}

export async function handleApiReferenceModelRequest(
  request: Request,
): Promise<Response> {
  return handleReferenceError(async () => {
    const url = new URL(request.url)
    const version = url.searchParams.get('version') ?? ''
    const model = url.searchParams.get('model') ?? ''

    if (!isReferenceVersion(version) || !model.trim()) {
      return badRequestResponse('Invalid API reference model parameters')
    }

    const { loadApiReferenceModel } = await import('./load-model')
    try {
      const result = await loadApiReferenceModel(version, model)
      if (!result) {
        return notFoundResponse()
      }
      return jsonResponse(result)
    } catch (error) {
      if (isReferenceNotFoundError(error)) {
        return notFoundResponse()
      }
      throw error
    }
  })
}

export async function handleReferenceNavCountsRequest(
  request: Request,
): Promise<Response> {
  return handleReferenceError(async () => {
    const url = new URL(request.url)
    const version = url.searchParams.get('version') ?? ''
    const mode = url.searchParams.get('mode') ?? ''

    if (!isReferenceVersion(version) || (mode !== 'client' && mode !== 'server')) {
      return badRequestResponse('Invalid API reference nav count parameters')
    }

    const { loadReferenceNavServiceCounts } = await import('./reference-nav')
    const counts = await loadReferenceNavServiceCounts(
      version,
      mode as ApiExplorerProjectPlatform,
    )
    return jsonResponse(Array.from(counts.entries()))
  })
}

export async function handleReferenceOpenApiSpecRequest(
  request: Request,
): Promise<Response> {
  return handleReferenceError(async () => {
    const url = new URL(request.url)
    const version = url.searchParams.get('version') ?? ''
    const mode = url.searchParams.get('mode') ?? ''

    if (!isReferenceVersion(version) || (mode !== 'client' && mode !== 'server')) {
      return badRequestResponse('Invalid OpenAPI spec parameters')
    }

    const { loadReferenceOpenApiSpecByMode } = await import('./load-spec')
    const spec = await loadReferenceOpenApiSpecByMode(
      version as ReferenceVersion,
      mode as ApiExplorerProjectPlatform,
    )
    return jsonResponse(spec)
  })
}
