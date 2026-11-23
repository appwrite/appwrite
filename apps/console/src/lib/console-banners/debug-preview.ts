import { useEffect, useState } from 'react'

import { POSTGRES_PROMO_BANNER_ID } from '@/lib/console-banners/catalog'

const STORAGE_KEY = 'debug:previewConsoleBanners'
const LEGACY_POSTGRES_PREVIEW_KEY = 'debug:previewPostgresPromoBanner'
const PREVIEW_EVENT = 'debugConsoleBannerPreviewChange'

function getStorage(): Storage | null {
  if (typeof window === 'undefined' || !window.localStorage) return null
  return window.localStorage
}

function migrateLegacyPostgresPreviewFlag(): void {
  const storage = getStorage()
  if (!storage) return
  if (storage.getItem(LEGACY_POSTGRES_PREVIEW_KEY) !== 'true') return
  storage.removeItem(LEGACY_POSTGRES_PREVIEW_KEY)
  const current = storage.getItem(STORAGE_KEY)
  const ids = current ? current.split(',').filter(Boolean) : []
  if (!ids.includes(POSTGRES_PROMO_BANNER_ID)) {
    ids.push(POSTGRES_PROMO_BANNER_ID)
    storage.setItem(STORAGE_KEY, ids.join(','))
  }
}

export function loadDebugConsoleBannerPreviewIds(): string[] {
  migrateLegacyPostgresPreviewFlag()
  const storage = getStorage()
  if (!storage) return []
  const raw = storage.getItem(STORAGE_KEY)
  if (!raw) return []
  return raw.split(',').filter(Boolean)
}

export function isDebugConsoleBannerPreviewEnabled(bannerId: string): boolean {
  return loadDebugConsoleBannerPreviewIds().includes(bannerId)
}

export function setDebugConsoleBannerPreviewEnabled(
  bannerId: string,
  enabled: boolean,
): void {
  const storage = getStorage()
  if (!storage) return
  const current = loadDebugConsoleBannerPreviewIds()
  const next = enabled
    ? current.includes(bannerId)
      ? current
      : [...current, bannerId]
    : current.filter((id) => id !== bannerId)
  if (next.length === 0) {
    storage.removeItem(STORAGE_KEY)
  } else {
    storage.setItem(STORAGE_KEY, next.join(','))
  }
  window.dispatchEvent(new CustomEvent(PREVIEW_EVENT))
}

export function clearAllDebugConsoleBannerPreviews(): void {
  const storage = getStorage()
  if (!storage) return
  storage.removeItem(STORAGE_KEY)
  window.dispatchEvent(new CustomEvent(PREVIEW_EVENT))
}

export function subscribeToDebugConsoleBannerPreviews(
  callback: () => void,
): () => void {
  if (typeof window === 'undefined') return () => undefined

  const handler = () => callback()
  window.addEventListener(PREVIEW_EVENT, handler)
  window.addEventListener('storage', handler)

  return () => {
    window.removeEventListener(PREVIEW_EVENT, handler)
    window.removeEventListener('storage', handler)
  }
}

export function useDebugConsoleBannerPreviews(): {
  previewIds: string[]
  isPreviewEnabled: (bannerId: string) => boolean
  setPreviewEnabled: (bannerId: string, enabled: boolean) => void
} {
  const [previewIds, setPreviewIds] = useState<string[]>([])

  useEffect(() => {
    setPreviewIds(loadDebugConsoleBannerPreviewIds())
    return subscribeToDebugConsoleBannerPreviews(() => {
      setPreviewIds(loadDebugConsoleBannerPreviewIds())
    })
  }, [])

  return {
    previewIds,
    isPreviewEnabled: (bannerId) => previewIds.includes(bannerId),
    setPreviewEnabled: setDebugConsoleBannerPreviewEnabled,
  }
}
