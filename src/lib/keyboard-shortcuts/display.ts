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

const MODIFIERS = new Set(['mod', 'meta', 'command', 'cmd', '⌘', 'ctrl', 'control', 'alt', 'opt', 'option', 'shift'])

export function isMacPlatform() {
  return (
    typeof navigator !== 'undefined' &&
    /Mac|iPod|iPhone|iPad/.test(navigator.platform)
  )
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
  if (key === '?') return '?'
  if (key === ',') return ','
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
  if (token === '?') return ['shiftLeft', 'slash']
  if (token === ',') return ['comma']
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

export function buildShortcutGroups(
  commands: Array<{ id: string; label: string; shortcut?: string; kind: string; group?: string }>,
  groupLabelForKind: (kind: string) => string,
  isMac: boolean,
): ShortcutGroup[] {
  const buckets = new Map<string, ParsedShortcut[]>()

  for (const cmd of commands) {
    if (!cmd.shortcut) continue
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

  buckets.set('Global', [
    {
      id: 'global.command-center',
      description: 'Open command center',
      raw: 'mod+k',
      displayKeys: formatDisplayKeys('mod+k', isMac),
      highlightKeys: parseHighlightKeys('mod+k', isMac),
      isSequential: false,
    },
    {
      id: 'global.shortcuts',
      description: 'Show keyboard shortcuts',
      raw: '?',
      displayKeys: formatDisplayKeys('?', isMac),
      highlightKeys: parseHighlightKeys('?', isMac),
      isSequential: false,
    },
    {
      id: 'global.back',
      description: 'Close / go back',
      raw: 'escape',
      displayKeys: formatDisplayKeys('escape', isMac),
      highlightKeys: parseHighlightKeys('escape', isMac),
      isSequential: false,
    },
    {
      id: 'global.search',
      description: 'Focus search',
      raw: '/',
      displayKeys: formatDisplayKeys('/', isMac),
      highlightKeys: parseHighlightKeys('/', isMac),
      isSequential: false,
    },
  ])

  const groups = Array.from(buckets.entries()).map(([label, shortcuts]) => ({
    label,
    shortcuts,
  }))

  groups.sort((a, b) => {
    if (a.label === 'Global') return -1
    if (b.label === 'Global') return 1
    return a.label.localeCompare(b.label)
  })

  return groups
}
