import { Moon, Sun } from 'lucide-react'
import {
  THEME_DARK_SHORTCUT_RAW,
  THEME_LIGHT_SHORTCUT_RAW,
} from '@/lib/keyboard-shortcuts/theme-shortcuts'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const THEME: CommandEntry[] = [
  {
    id: 'global.theme.light',
    scopes: ['account', 'organization', 'project'],
    kind: 'action',
    group: 'Global',
    label: 'Set theme to light',
    description: 'Switch the console to light mode',
    icon: Sun,
    shortcut: THEME_LIGHT_SHORTCUT_RAW,
    keywords: ['theme', 'light', 'mode', 'appearance', 'color'],
    perform: (ctx) => {
      ctx.handlers.onSetTheme?.('light')
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'global.theme.dark',
    scopes: ['account', 'organization', 'project'],
    kind: 'action',
    group: 'Global',
    label: 'Set theme to dark',
    description: 'Switch the console to dark mode',
    icon: Moon,
    shortcut: THEME_DARK_SHORTCUT_RAW,
    keywords: ['theme', 'dark', 'mode', 'appearance', 'color'],
    perform: (ctx) => {
      ctx.handlers.onSetTheme?.('dark')
      ctx.closeCommandCenter()
    },
  },
]

registerCommands(THEME)
