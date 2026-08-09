import { useEffect, useRef, useState } from 'react'
import { shouldSuppressGlobalShortcuts } from '@/lib/global-shortcut-suppress'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  isMacOs,
  resolveUserOs,
  type UserOs,
  type UserOsOverride,
} from '@/lib/user-os'

type KeyCombo = string | string[]
type ShortcutHandler = (e: KeyboardEvent) => void

interface ShortcutOptions {
  /** Prevent default browser behavior */
  preventDefault?: boolean
  /** Stop event propagation */
  stopPropagation?: boolean
  /** Only trigger when no input is focused */
  ignoreInputs?: boolean
  /** Enable the shortcut */
  enabled?: boolean
  /** Listen in capture phase so global shortcuts run before nested handlers */
  capture?: boolean
}

// Normalize key names
function normalizeKey(key: string): string {
  const keyMap: Record<string, string> = {
    cmd: 'meta',
    command: 'meta',
    '⌘': 'meta',
    ctrl: 'control',
    ctl: 'control',
    opt: 'alt',
    option: 'alt',
    return: 'enter',
    esc: 'escape',
    ' ': 'space',
  }
  return keyMap[key.toLowerCase()] || key.toLowerCase()
}

// Parse key combo string into array
function parseKeyCombo(combo: KeyCombo): string[] {
  if (Array.isArray(combo)) {
    return combo.map(normalizeKey)
  }
  return combo.split('+').map((k) => normalizeKey(k.trim()))
}

function isBackquoteKey(e: KeyboardEvent): boolean {
  const pressed = normalizeKey(e.key)
  return pressed === '`' || pressed === 'backquote' || e.code === 'Backquote'
}

function eventMatchesRequiredKey(
  e: KeyboardEvent,
  requiredKey: string,
): boolean {
  const pressed = normalizeKey(e.key)
  if (pressed === requiredKey) return true
  // macOS Option layers emit special characters (e.g. Ï) instead of the base key.
  if (/^[a-z]$/i.test(requiredKey) && e.code === `Key${requiredKey.toUpperCase()}`) {
    return true
  }
  // US QWERTY: ? is shift+/ but e.key is '?'.
  if (requiredKey === '/' && pressed === '?') return true
  if (
    (requiredKey === ';' || requiredKey === ':') &&
    (pressed === ';' || pressed === ':')
  ) {
    return true
  }
  // Quote key: Shift may emit `"` instead of `'`.
  if (
    (requiredKey === "'" || requiredKey === '"' || requiredKey === 'quote') &&
    (pressed === "'" || pressed === '"' || e.code === 'Quote')
  ) {
    return true
  }
  // Period key: Shift may emit `>` instead of `.`.
  if (
    (requiredKey === '.' || requiredKey === '>' || requiredKey === 'period') &&
    (pressed === '.' || pressed === '>' || e.code === 'Period')
  ) {
    return true
  }
  // Backslash key (also `|` when Shift is held on some layouts).
  if (
    (requiredKey === '\\' ||
      requiredKey === '|' ||
      requiredKey === 'backslash') &&
    (pressed === '\\' || pressed === '|' || e.code === 'Backslash')
  ) {
    return true
  }
  if (
    (requiredKey === 'backquote' || requiredKey === '`') &&
    isBackquoteKey(e)
  ) {
    return true
  }
  if (requiredKey === 'tab' && (pressed === 'tab' || e.code === 'Tab')) {
    return true
  }
  return false
}

function isInputElement(element: Element | null): boolean {
  return shouldSuppressGlobalShortcuts(element)
}

/**
 * Hook for registering a single keyboard shortcut
 */
