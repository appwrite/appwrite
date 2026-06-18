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

export type ApiReferenceModelProperty = {
  name: string
  type: string
  description: string
  relatedModels?: string
}

export type ApiReferenceModelData = {
  id: string
  title: string
  properties: ApiReferenceModelProperty[]
  examples: Array<{ type: string; example: unknown }>
}
