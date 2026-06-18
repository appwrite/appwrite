import { createServerFn } from '@tanstack/react-start'
import { z } from 'zod'
import type { ReferenceService } from '@/lib/docs/references/constants'

const referenceServiceSchema = z.object({
  version: z.string(),
  platform: z.string(),
  service: z.string(),
})

const referenceModelSchema = z.object({
  version: z.string(),
  model: z.string(),
})

const referenceNavCountsSchema = z.object({
  version: z.string(),
  mode: z.enum(['client', 'server']),
})

const referenceOpenApiSpecSchema = z.object({
  version: z.string(),
  mode: z.enum(['client', 'server']),
})

export const loadApiReferenceServiceFn = createServerFn({ method: 'GET' })
  .inputValidator(referenceServiceSchema)
  .handler(async ({ data }) => {
    const { loadApiReferenceService } = await import(
      '@/server/api-reference/load-service'
    )
    const result = await loadApiReferenceService(
      data.version,
      data.platform,
      data.service,
    )
    if (!result) {
      throw new Error('API_REFERENCE_NOT_FOUND')
    }
    return result
  })

export const loadApiReferenceModelFn = createServerFn({ method: 'GET' })
  .inputValidator(referenceModelSchema)
  .handler(async ({ data }) => {
    const { loadApiReferenceModel } = await import(
      '@/server/api-reference/load-model'
    )
    const { isReferenceNotFoundError } = await import(
      '@/lib/docs/references/errors'
    )
    try {
      const result = await loadApiReferenceModel(data.version, data.model)
      if (!result) {
        throw new Error('API_REFERENCE_NOT_FOUND')
      }
      return result
    } catch (error) {
      if (isReferenceNotFoundError(error)) {
        throw new Error('API_REFERENCE_NOT_FOUND')
      }
      throw error
    }
  })

export const loadReferenceNavServiceCountsFn = createServerFn({ method: 'GET' })
  .inputValidator(referenceNavCountsSchema)
  .handler(async ({ data }) => {
    const { loadReferenceNavServiceCounts } = await import(
      '@/server/api-reference/reference-nav'
    )
    const counts = await loadReferenceNavServiceCounts(data.version, data.mode)
    return Array.from(counts.entries()) as Array<[ReferenceService, number]>
  })

export const loadReferenceOpenApiSpecFn = createServerFn({ method: 'GET' })
  .inputValidator(referenceOpenApiSpecSchema)
  .handler(async ({ data }) => {
    const { loadReferenceOpenApiSpecByMode } = await import(
      '@/server/api-reference/load-spec'
    )
    const { isReferenceVersion } = await import('@/lib/docs/references/constants')
    if (!isReferenceVersion(data.version)) {
      throw new Error('API_REFERENCE_NOT_FOUND')
    }
    return loadReferenceOpenApiSpecByMode(data.version, data.mode)
  })
