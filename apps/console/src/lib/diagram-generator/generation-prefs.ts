import { normalizeDiagramDocument } from '@/lib/diagram-generator/storage'
import type { DiagramDocument, DiagramTemplateId } from '@/lib/diagram-generator/types'

export const USER_PREFS_KEY_DIAGRAM_GENERATIONS = 'console.diagramGenerator.generations'

/** Local fallback when the user is not signed in. */
export const DIAGRAM_GENERATIONS_LOCAL_STORAGE_KEY =
  'console.diagramGenerator.generations'

export const MAX_SAVED_DIAGRAM_GENERATIONS = 30
export const MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH = 64

export type SavedDiagramGeneration = {
  id: string
  name: string
  updatedAt: number
  templateId?: DiagramTemplateId
  document: DiagramDocument
}

function isValidSavedDiagramGeneration(value: unknown): value is SavedDiagramGeneration {
  if (value == null || typeof value !== 'object') return false
  const item = value as SavedDiagramGeneration
  return (
    typeof item.id === 'string' &&
    typeof item.name === 'string' &&
    typeof item.updatedAt === 'number' &&
    Number.isFinite(item.updatedAt) &&
    item.document != null &&
    typeof item.document === 'object'
  )
}

export function parseSavedDiagramGenerations(raw: unknown): SavedDiagramGeneration[] {
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
    .filter(isValidSavedDiagramGeneration)
    .map((item) => ({
      id: item.id,
      name: String(item.name).trim().slice(0, MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH) || 'Untitled diagram',
      updatedAt: item.updatedAt,
      ...(item.templateId ? { templateId: item.templateId } : {}),
      document: normalizeDiagramDocument(item.document),
    }))
    .sort((a, b) => b.updatedAt - a.updatedAt)
    .slice(0, MAX_SAVED_DIAGRAM_GENERATIONS)
}

export function buildSavedDiagramGenerationsPrefs(
  list: SavedDiagramGeneration[],
): Record<string, string> {
  return {
    [USER_PREFS_KEY_DIAGRAM_GENERATIONS]: JSON.stringify(
      list.slice(0, MAX_SAVED_DIAGRAM_GENERATIONS),
    ),
  }
}

/** Merge saved diagrams into account prefs before `updateAccountPrefs`. */
export function mergeDiagramGenerationsIntoPrefs(
  prefs: Record<string, unknown>,
  list: SavedDiagramGeneration[],
): Record<string, unknown> {
  return {
    ...prefs,
    ...buildSavedDiagramGenerationsPrefs(list),
  }
}

export function upsertSavedDiagramGeneration(
  list: SavedDiagramGeneration[],
  entry: SavedDiagramGeneration,
): SavedDiagramGeneration[] {
  const normalized: SavedDiagramGeneration = {
    id: entry.id,
    name:
      entry.name.trim().slice(0, MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH) ||
      'Untitled diagram',
    updatedAt: entry.updatedAt,
    ...(entry.templateId ? { templateId: entry.templateId } : {}),
    document: normalizeDiagramDocument(entry.document),
  }

  const next = [normalized, ...list.filter((item) => item.id !== entry.id)]
  return next
    .sort((a, b) => b.updatedAt - a.updatedAt)
    .slice(0, MAX_SAVED_DIAGRAM_GENERATIONS)
}

export function removeSavedDiagramGeneration(
  list: SavedDiagramGeneration[],
  id: string,
): SavedDiagramGeneration[] {
  return list.filter((item) => item.id !== id)
}
