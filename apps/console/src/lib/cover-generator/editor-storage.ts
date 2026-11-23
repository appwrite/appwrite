import { normalizeCoverCardsAngledData } from '@/lib/cover-generator/cards-angled/constants'
import { normalizeCoverTableData } from '@/lib/cover-generator/table/constants'
import {
  normalizeCoverBarChartData,
  normalizeCoverLineChartData,
} from '@/lib/cover-generator/chart/constants'
import { normalizeCoverCliCodeData } from '@/lib/cover-generator/cli-code/constants'
import { normalizeCoverCodeSnippetData } from '@/lib/cover-generator/code-snippet/constants'
import {
  createDefaultCoverData,
  parseCoverRenderData,
} from '@/lib/cover-generator/parse-params'
import type { CoverTemplateId } from '@/lib/cover-generator/constants'
import { isCoverTemplateId } from '@/lib/cover-generator/constants'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { COVER_SCREENSHOT_FRAME_WIDTH } from '@/lib/cover-generator/cover-frame-width'
import { COVER_SCREENSHOT_ANGLED_DEFAULTS } from '@/lib/perspective-screenshot-card/constants'
import { resolveCoverEditorThemeId } from '@/lib/cover-generator/themes'
import {
  migrateCoverImageDataUrlsToIndexedDb,
} from '@/lib/cover-generator/editor-image-fields'

function normalizeStoredCoverData(data: CoverRenderData): CoverRenderData {
  const normalizedTheme: CoverRenderData = {
    ...data,
    theme: resolveCoverEditorThemeId(data.theme),
  }

  if (normalizedTheme.template === 'screenshot-angled') {
    const defaultPercent = COVER_SCREENSHOT_ANGLED_DEFAULTS.frameWidthPercent
    if (normalizedTheme.frameWidthPercent <= COVER_SCREENSHOT_FRAME_WIDTH.flatDefaultPercent) {
      return {
        ...normalizedTheme,
        frameWidthPercent: defaultPercent,
      }
    }
    return normalizedTheme
  }

  if (normalizedTheme.template === 'cards-angled') {
    return normalizeCoverCardsAngledData(normalizedTheme)
  }

  if (normalizedTheme.template === 'table') {
    return normalizeCoverTableData(normalizedTheme)
  }

  if (normalizedTheme.template === 'bar-chart') {
    return normalizeCoverBarChartData(normalizedTheme)
  }

  if (normalizedTheme.template === 'line-chart') {
    return normalizeCoverLineChartData(normalizedTheme)
  }

  if (normalizedTheme.template === 'cli-code') {
    return normalizeCoverCliCodeData(normalizedTheme)
  }

  if (normalizedTheme.template === 'code-snippet') {
    return normalizeCoverCodeSnippetData(normalizedTheme)
  }

  return normalizedTheme
}

/** Key: `console.coverGenerator.editor` (editor settings only; images use IndexedDB). */
export const COVER_GENERATOR_EDITOR_STORAGE_KEY = 'console.coverGenerator.editor'

import {
  parseCoverTemplateCategoryFilter,
  type CoverTemplateCategoryFilter,
} from '@/lib/cover-generator/template-categories'

/** @deprecated Use CoverTemplateCategoryFilter */
export type CoverTemplateTypeFilter = CoverTemplateCategoryFilter

export type CoverGeneratorEditorState = {
  data: CoverRenderData
  templateData: Partial<Record<CoverTemplateId, CoverRenderData>>
  imageFields: Record<string, string | undefined>
  typeFilter: CoverTemplateCategoryFilter
}

export type CoverGeneratorPersistedState = {
  data: CoverRenderData
  templateData?: Partial<Record<CoverTemplateId, CoverRenderData>>
  typeFilter: CoverTemplateCategoryFilter
}

function parseStoredTemplateData(
  value: unknown,
): Partial<Record<CoverTemplateId, CoverRenderData>> {
  if (!value || typeof value !== 'object') return {}

  const templateData: Partial<Record<CoverTemplateId, CoverRenderData>> = {}
  for (const [templateId, entry] of Object.entries(value as Record<string, unknown>)) {
    if (!isCoverTemplateId(templateId)) continue
    const parsed = parseStoredCoverData(entry)
    if (!parsed || parsed.template !== templateId) continue
    templateData[templateId] = normalizeStoredCoverData(parsed)
  }

  return templateData
}

function syncTemplateDataThemes(
  data: CoverRenderData,
  templateData: Partial<Record<CoverTemplateId, CoverRenderData>>,
): Partial<Record<CoverTemplateId, CoverRenderData>> {
  const theme = resolveCoverEditorThemeId(data.theme)
  const synced = applyThemeToStoredTemplateData(templateData, theme)
  synced[data.template] = { ...data, theme }
  return synced
}

