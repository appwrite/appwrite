import { EmbeddingModel } from '@appwrite.io/console'

export const EMBEDDING_DIMENSION_CUSTOM = 'custom' as const

export type EmbeddingDimensionPresetId =
  | EmbeddingModel
  | typeof EMBEDDING_DIMENSION_CUSTOM

export const EMBEDDING_DIMENSION_PRESETS: {
  id: EmbeddingModel
  label: string
  vendor: string
  dimension: number
  description: string
}[] = [
  {
    id: EmbeddingModel.Nomicembedtext,
    label: 'nomic-embed-text',
    vendor: 'Nomic AI',
    dimension: 768,
    description:
      'General-purpose English embeddings with an 8K context window.',
  },
  {
    id: EmbeddingModel.Embeddinggemma,
    label: 'embedding-gemma',
    vendor: 'Google',
    dimension: 768,
    description:
      'Multilingual embeddings for 100+ languages, optimized for edge deployment.',
  },
  {
    id: EmbeddingModel.Allminilm,
    label: 'all-minilm',
    vendor: 'Sentence Transformers',
    dimension: 384,
    description:
      'Lightweight and fast English embeddings for resource-constrained workloads.',
  },
  {
    id: EmbeddingModel.Bgesmall,
    label: 'bge-small',
    vendor: 'BAAI',
    dimension: 384,
    description:
      'Compact English embeddings with strong retrieval quality at low memory cost.',
  },
]

export const DEFAULT_EMBEDDING_DIMENSION_PRESET = EmbeddingModel.Nomicembedtext

export function getEmbeddingDimensionPreset(
  presetId: EmbeddingDimensionPresetId,
) {
  return EMBEDDING_DIMENSION_PRESETS.find((entry) => entry.id === presetId)
}

export function getEmbeddingDimensionPresetSearchValue(entry: {
  label: string
  vendor: string
  dimension: number
  description: string
}): string {
  return `${entry.label} ${entry.vendor} ${entry.dimension} ${entry.description}`
}

export function resolveEmbeddingDimension(
  presetId: EmbeddingDimensionPresetId,
  customDimension: string,
): number | null {
  if (presetId === EMBEDDING_DIMENSION_CUSTOM) {
    const dimension = Number(customDimension)
    if (!Number.isFinite(dimension) || dimension < 1 || !Number.isInteger(dimension)) {
      return null
    }
    return dimension
  }

  return getEmbeddingDimensionPreset(presetId)?.dimension ?? null
}
