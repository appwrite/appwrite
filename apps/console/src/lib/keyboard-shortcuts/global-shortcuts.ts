import {
  THEME_DARK_SHORTCUT_RAW,
  THEME_LIGHT_SHORTCUT_RAW,
  THEME_SYSTEM_SHORTCUT_RAW,
} from '@/lib/keyboard-shortcuts/theme-shortcuts'

export type ShortcutRef = {
  id: string
  description: string
  raw: string
}

/** Global shortcuts always listed in the keyboard shortcuts reference. */
export const GLOBAL_SHORTCUT_REFS: readonly ShortcutRef[] = [
  {
    id: 'global.command-center',
    description: 'Open command center',
    raw: 'mod+k',
  },
  {
    id: 'global.shortcuts',
    description: 'Show keyboard shortcuts',
    raw: '?',
  },
  {
    id: 'global.back',
    description: 'Close / go back',
    raw: 'escape',
  },
  {
    id: 'global.search',
    description: 'Focus search',
    raw: '/',
  },
  {
    id: 'global.theme.light',
    description: 'Set theme to light',
    raw: THEME_LIGHT_SHORTCUT_RAW,
  },
  {
    id: 'global.theme.dark',
    description: 'Set theme to dark',
    raw: THEME_DARK_SHORTCUT_RAW,
  },
  {
    id: 'global.theme.system',
    description: 'Set theme to system',
    raw: THEME_SYSTEM_SHORTCUT_RAW,
  },
]

export const GLOBAL_SHORTCUT_IDS = new Set(
  GLOBAL_SHORTCUT_REFS.map((shortcut) => shortcut.id),
)
