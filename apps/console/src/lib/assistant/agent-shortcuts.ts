import type { ShortcutRef } from '@/lib/keyboard-shortcuts/global-shortcuts'

/** Display string for shortcuts UI (`mod` → ⌘ on Mac, Ctrl on Windows). */
export const AGENT_TOGGLE_SHORTCUT_RAW = 'mod+i'

/** Key combos registered for toggling the agent pane (`mod` is not parsed by useKeyboardShortcut). */
export const AGENT_TOGGLE_SHORTCUT_COMBOS = [
  'meta+i',
  'control+i',
] as const

/** Display string for shortcuts UI (`mod` → ⌘ on Mac, Ctrl on Windows). */
export const AGENT_NEW_SHORTCUT_RAW = 'mod+shift+n'

/** Key combos registered for creating a new agent (`mod` is not parsed by useKeyboardShortcut). */
export const AGENT_NEW_SHORTCUT_COMBOS = [
  'meta+shift+n',
  'control+shift+n',
] as const

/** Create a new agent automation. */
export const AGENT_NEW_AUTOMATION_SHORTCUT_RAW = 'mod+shift+u'

export const AGENT_NEW_AUTOMATION_SHORTCUT_COMBOS = [
  'meta+shift+u',
  'control+shift+u',
] as const

/** Focus the agent composer textarea. */
export const AGENT_FOCUS_COMPOSER_SHORTCUT_RAW = 'mod+shift+l'

export const AGENT_FOCUS_COMPOSER_SHORTCUT_COMBOS = [
  'meta+shift+l',
  'control+shift+l',
] as const

/** Agent shortcuts listed in the keyboard shortcuts reference. */
export const AGENT_SHORTCUTS: readonly ShortcutRef[] = [
  {
    id: 'agent.toggle',
    description: 'Toggle agent',
    raw: AGENT_TOGGLE_SHORTCUT_RAW,
  },
  {
    id: 'agent.new',
    description: 'New agent',
    raw: AGENT_NEW_SHORTCUT_RAW,
  },
  {
    id: 'agent.new-automation',
    description: 'New automation',
    raw: AGENT_NEW_AUTOMATION_SHORTCUT_RAW,
  },
  {
    id: 'agent.focus-composer',
    description: 'Focus prompt',
    raw: AGENT_FOCUS_COMPOSER_SHORTCUT_RAW,
  },
]
