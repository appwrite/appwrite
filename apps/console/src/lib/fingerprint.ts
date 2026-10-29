/**
 * Browser fingerprint for console access and project resume.
 * Must match exactly the old console implementation so the backend accepts the token.
 * See: console/src/lib/helpers/fingerprint.ts
 *
 * When a secret is set, the token is HMAC-signed so the backend can verify it.
 * Env: VITE_CONSOLE_FINGERPRINT_KEY or PUBLIC_CONSOLE_FINGERPRINT_KEY (same value as backend).
 *
 * Timestamps use server time (from /health/version Date header) when synced, same as the
 * reference console - local clock drift otherwise causes "Invalid console fingerprint".
 */

import { getRuntimeConfig } from '@/lib/runtime-config'

const SECRET = getRuntimeConfig().fingerprintKey
const CACHE_TTL_MS = 60 * 60 * 1000 // 1 hour

const GLOBAL_SERVER_TIME_KEY = '__vibesFingerprintServerTime__'

type ServerTimeCache = { serverSecs: number; fetchedAtMs: number }

type FingerprintServerTimeGlobal = {
  cache: ServerTimeCache | null
  inflight: Map<string, Promise<void>>
}

function getFingerprintServerTimeGlobal(): FingerprintServerTimeGlobal {
  const g = globalThis as typeof globalThis & {
    [GLOBAL_SERVER_TIME_KEY]?: FingerprintServerTimeGlobal
  }
  if (!g[GLOBAL_SERVER_TIME_KEY]) {
    g[GLOBAL_SERVER_TIME_KEY] = {
      cache: null,
      inflight: new Map(),
    }
  }
  return g[GLOBAL_SERVER_TIME_KEY]
}

function serverTimeSyncKey(endpoint: string, projectId: string): string {
  return `${endpoint.replace(/\/$/, '')}|${projectId}`
}

/**
 * Record server unix time (seconds). Only the first successful sync is kept, matching
 * console/src/lib/helpers/fingerprint.ts.
 */
export function syncServerTime(serverTimeSecs: number): void {
  const state = getFingerprintServerTimeGlobal()
  if (state.cache) return
  state.cache = { serverSecs: serverTimeSecs, fetchedAtMs: Date.now() }
}

/** Clear cached server clock (e.g. when the API endpoint changes in debug menu). */
export function resetFingerprintServerTimeCache(): void {
  const state = getFingerprintServerTimeGlobal()
  state.cache = null
  state.inflight.clear()
}

function getServerTimestamp(): number {
  const serverTimeCache = getFingerprintServerTimeGlobal().cache
  if (!serverTimeCache) {
    return Math.floor(Date.now() / 1000)
  }
  const elapsedSecs = Math.floor(
    (Date.now() - serverTimeCache.fetchedAtMs) / 1000,
  )
  return serverTimeCache.serverSecs + elapsedSecs
}

/**
 * Ensures server time is synced once from the Date header on GET /health/version
 * (same as console (console)/+layout.ts). Await before generating fingerprints when
 * the app may not have completed the background sync yet (e.g. Restore project).
 */
export function ensureFingerprintServerTimeSynced(
  endpoint: string,
  projectId: string,
): Promise<void> {
  const state = getFingerprintServerTimeGlobal()
  if (state.cache) return Promise.resolve()
  if (!endpoint?.trim() || !projectId?.trim()) {
    return Promise.resolve()
  }

  const key = serverTimeSyncKey(endpoint, projectId)
  const existing = state.inflight.get(key)
  if (existing) return existing

  const url = `${endpoint.replace(/\/$/, '')}/health/version`
  const promise = fetch(url, {
    headers: { 'X-Appwrite-Project': projectId },
  })
    .then((response) => {
      const dateHeader = response.headers.get('Date')
      const parsed = dateHeader ? new Date(dateHeader).getTime() : NaN
      if (Number.isFinite(parsed)) {
        syncServerTime(Math.floor(parsed / 1000))
      }
    })
    .catch(() => {
      /* fall back to local clock in getServerTimestamp */
    })
    .finally(() => {
      const globalState = getFingerprintServerTimeGlobal()
      if (globalState.inflight.get(key) === promise) {
        globalState.inflight.delete(key)
      }
    })

  state.inflight.set(key, promise)
  return promise
}

async function sha256(message: string): Promise<string> {
  if (!crypto?.subtle) {
    console.warn('crypto.subtle unavailable, fingerprinting disabled')
    return ''
  }
  const msgBuffer = new TextEncoder().encode(message)
  const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer)
  const hashArray = Array.from(new Uint8Array(hashBuffer))
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('')
}

async function hmacSha256(message: string, secret: string): Promise<string> {
  if (!crypto?.subtle) return ''
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  )
  const sig = await crypto.subtle.sign(
    'HMAC',
    key,
    new TextEncoder().encode(message),
  )
  return Array.from(new Uint8Array(sig))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')
}

