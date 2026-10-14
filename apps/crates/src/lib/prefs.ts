import { useSyncExternalStore } from 'react'

// Per-reader preferences, kept in this browser: migration mode (PHP and the
// conversion's status next to the Rust docs) and how library lists are laid
// out (cards or rows). The server renders the defaults (the Rust docs alone);
// the stored choice applies once the page hydrates.

type Key = 'migration' | 'layout'
const DEFAULTS: Record<Key, string> = { migration: 'off', layout: 'grid' }
const listeners = new Set<() => void>()

function read(key: Key): string {
  try {
    return localStorage.getItem(`utopia-docs:${key}`) ?? DEFAULTS[key]
  } catch {
    return DEFAULTS[key]
  }
}

export function write(key: Key, value: string) {
  try {
    localStorage.setItem(`utopia-docs:${key}`, value)
  } catch {
    // Storage can be unavailable (private windows); the choice lasts for this page.
  }
  memory[key] = value
  listeners.forEach((l) => l())
}

const memory: Partial<Record<Key, string>> = {}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function usePref(key: Key) {
  return useSyncExternalStore(
    subscribe,
    () => memory[key] ?? read(key),
    () => DEFAULTS[key],
  )
}

/** Migration mode: PHP twins, PHP signatures, conversion status and sync, in yellow cards. */
export const useMigration = () => usePref('migration') === 'on'
export const setMigration = (on: boolean) => write('migration', on ? 'on' : 'off')

/** How lists of libraries are shown: a grid of cards, or one row per library. */
export type Layout = 'grid' | 'list'
export const useLayout = () => (usePref('layout') === 'list' ? 'list' : 'grid') as Layout
export const setLayout = (layout: Layout) => write('layout', layout)