export function useKeyboardShortcut(
  keyCombo: KeyCombo,
  handler: ShortcutHandler,
  options: ShortcutOptions = {},
) {
  const {
    preventDefault = true,
    stopPropagation = false,
    ignoreInputs = true,
    enabled = true,
    capture = false,
  } = options

  const handlerRef = useRef(handler)
  handlerRef.current = handler

  useEffect(() => {
    if (!enabled) return

    const keys = parseKeyCombo(keyCombo)

    const handleKeyDown = (e: KeyboardEvent) => {
      // Check if we should ignore inputs
      if (ignoreInputs && isInputElement(document.activeElement)) {
        return
      }

      // Check modifier keys
      const modifiers = {
        meta: e.metaKey,
        control: e.ctrlKey,
        alt: e.altKey,
        shift: e.shiftKey,
      }

      // Check if all required keys match
      const requiredModifiers = keys.filter((k) =>
        ['meta', 'control', 'alt', 'shift'].includes(k),
      )
      const requiredKeys = keys.filter(
        (k) => !['meta', 'control', 'alt', 'shift'].includes(k),
      )

      // Verify modifiers
      const modifiersMatch = requiredModifiers.every(
        (mod) => modifiers[mod as keyof typeof modifiers],
      )

      // Verify no extra modifiers are pressed (unless they're required)
      const noExtraModifiers = Object.entries(modifiers).every(
        ([mod, pressed]) => {
          if (requiredModifiers.includes(mod)) return true
          return !pressed
        },
      )

      // Verify the main key
      const keyMatches =
        requiredKeys.length === 0 ||
        requiredKeys.every((key) => eventMatchesRequiredKey(e, key))

      if (modifiersMatch && noExtraModifiers && keyMatches) {
        if (preventDefault) e.preventDefault()
        if (stopPropagation) e.stopPropagation()
        handlerRef.current(e)
      }
    }

    window.addEventListener('keydown', handleKeyDown, capture)
    return () => window.removeEventListener('keydown', handleKeyDown, capture)
  }, [keyCombo, preventDefault, stopPropagation, ignoreInputs, enabled, capture])
}

/**
 * Hook for vim-style sequential key shortcuts (e.g., "g o" for go to overview)
 */
export function useSequentialShortcuts(
  shortcuts: Record<string, ShortcutHandler>,
  options: ShortcutOptions = {},
) {
  const { ignoreInputs = true, enabled = true } = options

  const sequenceRef = useRef<string[]>([])
  const timeoutRef = useRef<NodeJS.Timeout | null>(null)
  const handlersRef = useRef(shortcuts)
  handlersRef.current = shortcuts

  useEffect(() => {
    if (!enabled) return

    const resetSequence = () => {
      sequenceRef.current = []
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
        timeoutRef.current = null
      }
    }

    const handleKeyDown = (e: KeyboardEvent) => {
      // Ignore if in input
      if (ignoreInputs && isInputElement(document.activeElement)) {
        return
      }

      // Ignore modifier-only keys
      if (['Meta', 'Control', 'Alt', 'Shift'].includes(e.key)) {
        return
      }

      // Ignore if any modifier is pressed (except shift for capital letters)
      if (e.metaKey || e.ctrlKey || e.altKey) {
        resetSequence()
        return
      }

      const key = normalizeKey(e.key)
      sequenceRef.current.push(key)

      // Reset timeout
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
      }

      // Check for matching shortcut
      const currentSequence = sequenceRef.current.join(' ')

      // Check if any shortcut starts with current sequence
      const possibleMatches = Object.keys(handlersRef.current).filter(
        (shortcut) => shortcut.startsWith(currentSequence),
      )

      if (possibleMatches.length === 0) {
        // No matches, reset
        resetSequence()
        return
      }

      e.preventDefault()

      // Check for exact match
      if (handlersRef.current[currentSequence]) {
        e.preventDefault()
        handlersRef.current[currentSequence](e)
        resetSequence()
        return
      }

      // Set timeout to reset sequence if no more keys pressed
      timeoutRef.current = setTimeout(resetSequence, 1000)
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => {
      window.removeEventListener('keydown', handleKeyDown)
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
      }
    }
  }, [ignoreInputs, enabled])
}

/**
 * Hook for focus management with keyboard
 */
