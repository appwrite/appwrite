import type { ApiExplorerMethod } from '@/lib/api-explorer/types'

export type ApiReferenceParameter = {
  name: string
  description: string
  required: boolean
  type: string
}

export type ApiReferenceResponseModel = {
  id: string
  name: string
  properties: ApiReferenceModelProperty[]
}

export type ApiReferenceResponse = {
  code: number
  contentType?: string
  models: ApiReferenceResponseModel[]
}

export type ApiReferenceMethod = ApiExplorerMethod & {
  demo?: string
  responses: ApiReferenceResponse[]
}

export type ApiReferenceServiceData = {
  id: string
  label: string
  description: string
  methods: ApiReferenceMethod[]
}

export type ApiReferencePropertyTypeKind = 'array' | 'object' | 'scalar'

export type ApiReferenceModelProperty = {
  name: string
  typeKind: ApiReferencePropertyTypeKind
  /** Scalar type label, or `array` / `object` for container fields. */
  type: string
  /** When array items resolve to a single model. */
  itemType?: string
  variantCount?: number
  description: string
  relatedModels?: string
  /** Inline models for array items or object unions (e.g. columns list, indexes). */
  variants?: ApiReferenceResponseModel[]
}

export type ApiReferenceModelData = {
  id: string
  title: string
  properties: ApiReferenceModelProperty[]
  examples: Array<{ type: string; example: unknown }>
}
