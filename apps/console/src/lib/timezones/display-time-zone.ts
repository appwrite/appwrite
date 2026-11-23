'use client'

import { useMemo, useSyncExternalStore } from 'react'
import { getUserTimeZone, isValidTimeZone } from './zoned-time'

/** Per-device display zone for Appwrite datetimes; absent means the browser zone. */
const DISPLAY_TIME_ZONE_STORAGE_KEY = 'console.datetime.timeZone'

const listeners = new Set<() => void>()
// undefined = not read from storage yet
let storedCache: string | null | undefined
let browserCache: string | undefined

function readStoredTimeZone(): string | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(DISPLAY_TIME_ZONE_STORAGE_KEY)
    return raw && isValidTimeZone(raw) ? raw : null
  } catch {
    return null
  }
}

function notify() {
  listeners.forEach((listener) => listener())
}

function getBrowserTimeZone(): string {
  return (browserCache ??= getUserTimeZone())
}

function getStoredDisplayTimeZone(): string | null {
  if (storedCache === undefined) storedCache = readStoredTimeZone()
  return storedCache
}

/** null or the browser zone clears the key, so the default keeps following the OS when travelling. */
function setDisplayTimeZone(timeZone: string | null): void {
  const next =
    timeZone && isValidTimeZone(timeZone) && timeZone !== getBrowserTimeZone()
      ? timeZone
      : null
  storedCache = next
  try {
    if (next) window.localStorage.setItem(DISPLAY_TIME_ZONE_STORAGE_KEY, next)
    else window.localStorage.removeItem(DISPLAY_TIME_ZONE_STORAGE_KEY)
  } catch {
    // Private mode or quota: keep the choice for this session only.
  }
  notify()
}

function onStorage(event: StorageEvent) {
  if (event.key !== null && event.key !== DISPLAY_TIME_ZONE_STORAGE_KEY) return
  storedCache = undefined
  notify()
}

let listeningToStorage = false

function subscribe(listener: () => void) {
  if (typeof window === 'undefined') return () => {}
  // Never removed: the cache must hear other tabs while no picker is mounted.
  if (!listeningToStorage) {
    listeningToStorage = true
    window.addEventListener('storage', onStorage)
  }
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

const noopSubscribe = () => () => {}

type DisplayTimeZone = {
  /** Stored override, else the browser zone. */
  timeZone: string
  browserTimeZone: string
  storedTimeZone: string | null
  isOverride: boolean
  setTimeZone: (timeZone: string | null) => void
}

/** Display zone for absolute instants. */
export function useDisplayTimeZone(): DisplayTimeZone {
  const stored = useSyncExternalStore(
    subscribe,
    getStoredDisplayTimeZone,
    () => null,
  )
  const browserTimeZone = useSyncExternalStore(
    noopSubscribe,
    getBrowserTimeZone,
    () => 'UTC',
  )
  return useMemo(
    () => ({
      timeZone: stored ?? browserTimeZone,
      browserTimeZone,
      storedTimeZone: stored,
      isOverride: stored !== null && stored !== browserTimeZone,
      setTimeZone: setDisplayTimeZone,
    }),
    [stored, browserTimeZone],
  )
}