function applyThemeToStoredTemplateData(
  templateData: Partial<Record<CoverTemplateId, CoverRenderData>>,
  theme: ReturnType<typeof resolveCoverEditorThemeId>,
): Partial<Record<CoverTemplateId, CoverRenderData>> {
  const next: Partial<Record<CoverTemplateId, CoverRenderData>> = {}

  for (const [templateId, entry] of Object.entries(templateData)) {
    if (!entry || !isCoverTemplateId(templateId)) continue
    next[templateId] = { ...entry, theme }
  }

  return next
}

function buildPersistedTemplateData(
  data: CoverRenderData,
  templateData: Partial<Record<CoverTemplateId, CoverRenderData>>,
): Partial<Record<CoverTemplateId, CoverRenderData>> {
  return syncTemplateDataThemes(data, {
    ...templateData,
    [data.template]: normalizeStoredCoverData(data),
  })
}

export function restoreCoverRenderDataFromJson(
  value: unknown,
): CoverRenderData | null {
  const parsed = parseStoredCoverData(value)
  return parsed ? normalizeStoredCoverData(parsed) : null
}

function parseStoredCoverData(value: unknown): CoverRenderData | null {
  if (!value || typeof value !== 'object') return null

  const params = new URLSearchParams()
  for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
    if (entry == null || entry === '') continue
    params.set(key, String(entry))
  }

  if (!params.get('template')) return null

  try {
    return parseCoverRenderData(params)
  } catch {
    return null
  }
}

function parseStoredImageFields(
  value: unknown,
): Record<string, string | undefined> {
  if (!value || typeof value !== 'object') return {}

  const fields: Record<string, string | undefined> = {}
  for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
    if (typeof entry === 'string' && entry.length > 0) {
      fields[key] = entry
    }
  }
  return fields
}

function parseStoredTypeFilter(value: unknown): CoverTemplateCategoryFilter {
  return parseCoverTemplateCategoryFilter(value)
}

function readLegacyImageFieldsFromLocalStorage(): Record<
  string,
  string | undefined
> {
  if (typeof window === 'undefined') return {}

  try {
    const raw = window.localStorage.getItem(COVER_GENERATOR_EDITOR_STORAGE_KEY)
    if (!raw) return {}

    const parsed = JSON.parse(raw) as { imageFields?: unknown }
    return parseStoredImageFields(parsed.imageFields)
  } catch {
    return {}
  }
}

function stripLegacyImageFieldsFromLocalStorage(
  persisted: CoverGeneratorPersistedState,
): void {
  if (typeof window === 'undefined') return

  try {
    window.localStorage.setItem(
      COVER_GENERATOR_EDITOR_STORAGE_KEY,
      JSON.stringify(persisted),
    )
  } catch {
    // Ignore when storage is unavailable.
  }
}

export function readCoverGeneratorEditorState(): CoverGeneratorPersistedState | null {
  if (typeof window === 'undefined') return null

  try {
    const raw = window.localStorage.getItem(COVER_GENERATOR_EDITOR_STORAGE_KEY)
    if (!raw) return null

    const parsed = JSON.parse(raw) as {
      data?: unknown
      templateData?: unknown
      typeFilter?: unknown
    }

    const data = parseStoredCoverData(parsed.data)
    if (!data) return null

    const normalizedData = normalizeStoredCoverData(data)
    const templateData = parseStoredTemplateData(parsed.templateData)

    return {
      data: normalizedData,
      templateData: syncTemplateDataThemes(normalizedData, {
        ...templateData,
        [normalizedData.template]: normalizedData,
      }),
      typeFilter: parseStoredTypeFilter(parsed.typeFilter),
    }
  } catch {
    return null
  }
}

export function writeCoverGeneratorEditorState(
  state: CoverGeneratorPersistedState,
): void {
  if (typeof window === 'undefined') return

  const persisted: CoverGeneratorPersistedState = {
    data: normalizeStoredCoverData(state.data),
    templateData: buildPersistedTemplateData(
      state.data,
      state.templateData ?? {},
    ),
    typeFilter: state.typeFilter,
  }

  try {
    window.localStorage.setItem(
      COVER_GENERATOR_EDITOR_STORAGE_KEY,
      JSON.stringify(persisted),
    )
  } catch {
    // Ignore when storage is unavailable.
  }
}

/** One-time migration of data URLs from localStorage into IndexedDB. */
export async function migrateLegacyCoverGeneratorImageFields(
  templateId: CoverTemplateId,
): Promise<void> {
  const legacyFields = readLegacyImageFieldsFromLocalStorage()
  if (Object.keys(legacyFields).length === 0) return

  await migrateCoverImageDataUrlsToIndexedDb(templateId, legacyFields)

  const persisted = readCoverGeneratorEditorState()
  if (persisted) {
    stripLegacyImageFieldsFromLocalStorage(persisted)
  }
}

export function getInitialCoverGeneratorEditorState(): CoverGeneratorEditorState {
  const persisted = readCoverGeneratorEditorState()
  const data = persisted?.data ?? createDefaultCoverData()
  const templateData = syncTemplateDataThemes(data, persisted?.templateData ?? {
    [data.template]: data,
  })

  return {
    data,
    templateData,
    imageFields: {},
    typeFilter: persisted?.typeFilter ?? 'all',
  }
}
