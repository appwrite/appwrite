import { useMemo } from 'react'
import { useTheme } from 'next-themes'
import { useSequentialShortcuts } from '@/hooks/use-keyboard-shortcuts'

export const THEME_LIGHT_SHORTCUT = 't l'
export const THEME_DARK_SHORTCUT = 't d'
export const THEME_SYSTEM_SHORTCUT = 't a'

export const THEME_LIGHT_SHORTCUT_RAW = 'T L'
export const THEME_DARK_SHORTCUT_RAW = 'T D'
export const THEME_SYSTEM_SHORTCUT_RAW = 'T A'

export type ThemeShortcutValue = 'light' | 'dark' | 'system'

export function buildThemeSequentialShortcuts(
  setTheme: (theme: ThemeShortcutValue) => void,
) {
  return {
    [THEME_LIGHT_SHORTCUT]: () => setTheme('light'),
    [THEME_DARK_SHORTCUT]: () => setTheme('dark'),
    [THEME_SYSTEM_SHORTCUT]: () => setTheme('system'),
  }
}

export function useThemeShortcuts(options: { enabled?: boolean } = {}) {
  const { setTheme } = useTheme()
  const { enabled = true } = options

  const shortcuts = useMemo(
    () => buildThemeSequentialShortcuts(setTheme),
    [setTheme],
  )

  useSequentialShortcuts(shortcuts, { enabled })
}
