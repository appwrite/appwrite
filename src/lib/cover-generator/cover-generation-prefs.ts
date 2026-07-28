import type { CoverTemplateId } from '@/lib/cover-generator/constants'
import {
  readCoverGeneratorEditorState,
  restoreCoverRenderDataFromJson,
} from '@/lib/cover-generator/editor-storage'
import { coverRenderDataToSearchParams } from '@/lib/cover-generator/parse-params'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { COVER_TEMPLATE_DEFINITIONS } from '@/lib/cover-generator/template-config'

export const USER_PREFS_KEY_COVER_GENERATIONS = 'console.coverGenerator.generations'

export const COVER_GENERATIONS_LOCAL_STORAGE_KEY =
  'console.coverGenerator.generations'

export const MAX_SAVED_COVER_GENERATIONS = 30
export const MAX_SAVED_COVER_GENERATION_NAME_LENGTH = 64

export type SavedCoverGeneration = {
  id: string
  name: string
  updatedAt: number
  templateId: CoverTemplateId
  data: CoverRenderData
}

function isValidSavedCoverGeneration(value: unknown): value is SavedCoverGeneration {
  if (value == null || typeof value !== 'object') return false
  const item = value as SavedCoverGeneration
  return (
    typeof item.id === 'string' &&
    typeof item.name === 'string' &&
    typeof item.updatedAt === 'number' &&
    Number.isFinite(item.updatedAt) &&
    typeof item.templateId === 'string' &&
    item.data != null &&
    typeof item.data === 'object'
  )
}

export function getCoverGenerationDisplayName(data: CoverRenderData): string {
  const record = data as Record<string, unknown>
  const title = typeof record.title === 'string' ? record.title.trim() : ''
  if (title) return title.slice(0, MAX_SAVED_COVER_GENERATION_NAME_LENGTH)

  const definition = COVER_TEMPLATE_DEFINITIONS.find(
    (entry) => entry.id === data.template,
  )
  return definition?.label ?? 'Cover'
}

export function coverTemplateHasDocumentTitle(data: CoverRenderData): boolean {
  const definition = COVER_TEMPLATE_DEFINITIONS.find((entry) => entry.id === data.template)
  return definition?.fields.some((field) => field.key === 'title') ?? false
}

export function resolveCoverEditorDocumentName(
  data: CoverRenderData,
  savedName?: string,
): string {
  if (coverTemplateHasDocumentTitle(data)) {
    return getCoverGenerationDisplayName(data)
  }

  const trimmed = savedName?.trim()
  if (trimmed) {
    return trimmed.slice(0, MAX_SAVED_COVER_GENERATION_NAME_LENGTH)
  }

  return getCoverGenerationDisplayName(data)
}

export function resolveCoverGenerationPersistName(
  data: CoverRenderData,
  savedName: string,
): string {
  if (coverTemplateHasDocumentTitle(data)) {
    return getCoverGenerationDisplayName(data)
  }

  return (
    savedName.trim().slice(0, MAX_SAVED_COVER_GENERATION_NAME_LENGTH) ||
    getCoverGenerationDisplayName(data)
  )
}

export function parseSavedCoverGenerations(raw: unknown): SavedCoverGeneration[] {
  let parsed: unknown = raw
  if (typeof raw === 'string') {
    const trimmed = raw.trim()
    if (!trimmed) return []
    try {
      parsed = JSON.parse(trimmed) as unknown
    } catch {
      return []
    }
  }

  if (!Array.isArray(parsed)) return []

  return parsed
    .filter(isValidSavedCoverGeneration)
    .flatMap((item) => {
      const data = restoreCoverRenderDataFromJson(item.data)
      if (!data) return []
      return [
        {
          id: item.id,
          name:
            item.name.trim().slice(0, MAX_SAVED_COVER_GENERATION_NAME_LENGTH) ||
            getCoverGenerationDisplayName(data),
          updatedAt: item.updatedAt,
          templateId: data.template,
          data,
        },
      ]
    })
    .sort((a, b) => b.updatedAt - a.updatedAt)
    .slice(0, MAX_SAVED_COVER_GENERATIONS)
}

/**
 * Persist only URL-param-safe string fields so prefs stay a flat JSON string
 * (no nested booleans/objects that can confuse account.updatePrefs payloads).
 */
function serializeCoverGenerationForPrefs(entry: SavedCoverGeneration) {
  const data = Object.fromEntries(coverRenderDataToSearchParams(entry.data).entries())
  return {
    id: entry.id,
    name: entry.name,
    updatedAt: entry.updatedAt,
    templateId: entry.templateId,
    data,
  }
}

export function buildSavedCoverGenerationsPrefs(
  list: SavedCoverGeneration[],
): Record<string, string> {
  return {
    [USER_PREFS_KEY_COVER_GENERATIONS]: JSON.stringify(
      list.slice(0, MAX_SAVED_COVER_GENERATIONS).map(serializeCoverGenerationForPrefs),
    ),
  }
}

/** Merge saved covers into account prefs before `updateAccountPrefs`. */
export function mergeCoverGenerationsIntoPrefs(
  prefs: Record<string, unknown>,
  list: SavedCoverGeneration[],
): Record<string, unknown> {
  return {
    ...prefs,
    ...buildSavedCoverGenerationsPrefs(list),
  }
}

export function upsertSavedCoverGeneration(
  list: SavedCoverGeneration[],
  entry: SavedCoverGeneration,
): SavedCoverGeneration[] {
  const normalized: SavedCoverGeneration = {
    id: entry.id,
    name:
      entry.name.trim().slice(0, MAX_SAVED_COVER_GENERATION_NAME_LENGTH) ||
      getCoverGenerationDisplayName(entry.data),
    updatedAt: entry.updatedAt,
    templateId: entry.data.template,
    data: entry.data,
  }

  return [normalized, ...list.filter((item) => item.id !== entry.id)]
    .sort((a, b) => b.updatedAt - a.updatedAt)
    .slice(0, MAX_SAVED_COVER_GENERATIONS)
}

export function removeSavedCoverGeneration(
  list: SavedCoverGeneration[],
  id: string,
): SavedCoverGeneration[] {
  return list.filter((item) => item.id !== id)
}

export function applyCoverGenerationName(
  data: CoverRenderData,
  name: string,
): CoverRenderData {
  if (!coverTemplateHasDocumentTitle(data)) {
    return data
  }

  return { ...data, title: name } as CoverRenderData
}

export function readLegacyCoverEditorGeneration(): SavedCoverGeneration | null {
  const persisted = readCoverGeneratorEditorState()
  if (!persisted) return null

  return {
    id: crypto.randomUUID(),
    name: getCoverGenerationDisplayName(persisted.data),
    updatedAt: Date.now(),
    templateId: persisted.data.template,
    data: persisted.data,
  }
}

export function clearLegacyCoverEditorLocalStorage(): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.removeItem('console.coverGenerator.editor')
  } catch {
    /* private mode */
  }
}
