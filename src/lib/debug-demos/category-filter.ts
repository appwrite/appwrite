import {
  DEBUG_DEMO_CATEGORIES,
  type DebugDemoCategory,
} from '@/lib/debug-demos/catalog'

const HIDDEN_CATEGORIES_KEY = 'debug:demoHiddenCategories'

function isDebugDemoCategory(value: string): value is DebugDemoCategory {
  return (DEBUG_DEMO_CATEGORIES as readonly string[]).includes(value)
}

export function readHiddenDemoCategories(): Set<DebugDemoCategory> {
  if (typeof window === 'undefined') return new Set()
  try {
    const raw = window.localStorage.getItem(HIDDEN_CATEGORIES_KEY)
    if (!raw) return new Set()
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return new Set()
    return new Set(
      parsed.filter(
        (entry): entry is DebugDemoCategory =>
          typeof entry === 'string' && isDebugDemoCategory(entry),
      ),
    )
  } catch {
    return new Set()
  }
}

export function writeHiddenDemoCategories(hidden: Set<DebugDemoCategory>) {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(
    HIDDEN_CATEGORIES_KEY,
    JSON.stringify(Array.from(hidden)),
  )
}
