const DEMO_SESSION_KEY = 'debug:demoSession'
const DEMO_BAR_POSITION_KEY = 'debug:demosBarPosition'
const DEMO_BAR_SIZE_KEY = 'debug:demosBarSize'

export type DebugDemoBarSize = {
  width: number
  height: number
}

export const DEBUG_DEMO_BAR_DEFAULT_SIZE: DebugDemoBarSize = {
  width: 320,
  height: 560,
}

export const DEBUG_DEMO_BAR_MIN_SIZE: DebugDemoBarSize = {
  width: 260,
  height: 320,
}

export const DEBUG_DEMO_BAR_MAX_SIZE: DebugDemoBarSize = {
  width: 480,
  height: 900,
}

export type DebugDemoSession = {
  active: boolean
  history: string[]
  index: number
}

export type DebugDemoBarPosition = {
  x: number
  y: number
}

const DEFAULT_SESSION: DebugDemoSession = {
  active: false,
  history: [],
  index: 0,
}

function parseSession(raw: string | null): DebugDemoSession | null {
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as DebugDemoSession
    if (
      typeof parsed.active === 'boolean' &&
      Array.isArray(parsed.history) &&
      typeof parsed.index === 'number'
    ) {
      return {
        active: parsed.active,
        history: parsed.history.filter((id) => typeof id === 'string'),
        index: Math.max(0, Math.floor(parsed.index)),
      }
    }
  } catch {
    // ignore
  }
  return null
}

export function readDebugDemoSession(): DebugDemoSession {
  if (typeof window === 'undefined') return DEFAULT_SESSION
  try {
    return (
      parseSession(window.localStorage.getItem(DEMO_SESSION_KEY)) ??
      DEFAULT_SESSION
    )
  } catch {
    return DEFAULT_SESSION
  }
}

export function writeDebugDemoSession(session: DebugDemoSession) {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(DEMO_SESSION_KEY, JSON.stringify(session))
}

export function activateDebugDemoSession(
  initialDemoId: string,
): DebugDemoSession {
  const session: DebugDemoSession = {
    active: true,
    history: [initialDemoId],
    index: 0,
  }
  writeDebugDemoSession(session)
  return session
}

export function pushDebugDemoSession(demoId: string): DebugDemoSession {
  const current = readDebugDemoSession()
  const trimmed = current.history.slice(0, current.index + 1)
  if (trimmed[trimmed.length - 1] === demoId) {
    const unchanged = { ...current, active: true }
    writeDebugDemoSession(unchanged)
    return unchanged
  }
  const next: DebugDemoSession = {
    active: true,
    history: [...trimmed, demoId],
    index: trimmed.length,
  }
  writeDebugDemoSession(next)
  return next
}

export function setDebugDemoSessionIndex(index: number): DebugDemoSession {
  const current = readDebugDemoSession()
  const clamped = Math.min(
    Math.max(0, index),
    Math.max(0, current.history.length - 1),
  )
  const next = { ...current, active: true, index: clamped }
  writeDebugDemoSession(next)
  return next
}

export function clearDebugDemoSession() {
  writeDebugDemoSession(DEFAULT_SESSION)
}

export function readDebugDemoBarPosition(): DebugDemoBarPosition | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(DEMO_BAR_POSITION_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as DebugDemoBarPosition
    if (
      typeof parsed.x === 'number' &&
      Number.isFinite(parsed.x) &&
      typeof parsed.y === 'number' &&
      Number.isFinite(parsed.y)
    ) {
      return parsed
    }
  } catch {
    // ignore
  }
  return null
}

export function writeDebugDemoBarPosition(position: DebugDemoBarPosition) {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(DEMO_BAR_POSITION_KEY, JSON.stringify(position))
}

function clampBarDimension(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max)
}

export function clampDebugDemoBarSize(
  size: DebugDemoBarSize,
): DebugDemoBarSize {
  const maxWidth = Math.min(
    DEBUG_DEMO_BAR_MAX_SIZE.width,
    typeof window !== 'undefined'
      ? window.innerWidth * 0.92
      : DEBUG_DEMO_BAR_MAX_SIZE.width,
  )
  const maxHeight = Math.min(
    DEBUG_DEMO_BAR_MAX_SIZE.height,
    typeof window !== 'undefined'
      ? window.innerHeight * 0.88
      : DEBUG_DEMO_BAR_MAX_SIZE.height,
  )
  return {
    width: clampBarDimension(
      size.width,
      DEBUG_DEMO_BAR_MIN_SIZE.width,
      maxWidth,
    ),
    height: clampBarDimension(
      size.height,
      DEBUG_DEMO_BAR_MIN_SIZE.height,
      maxHeight,
    ),
  }
}

export function readDebugDemoBarSize(): DebugDemoBarSize {
  if (typeof window === 'undefined') return DEBUG_DEMO_BAR_DEFAULT_SIZE
  try {
    const raw = window.localStorage.getItem(DEMO_BAR_SIZE_KEY)
    if (!raw) return clampDebugDemoBarSize(DEBUG_DEMO_BAR_DEFAULT_SIZE)
    const parsed = JSON.parse(raw) as DebugDemoBarSize
    if (
      typeof parsed.width === 'number' &&
      Number.isFinite(parsed.width) &&
      typeof parsed.height === 'number' &&
      Number.isFinite(parsed.height)
    ) {
      return clampDebugDemoBarSize(parsed)
    }
  } catch {
    // ignore
  }
  return clampDebugDemoBarSize(DEBUG_DEMO_BAR_DEFAULT_SIZE)
}

export function writeDebugDemoBarSize(size: DebugDemoBarSize) {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(
    DEMO_BAR_SIZE_KEY,
    JSON.stringify(clampDebugDemoBarSize(size)),
  )
}

export const DEBUG_DEMO_SESSION_CHANGE_EVENT = 'debugDemoSessionChange'

export function notifyDebugDemoSessionChange() {
  if (typeof window === 'undefined') return
  window.dispatchEvent(new CustomEvent(DEBUG_DEMO_SESSION_CHANGE_EVENT))
}

export function subscribeDebugDemoSession(listener: () => void) {
  if (typeof window === 'undefined') return () => {}
  const handler = () => listener()
  window.addEventListener(DEBUG_DEMO_SESSION_CHANGE_EVENT, handler)
  window.addEventListener('storage', handler)
  return () => {
    window.removeEventListener(DEBUG_DEMO_SESSION_CHANGE_EVENT, handler)
    window.removeEventListener('storage', handler)
  }
}
