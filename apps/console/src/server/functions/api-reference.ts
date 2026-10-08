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
  .validator(referenceServiceSchema)
  .handler(async ({ data }) => {
    const { loadApiReferenceService } = await import(
      '@/server/api-reference/load-service'
    )
    const { isReferenceNotFoundError } = await import(
      '@/lib/docs/references/errors'
    )
    try {
      const result = await loadApiReferenceService(
        data.version,
        data.platform,
        data.service,
      )
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

export const loadApiReferenceModelFn = createServerFn({ method: 'GET' })
  .validator(referenceModelSchema)
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
  .validator(referenceNavCountsSchema)
  .handler(async ({ data }) => {
    const { loadReferenceNavServiceCounts } = await import(
      '@/server/api-reference/reference-nav'
    )
    const { isReferenceNotFoundError } = await import(
      '@/lib/docs/references/errors'
    )
    try {
      const counts = await loadReferenceNavServiceCounts(data.version, data.mode)
      return Array.from(counts.entries()) as Array<[ReferenceService, number]>
    } catch (error) {
      if (isReferenceNotFoundError(error)) {
        throw new Error('API_REFERENCE_NOT_FOUND')
      }
      throw error
    }
  })

export const loadReferenceOpenApiSpecFn = createServerFn({ method: 'GET' })
  .validator(referenceOpenApiSpecSchema)
  .handler(async ({ data }) => {
    const { loadReferenceOpenApiSpecByMode } = await import(
      '@/server/api-reference/load-spec'
    )
    const { isReferenceVersion } = await import('@/lib/docs/references/constants')
    const { isReferenceNotFoundError } = await import(
      '@/lib/docs/references/errors'
    )
    if (!isReferenceVersion(data.version)) {
      throw new Error('API_REFERENCE_NOT_FOUND')
    }
    try {
      return loadReferenceOpenApiSpecByMode(data.version, data.mode)
    } catch (error) {
      if (isReferenceNotFoundError(error)) {
        throw new Error('API_REFERENCE_NOT_FOUND')
      }
      throw error
    }
  })
