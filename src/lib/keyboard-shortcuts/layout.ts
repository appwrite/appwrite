import type { KeyId } from './display'

export interface KeySpec {
  id: KeyId
  label: string
  flex?: number
  /** Invisible flex gap for realistic keyboard spacing. */
  spacer?: boolean
}

export interface KeyboardRow {
  keys: KeySpec[]
}

function letter(id: KeyId, label?: string): KeySpec {
  return { id, label: label ?? id.toUpperCase(), flex: 1 }
}

function fKey(n: number): KeySpec {
  return { id: `f${n}`, label: `F${n}`, flex: 0.85 }
}

function getFunctionRow(isMac: boolean): KeyboardRow {
  const keys: KeySpec[] = [
    { id: 'escape', label: 'esc', flex: 1.15 },
    ...Array.from({ length: 12 }, (_, i) => fKey(i + 1)),
  ]

  if (isMac) {
    keys.push({ id: 'touchId', label: '', flex: 1 })
  }

  return { keys }
}

const MAIN_ROWS: KeyboardRow[] = [
  {
    keys: [
      { id: 'backquote', label: '`', flex: 1 },
      ...['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'].map((k) => letter(k)),
      { id: 'minus', label: '-', flex: 1 },
      { id: 'equal', label: '=', flex: 1 },
      { id: 'backspace', label: '⌫', flex: 1.55 },
    ],
  },
  {
    keys: [
      { id: 'tab', label: 'tab', flex: 1.35 },
      ...['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'].map((k) => letter(k)),
      { id: 'bracketLeft', label: '[', flex: 1 },
      { id: 'bracketRight', label: ']', flex: 1 },
      { id: 'backslash', label: '\\', flex: 1.15 },
    ],
  },
  {
    keys: [
      { id: 'caps', label: 'caps', flex: 1.65 },
      ...['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'].map((k) => letter(k)),
      { id: 'semicolon', label: ';', flex: 1 },
      { id: 'quote', label: "'", flex: 1 },
      { id: 'enter', label: 'return', flex: 1.65 },
    ],
  },
  {
    keys: [
      { id: 'shiftLeft', label: 'shift', flex: 2.05 },
      ...['z', 'x', 'c', 'v', 'b', 'n', 'm'].map((k) => letter(k)),
      { id: 'comma', label: ',', flex: 1 },
      { id: 'period', label: '.', flex: 1 },
      { id: 'slash', label: '/', flex: 1 },
      { id: 'shiftRight', label: 'shift', flex: 2.05 },
    ],
  },
]

export const ARROW_CLUSTER: KeySpec[] = [
  { id: 'arrowUp', label: '↑' },
  { id: 'arrowLeft', label: '←' },
  { id: 'arrowDown', label: '↓' },
  { id: 'arrowRight', label: '→' },
]

export function getMacModifierRow(): KeyboardRow {
  return {
    keys: [
      { id: 'fn', label: 'fn', flex: 1 },
      { id: 'controlLeft', label: '⌃', flex: 1.1 },
      { id: 'altLeft', label: '⌥', flex: 1.1 },
      { id: 'metaLeft', label: '⌘', flex: 1.3 },
      { id: 'space', label: 'space', flex: 5.4 },
      { id: 'metaRight', label: '⌘', flex: 1.3 },
      { id: 'altRight', label: '⌥', flex: 1.1 },
      { id: 'controlRight', label: '⌃', flex: 1.1 },
    ],
  }
}

export function getWinModifierRow(): KeyboardRow {
  return {
    keys: [
      { id: 'controlLeft', label: 'ctrl', flex: 1.3 },
      { id: 'winLeft', label: '', flex: 1.1 },
      { id: 'altLeft', label: 'alt', flex: 1.1 },
      { id: 'space', label: 'space', flex: 5.4 },
      { id: 'altRight', label: 'alt', flex: 1.1 },
      { id: 'winRight', label: '', flex: 1.1 },
      { id: 'controlRight', label: 'ctrl', flex: 1.3 },
    ],
  }
}

/** Rows above the bottom modifier + arrow cluster. */
export function getMainKeyboardRows(isMac: boolean): KeyboardRow[] {
  return [getFunctionRow(isMac), ...MAIN_ROWS]
}