function getCanvasFingerprint(): string {
  try {
    const canvas = document.createElement('canvas')
    const ctx = canvas.getContext('2d')
    if (!ctx) return ''

    canvas.width = 200
    canvas.height = 50

    ctx.textBaseline = 'top'
    ctx.font = '14px Arial'
    ctx.fillStyle = '#f60'
    ctx.fillRect(125, 1, 62, 20)
    ctx.fillStyle = '#069'
    ctx.fillText('Appwrite Console', 2, 15)
    ctx.fillStyle = 'rgba(102, 204, 0, 0.7)'
    ctx.fillText('Appwrite Console', 4, 17)

    return canvas.toDataURL()
  } catch {
    return ''
  }
}

function getWebGLFingerprint(): string {
  try {
    const canvas = document.createElement('canvas')
    const gl =
      canvas.getContext('webgl') ||
      (canvas.getContext('experimental-webgl') as WebGLRenderingContext | null)
    if (!gl) return ''

    const debugInfo = gl.getExtension('WEBGL_debug_renderer_info')
    if (!debugInfo) return 'webgl-no-debug'

    const vendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) || ''
    const renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || ''

    return `${vendor}~${renderer}`
  } catch {
    return ''
  }
}

async function getAudioFingerprint(): Promise<string> {
  try {
    const OfflineCtx =
      window.OfflineAudioContext ||
      (
        window as unknown as {
          webkitOfflineAudioContext: typeof OfflineAudioContext
        }
      ).webkitOfflineAudioContext
    if (!OfflineCtx) return ''

    const sampleRate = 44100
    const length = 4096
    const context = new OfflineCtx(1, length, sampleRate)

    const oscillator = context.createOscillator()
    oscillator.type = 'triangle'
    oscillator.frequency.value = 10000

    const compressor = context.createDynamicsCompressor()
    compressor.threshold.value = -50
    compressor.knee.value = 40
    compressor.ratio.value = 12
    compressor.attack.value = 0
    compressor.release.value = 0.25

    oscillator.connect(compressor)
    compressor.connect(context.destination)

    oscillator.start(0)

    const buffer = await context.startRendering()
    const samples = buffer.getChannelData(0)

    let sum = 0
    for (let i = 0; i < samples.length; i++) {
      sum += Math.abs(samples[i])
    }

    return sum.toString()
  } catch {
    return ''
  }
}

interface StaticSignals {
  userAgent: string
  language: string
  languages: string[]
  platform: string
  hardwareConcurrency: number
  deviceMemory: number | undefined
  maxTouchPoints: number
  screenWidth: number
  screenHeight: number
  screenColorDepth: number
  screenPixelDepth: number
  devicePixelRatio: number
  timezoneOffset: number
  timezone: string
  canvas: string
  webgl: string
  audio: string
}

interface BrowserSignals extends StaticSignals {
  timestamp: number
}

interface SignalsCache {
  signals: StaticSignals
  collectedAt: number
}

let cache: SignalsCache | null = null
let cachePromise: Promise<StaticSignals> | null = null

async function collectStaticSignals(): Promise<StaticSignals> {
  const [canvasRaw, webgl, audio] = await Promise.all([
    Promise.resolve(getCanvasFingerprint()),
    Promise.resolve(getWebGLFingerprint()),
    getAudioFingerprint(),
  ])

  const canvas = canvasRaw ? await sha256(canvasRaw) : ''

  return {
    userAgent: navigator.userAgent,
    language: navigator.language,
    languages: [...(navigator.languages || [])],
    platform: navigator.platform,
    hardwareConcurrency: navigator.hardwareConcurrency || 0,
    deviceMemory: (navigator as Navigator & { deviceMemory?: number })
      .deviceMemory,
    maxTouchPoints: navigator.maxTouchPoints || 0,
    screenWidth: screen.width,
    screenHeight: screen.height,
    screenColorDepth: screen.colorDepth,
    screenPixelDepth: screen.pixelDepth,
    devicePixelRatio: window.devicePixelRatio || 1,
    timezoneOffset: new Date().getTimezoneOffset(),
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    canvas,
    webgl,
    audio,
  }
}

async function getCachedSignals(): Promise<StaticSignals> {
  const now = Date.now()

  if (cache && now - cache.collectedAt < CACHE_TTL_MS) {
    return cache.signals
  }

  if (cachePromise) {
    return cachePromise
  }

  cachePromise = collectStaticSignals()

  try {
    const signals = await cachePromise
    cache = { signals, collectedAt: now }
    return signals
  } finally {
    cachePromise = null
  }
}

/**
 * Generate a fingerprint token for console requests (updateConsoleAccess, resume).
 * Must produce the same format as the old console so the backend accepts it.
 */
export async function generateFingerprintToken(): Promise<string> {
  const staticSignals = await getCachedSignals()

  const signals: BrowserSignals = {
    ...staticSignals,
    timestamp: getServerTimestamp(),
  }

  const payload = JSON.stringify(signals)
  const bytes = new TextEncoder().encode(payload)
  let binary = ''
  for (const byte of bytes) {
    binary += String.fromCharCode(byte)
  }
  const encoded = btoa(binary)

  if (!SECRET) {
    if (import.meta.env?.DEV) {
      console.warn(
        '[fingerprint] VITE_CONSOLE_FINGERPRINT_KEY is not set. Token is sent unsigned; backend may return 401 Invalid console fingerprint. Set it in .env to match the backend key.',
      )
    }
    return encoded
  }

  const signature = await hmacSha256(encoded, SECRET)

  return `${encoded}.${signature}`
}
