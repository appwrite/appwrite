import {
  GLOBAL_SHORTCUT_IDS,
  GLOBAL_SHORTCUT_REFS,
} from '@/lib/keyboard-shortcuts/global-shortcuts'
import { detectUserOs, isMacOs } from '@/lib/user-os'

export type KeyId = string

export interface ParsedShortcut {
  id: string
  description: string
  /** Raw shortcut string from registry or global defaults. */
  raw: string
  displayKeys: string[]
  highlightKeys: KeyId[]
  isSequential: boolean
}

const MODIFIERS = new Set([
  'mod',
  'meta',
  'command',
  'cmd',
  '⌘',
  'ctrl',
  'control',
  'alt',
  'opt',
  'option',
  'shift',
])

const SHORTCUT_GROUP_ORDER = [
  'Global',
  'Navigation',
  'Create',
  'Actions',
  'Agent',
  'SQL editor',
  'Terminal',
  'Help',
  'Theme',
] as const

function normalizeShortcutRaw(raw: string) {
  return raw.trim().toLowerCase()
}

/** Raw device detection (ignores debug OS override). Prefer `usePlatform()`. */
export function isMacPlatform() {
  return isMacOs(detectUserOs())
}

function formatSingleDisplayKey(key: string, isMac: boolean): string {
  const lower = key.toLowerCase()
  if (lower === 'mod' || lower === 'meta' || lower === 'command' || lower === 'cmd' || lower === '⌘') {
    return isMac ? '⌘' : 'Ctrl'
  }
  if (lower === 'ctrl' || lower === 'control') return 'Ctrl'
  if (lower === 'alt' || lower === 'opt' || lower === 'option') return isMac ? '⌥' : 'Alt'
  if (lower === 'shift') return isMac ? '⇧' : 'Shift'
  if (lower === 'escape' || lower === 'esc') return 'Esc'
  if (lower === 'slash' || key === '/') return '/'
  if (lower === 'backquote' || key === '`') return '`'
  if (lower === 'home') return 'Home'
  if (lower === 'end') return 'End'
  if (lower === 'delete') return 'Delete'
  if (lower === 'backspace') return '⌫'
  if (lower === 'tab') return 'Tab'
  if (lower === 'pageup') return 'PgUp'
  if (lower === 'pagedown') return 'PgDn'
  if (lower === 'up') return '↑'
  if (lower === 'down') return '↓'
  if (lower === 'left') return '←'
  if (lower === 'right') return '→'
  if (key === '?') return '?'
  if (key === ',') return ','
  if (lower === 'semicolon' || key === ';') return ';'
  if (lower === 'quote' || key === "'" || key === '"') return "'"
  if (lower === 'backslash' || key === '\\' || key === '|') return '\\'
  if (lower === 'period' || key === '.') return '.'
  if (key.length === 1 && /[a-z]/i.test(key)) return key.toUpperCase()
  return key
}

function tokenToHighlightKey(token: string, isMac: boolean): KeyId[] {
  const lower = token.toLowerCase()
  if (lower === 'mod' || lower === 'meta' || lower === 'command' || lower === 'cmd' || lower === '⌘') {
    return isMac ? ['metaLeft'] : ['controlLeft']
  }
  if (lower === 'ctrl' || lower === 'control') return ['controlLeft']
  if (lower === 'alt' || lower === 'opt' || lower === 'option') return ['altLeft']
  if (lower === 'shift') return ['shiftLeft']
  if (lower === 'escape' || lower === 'esc') return ['escape']
  if (lower === 'slash' || token === '/') return ['slash']
  if (lower === 'backquote' || token === '`') return ['backquote']
  if (lower === 'home') return ['home']
  if (lower === 'end') return ['end']
  if (lower === 'delete') return ['delete']
  if (lower === 'backspace') return ['backspace']
  if (lower === 'tab') return ['tab']
  if (lower === 'pageup') return ['pageUp']
  if (lower === 'pagedown') return ['pageDown']
  if (lower === 'up') return ['arrowUp']
  if (lower === 'down') return ['arrowDown']
  if (lower === 'left') return ['arrowLeft']
  if (lower === 'right') return ['arrowRight']
  if (token === '?') return ['shiftLeft', 'slash']
  if (token === ',') return ['comma']
  if (token === ';' || lower === 'semicolon') return ['semicolon']
  if (token === "'" || token === '"' || lower === 'quote') return ['quote']
  if (token === '\\' || token === '|' || lower === 'backslash') {
    return ['backslash']
  }
  if (token === '.' || lower === 'period') return ['period']
  if (token.length === 1 && /[a-z]/i.test(token)) return [token.toLowerCase()]
  return [lower]
}

