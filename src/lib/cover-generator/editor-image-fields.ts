import { getCoverCardsAngledIconKeys } from '@/lib/cover-generator/cards-angled/constants'
import type { CoverTemplateId } from '@/lib/cover-generator/constants'
import {
  clearCoverImageFields,
  deleteCoverImageField,
  getAllCoverImageFields,
  saveCoverImageField,
} from '@/lib/cover-generator/editor-image-store'
import type { CoverRenderData } from '@/lib/cover-generator/types'

const COVER_IMAGE_STORAGE_KEY_SEPARATOR = '::'

export function getCoverImageStorageKey(
  templateId: CoverTemplateId,
  fieldKey: string,
  generationId?: string | null,
): string {
  const templateScope = generationId
    ? `${generationId}${COVER_IMAGE_STORAGE_KEY_SEPARATOR}${templateId}`
    : templateId
  return `${templateScope}${COVER_IMAGE_STORAGE_KEY_SEPARATOR}${fieldKey}`
}

export function isCoverUploadedImageValue(value: string): boolean {
  return value.startsWith('data:') || value.startsWith('blob:')
}

export function revokeCoverImageObjectUrl(url: string | undefined): void {
  if (url?.startsWith('blob:')) {
    URL.revokeObjectURL(url)
  }
}

export function revokeCoverImageObjectUrls(
  fields: Record<string, string | undefined>,
): void {
  for (const url of Object.values(fields)) {
    revokeCoverImageObjectUrl(url)
  }
}

export function dataUrlToBlob(dataUrl: string): Blob {
  const [header, base64] = dataUrl.split(',')
  if (!base64) {
    throw new Error('Invalid data URL')
  }

  const mime = header.match(/:(.*?);/)?.[1] ?? 'application/octet-stream'
  const bytes = atob(base64)
  const buffer = new Uint8Array(bytes.length)
  for (let index = 0; index < bytes.length; index += 1) {
    buffer[index] = bytes.charCodeAt(index)
  }
  return new Blob([buffer], { type: mime })
}

export async function loadAllCoverImageFieldUrls(): Promise<
  Record<string, string>
> {
  const stored = await getAllCoverImageFields()
  const fields: Record<string, string> = {}

  for (const [key, blob] of stored) {
    fields[key] = URL.createObjectURL(blob)
  }

  return fields
}

export async function loadCoverImageFieldUrlsForTemplate(
  templateId: CoverTemplateId,
  generationId?: string | null,
): Promise<Record<string, string>> {
  const stored = await getAllCoverImageFields()
  const fields: Record<string, string> = {}
  const prefix = generationId
    ? `${generationId}${COVER_IMAGE_STORAGE_KEY_SEPARATOR}${templateId}${COVER_IMAGE_STORAGE_KEY_SEPARATOR}`
    : `${templateId}${COVER_IMAGE_STORAGE_KEY_SEPARATOR}`

  for (const [storageKey, blob] of stored) {
    if (!storageKey.startsWith(prefix)) continue
    const fieldKey = storageKey.slice(prefix.length)
    if (!fieldKey) continue
    fields[fieldKey] = URL.createObjectURL(blob)
  }

  return fields
}

export async function migrateLegacyCoverImageStorageKeys(
  templateId: CoverTemplateId,
): Promise<void> {
  const stored = await getAllCoverImageFields()

  await Promise.all(
    [...stored.entries()].map(async ([storageKey, blob]) => {
      if (storageKey.includes(COVER_IMAGE_STORAGE_KEY_SEPARATOR)) return

      await saveCoverImageField(getCoverImageStorageKey(templateId, storageKey), blob)
      await deleteCoverImageField(storageKey)
    }),
  )
}

export async function persistCoverImageUpload(
  templateId: CoverTemplateId,
  fieldKey: string,
  source: File | Blob,
  generationId?: string | null,
): Promise<string> {
  await saveCoverImageField(
    getCoverImageStorageKey(templateId, fieldKey, generationId),
    source,
  )
  return URL.createObjectURL(source)
}

export async function persistCoverImageDataUrl(
  templateId: CoverTemplateId,
  fieldKey: string,
  dataUrl: string,
  generationId?: string | null,
): Promise<string> {
  const blob = dataUrlToBlob(dataUrl)
  return persistCoverImageUpload(templateId, fieldKey, blob, generationId)
}

export async function removeCoverImageField(
  templateId: CoverTemplateId,
  fieldKey: string,
  generationId?: string | null,
): Promise<void> {
  await deleteCoverImageField(
    getCoverImageStorageKey(templateId, fieldKey, generationId),
  )
}

export async function clearCoverImageFieldsForTemplate(
  templateId: CoverTemplateId,
  generationId?: string | null,
): Promise<void> {
  const stored = await getAllCoverImageFields()
  const prefix = generationId
    ? `${generationId}${COVER_IMAGE_STORAGE_KEY_SEPARATOR}${templateId}${COVER_IMAGE_STORAGE_KEY_SEPARATOR}`
    : `${templateId}${COVER_IMAGE_STORAGE_KEY_SEPARATOR}`

  await Promise.all(
    [...stored.keys()]
      .filter((storageKey) => storageKey.startsWith(prefix))
      .map((storageKey) => deleteCoverImageField(storageKey)),
  )
}

export async function clearAllCoverImageFields(): Promise<void> {
  await clearCoverImageFields()
}

export async function migrateCoverImageDataUrlsToIndexedDb(
  templateId: CoverTemplateId,
  imageFields: Record<string, string | undefined>,
): Promise<void> {
  await Promise.all(
    Object.entries(imageFields).map(async ([fieldKey, value]) => {
      if (!value?.startsWith('data:')) return
      await persistCoverImageDataUrl(templateId, fieldKey, value)
    }),
  )
}

export function getCoverGeneratorImageFieldKeys(
  data: CoverRenderData,
): string[] {
  switch (data.template) {
    case 'integration':
      return ['logoLeft', 'logoRight']
    case 'integration-icon':
    case 'showcase-icon':
    case 'title-icon':
      return ['icon']
    case 'screenshot':
    case 'screenshot-side':
    case 'screenshot-angled':
      return ['screenshot']
    case 'cards-angled':
      return getCoverCardsAngledIconKeys()
    default:
      return []
  }
}

async function blobUrlToDataUrl(blobUrl: string): Promise<string | undefined> {
  try {
    const response = await fetch(blobUrl)
    const blob = await response.blob()
    return await new Promise((resolve, reject) => {
      const reader = new FileReader()
      reader.onload = () => resolve(String(reader.result))
      reader.onerror = () => reject(reader.error)
      reader.readAsDataURL(blob)
    })
  } catch {
    return undefined
  }
}

async function resolveInlineCoverImageValue(
  value: string | undefined,
): Promise<string | undefined> {
  if (!value?.startsWith('blob:')) return value
  return blobUrlToDataUrl(value)
}

/** Replace browser-only blob URLs with data URLs for server/API cover rendering. */
export async function resolveCoverRenderDataInlineAssets(
  data: CoverRenderData,
): Promise<CoverRenderData> {
  const keys = getCoverGeneratorImageFieldKeys(data)
  let next: CoverRenderData | null = null

  await Promise.all(
    keys.map(async (key) => {
      const value = (data as Record<string, unknown>)[key] as string | undefined
      if (!value?.startsWith('blob:')) return

      const resolved = await resolveInlineCoverImageValue(value)
      if (!resolved || resolved === value) return

      if (!next) next = { ...data }
      ;(next as Record<string, unknown>)[key] = resolved
    }),
  )

  return next ?? data
}
