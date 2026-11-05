/**
 * AVIF browser support detection.
 *
 * Detects whether the current browser can decode AVIF images so callers can
 * request AVIF variants (smaller, higher quality) of resources like site
 * deployment screenshots and gracefully fall back to the default format
 * (typically WebP/JPEG) on browsers without AVIF support.
 *
 * Detection works by attempting to decode a tiny inline AVIF image. The
 * result is cached for the lifetime of the page. The synchronous getter
 * returns `null` until detection completes; the React hook re-renders once
 * the result is known so URL-building code can switch to AVIF on the next
 * render pass.
 */
import { useEffect, useState } from 'react'
import { ImageFormat } from '@appwrite.io/console'

// Minimal valid 2x2 AVIF image used purely as a decoder probe.
const AVIF_PROBE_DATA_URL =
  'data:image/avif;base64,AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAADybWV0YQAAAAAAAAAoaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAGxpYmF2aWYAAAAADnBpdG0AAAAAAAEAAAAeaWxvYwAAAABEAAABAAEAAAABAAABGgAAAB0AAAAoaWluZgAAAAAAAQAAABppbmZlAgAAAAABAABhdjAxQ29sb3IAAAAAamlwcnAAAABLaXBjbwAAABRpc3BlAAAAAAAAAAIAAAACAAAAEHBpeGkAAAAAAwgICAAAAAxhdjFDgQ0MAAAAABNjb2xybmNseAACAAIAAYAAAAAXaXBtYQAAAAAAAAABAAEEAQKDBAAAACVtZGF0EgAKCBgANogQEAwgMg8f8D///8WfhwB8+ErK42A='

// Bumping this key invalidates persisted detections (e.g. if probe changes).
const STORAGE_KEY = 'appwrite:console:avif-support:v1'

function readPersistedSupport(): boolean | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (raw === '1') return true
    if (raw === '0') return false
  } catch {
    // localStorage unavailable (private mode, etc.)
  }
  return null
}

function persistSupport(supported: boolean): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(STORAGE_KEY, supported ? '1' : '0')
  } catch {
    // ignore
  }
}

let cachedSupport: boolean | null = readPersistedSupport()
let inflight: Promise<boolean> | null = null
const subscribers = new Set<(supported: boolean) => void>()

function notifySubscribers(supported: boolean) {
  for (const subscriber of subscribers) {
    try {
      subscriber(supported)
    } catch {
      // ignore subscriber errors
    }
  }
}

/**
 * Asynchronously detects AVIF support and caches the result. Repeat calls
 * return the cached value (or in-flight promise) without re-probing.
 */
export function detectAvifSupport(): Promise<boolean> {
  if (cachedSupport !== null) return Promise.resolve(cachedSupport)
  if (inflight) return inflight

  if (typeof window === 'undefined' || typeof Image === 'undefined') {
    cachedSupport = false
    return Promise.resolve(false)
  }

  inflight = new Promise<boolean>((resolve) => {
    const img = new Image()
    img.onload = () => {
      const supported = img.width > 0 && img.height > 0
      cachedSupport = supported
      inflight = null
      persistSupport(supported)
      notifySubscribers(supported)
      resolve(supported)
    }
    img.onerror = () => {
      cachedSupport = false
      inflight = null
      persistSupport(false)
      notifySubscribers(false)
      resolve(false)
    }
    img.src = AVIF_PROBE_DATA_URL
  })

  return inflight
}

/**
 * Returns the cached AVIF support flag. Returns `null` when detection has
 * not yet completed; callers that need to react to the result should use
 * {@link useAvifSupport} or await {@link detectAvifSupport}.
 */
export function isAvifSupportedSync(): boolean | null {
  return cachedSupport
}

/**
 * Returns the preferred image output format for browser-rendered images
 * (currently AVIF when supported, otherwise undefined to let the server
 * choose its default - typically WebP/JPEG).
 */
export function getPreferredImageFormat(): ImageFormat | undefined {
  return cachedSupport === true ? ImageFormat.Avif : undefined
}

/**
 * React hook that triggers AVIF detection on mount and re-renders when the
 * result becomes available. Returns `true` once AVIF support is confirmed.
 */
export function useAvifSupport(): boolean {
  const [supported, setSupported] = useState<boolean>(
    () => cachedSupport === true,
  )

  useEffect(() => {
    if (cachedSupport !== null) {
      setSupported(cachedSupport)
      return
    }

    let active = true
    const subscriber = (value: boolean) => {
      if (active) setSupported(value)
    }
    subscribers.add(subscriber)
    void detectAvifSupport()

    return () => {
      active = false
      subscribers.delete(subscriber)
    }
  }, [])

  return supported
}

// Kick off detection eagerly so the result is typically available by the
// time the first screenshot URL is built.
if (typeof window !== 'undefined') {
  void detectAvifSupport()
}
