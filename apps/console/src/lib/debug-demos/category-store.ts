import {
  DEBUG_DEMO_CATEGORIES,
  type DebugDemoCategory,
} from '@/lib/debug-demos/catalog'

function isDebugDemoCategory(value: string): value is DebugDemoCategory {
  return (DEBUG_DEMO_CATEGORIES as readonly string[]).includes(value)
}

/** Browser-local set of demo categories persisted under one storage key. */
export function createDemoCategoryStore(storageKey: string) {
  return {
    read(): Set<DebugDemoCategory> {
      if (typeof window === 'undefined') return new Set()
      try {
        const raw = window.localStorage.getItem(storageKey)
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
    },
    write(categories: Set<DebugDemoCategory>) {
      if (typeof window === 'undefined') return
      window.localStorage.setItem(
        storageKey,
        JSON.stringify(Array.from(categories)),
      )
    },
  }
}