export function useFocusTrap(
  containerRef: React.RefObject<HTMLElement>,
  enabled = true,
) {
  useEffect(() => {
    if (!enabled || !containerRef.current) return

    const container = containerRef.current
    const focusableSelector =
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return

      const focusableElements = container.querySelectorAll(focusableSelector)
      const firstElement = focusableElements[0] as HTMLElement
      const lastElement = focusableElements[
        focusableElements.length - 1
      ] as HTMLElement

      if (e.shiftKey) {
        if (document.activeElement === firstElement) {
          e.preventDefault()
          lastElement?.focus()
        }
      } else {
        if (document.activeElement === lastElement) {
          e.preventDefault()
          firstElement?.focus()
        }
      }
    }

    container.addEventListener('keydown', handleKeyDown)
    return () => container.removeEventListener('keydown', handleKeyDown)
  }, [containerRef, enabled])
}

/**
 * Hook for arrow key navigation in lists
 */
export function useArrowNavigation(
  containerRef: React.RefObject<HTMLElement>,
  options: {
    selector?: string
    loop?: boolean
    orientation?: 'vertical' | 'horizontal' | 'both'
    onSelect?: (element: HTMLElement) => void
    enabled?: boolean
  } = {},
) {
  const {
    selector = '[data-nav-item]',
    loop = true,
    orientation = 'vertical',
    onSelect,
    enabled = true,
  } = options

  useEffect(() => {
    if (!enabled || !containerRef.current) return

    const container = containerRef.current

    const handleKeyDown = (e: KeyboardEvent) => {
      const items = Array.from(
        container.querySelectorAll(selector),
      ) as HTMLElement[]
      if (items.length === 0) return

      const currentIndex = items.findIndex(
        (item) => item === document.activeElement,
      )
      let nextIndex = currentIndex

      const isVertical = orientation === 'vertical' || orientation === 'both'
      const isHorizontal =
        orientation === 'horizontal' || orientation === 'both'

      if (
        (e.key === 'ArrowDown' && isVertical) ||
        (e.key === 'ArrowRight' && isHorizontal)
      ) {
        e.preventDefault()
        nextIndex = currentIndex + 1
        if (nextIndex >= items.length) {
          nextIndex = loop ? 0 : items.length - 1
        }
      } else if (
        (e.key === 'ArrowUp' && isVertical) ||
        (e.key === 'ArrowLeft' && isHorizontal)
      ) {
        e.preventDefault()
        nextIndex = currentIndex - 1
        if (nextIndex < 0) {
          nextIndex = loop ? items.length - 1 : 0
        }
      } else if (e.key === 'Home') {
        e.preventDefault()
        nextIndex = 0
      } else if (e.key === 'End') {
        e.preventDefault()
        nextIndex = items.length - 1
      } else if (e.key === 'Enter' || e.key === ' ') {
        if (currentIndex >= 0 && onSelect) {
          e.preventDefault()
          onSelect(items[currentIndex])
        }
        return
      } else {
        return
      }

      items[nextIndex]?.focus()
    }

    container.addEventListener('keydown', handleKeyDown)
    return () => container.removeEventListener('keydown', handleKeyDown)
  }, [containerRef, selector, loop, orientation, onSelect, enabled])
}

/**
 * Resolve whether to show macOS or Windows keyboard layout / shortcut labels.
 * Linux and Windows both use Ctrl-style modifiers.
 */
export function resolveKeyboardLayoutIsMac(override: UserOsOverride): boolean {
  return isMacOs(resolveUserOs(override))
}

/**
 * Hook to detect platform for showing correct modifier key and keyboard layout.
 * Respects debug menu OS override when set.
 */
export function usePlatform() {
  const [isPlatformKnown, setIsPlatformKnown] = useState(false)
  const { userOs: userOsOverride } = useDebugOverrides()

  useEffect(() => {
    setIsPlatformKnown(true)
  }, [])

  const os: UserOs = resolveUserOs(userOsOverride)
  const isMac = isMacOs(os)

  return {
    os,
    isMac,
    isPlatformKnown,
    userOsOverride,
    /** @deprecated Use `userOsOverride`. */
    keyboardLayout: userOsOverride,
    modKey: isPlatformKnown ? (isMac ? '⌘' : 'Ctrl') : undefined,
    altKey: isPlatformKnown ? (isMac ? '⌥' : 'Alt') : undefined,
  }
}