export function formatDisplayKeys(raw: string, isMac: boolean): string[] {
  if (raw.includes('+')) {
    return raw.split('+').map((part) => formatSingleDisplayKey(part.trim(), isMac))
  }
  return raw.split(/\s+/).filter(Boolean).map((part) => formatSingleDisplayKey(part.trim(), isMac))
}

export function parseHighlightKeys(raw: string, isMac: boolean): KeyId[] {
  const keys: KeyId[] = []
  const addKeys = (token: string) => {
    for (const key of tokenToHighlightKey(token, isMac)) {
      if (!keys.includes(key)) keys.push(key)
    }
  }

  const normalized = raw.trim()

  if (normalized.includes('+')) {
    for (const part of normalized.split('+')) addKeys(part.trim())
    return keys
  }

  for (const part of normalized.split(/\s+/).filter(Boolean)) {
    addKeys(part.trim())
  }
  return keys
}

export function isSequentialShortcut(raw: string) {
  if (raw.includes('+')) return false
  const parts = raw.split(/\s+/).filter(Boolean)
  if (parts.length <= 1) return false
  return parts.every((part) => !MODIFIERS.has(part.toLowerCase()))
}

export interface ShortcutGroup {
  label: string
  shortcuts: ParsedShortcut[]
}

export function buildShortcutRefGroup(
  label: string,
  shortcuts: Array<{ id: string; description: string; raw: string }>,
  isMac: boolean,
): ShortcutGroup {
  return {
    label,
    shortcuts: shortcuts.map((shortcut) => ({
      id: shortcut.id,
      description: shortcut.description,
      raw: shortcut.raw,
      displayKeys: formatDisplayKeys(shortcut.raw, isMac),
      highlightKeys: parseHighlightKeys(shortcut.raw, isMac),
      isSequential: isSequentialShortcut(shortcut.raw),
    })),
  }
}

export function mergeShortcutGroups(
  base: ShortcutGroup[],
  extra: ShortcutGroup[],
  insertAfterLabel = 'Global',
): ShortcutGroup[] {
  if (extra.length === 0) return base

  const insertIndex = base.findIndex((group) => group.label === insertAfterLabel)
  if (insertIndex === -1) {
    return [...base, ...extra]
  }

  return [
    ...base.slice(0, insertIndex + 1),
    ...extra,
    ...base.slice(insertIndex + 1),
  ]
}

export function buildShortcutGroups(
  commands: Array<{ id: string; label: string; shortcut?: string; kind: string; group?: string }>,
  groupLabelForKind: (kind: string) => string,
  isMac: boolean,
): ShortcutGroup[] {
  const buckets = new Map<string, ParsedShortcut[]>()

  for (const cmd of commands) {
    if (!cmd.shortcut) continue
    if (GLOBAL_SHORTCUT_IDS.has(cmd.id)) continue

    const label = cmd.group ?? groupLabelForKind(cmd.kind)
    const arr = buckets.get(label) ?? []
    arr.push({
      id: cmd.id,
      description: cmd.label,
      raw: cmd.shortcut,
      displayKeys: formatDisplayKeys(cmd.shortcut, isMac),
      highlightKeys: parseHighlightKeys(cmd.shortcut, isMac),
      isSequential: isSequentialShortcut(cmd.shortcut),
    })
    buckets.set(label, arr)
  }

  buckets.set(
    'Global',
    buildShortcutRefGroup('Global', GLOBAL_SHORTCUT_REFS, isMac).shortcuts,
  )

  const groups = Array.from(buckets.entries())
    .map(([label, shortcuts]) => ({
      label,
      shortcuts,
    }))
    .filter((group) => group.shortcuts.length > 0)

  groups.sort((a, b) => {
    const ai = SHORTCUT_GROUP_ORDER.indexOf(
      a.label as (typeof SHORTCUT_GROUP_ORDER)[number],
    )
    const bi = SHORTCUT_GROUP_ORDER.indexOf(
      b.label as (typeof SHORTCUT_GROUP_ORDER)[number],
    )
    if (ai !== -1 || bi !== -1) {
      if (ai === -1) return 1
      if (bi === -1) return -1
      return ai - bi
    }
    return a.label.localeCompare(b.label)
  })

  return dedupeShortcutGroups(groups)
}

/** Drop duplicate shortcut rows (same binding and label). */
export function dedupeShortcutGroups(groups: ShortcutGroup[]): ShortcutGroup[] {
  const seen = new Set<string>()

  return groups
    .map((group) => ({
      ...group,
      shortcuts: group.shortcuts.filter((shortcut) => {
        const key = `${normalizeShortcutRaw(shortcut.raw)}::${shortcut.description.toLowerCase()}`
        if (seen.has(key)) return false
        seen.add(key)
        return true
      }),
    }))
    .filter((group) => group.shortcuts.length > 0)
}
