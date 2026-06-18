import {
  isReferenceVersion,
} from '@/lib/docs/references/constants'
import { ReferenceNotFoundError } from '@/lib/docs/references/errors'
import { parseModelFromSpec } from '@/lib/docs/references/parse-model'
import type { ApiReferenceModelData } from '@/lib/docs/references/types'
import { loadReferenceConsoleSpec } from './load-spec'

export async function loadApiReferenceModel(
  version: string,
  modelId: string,
): Promise<ApiReferenceModelData | null> {
  if (!isReferenceVersion(version)) return null

  const spec = await loadReferenceConsoleSpec(version)
  const model = parseModelFromSpec(modelId, spec, version, {
    linkRelatedModels: true,
  })

  if (!model) {
    throw new ReferenceNotFoundError(`Model ${modelId} not found`)
  }

  return model
}
