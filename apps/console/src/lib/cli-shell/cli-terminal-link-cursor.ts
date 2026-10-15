import type { IDisposable, ILink, Terminal } from '@xterm/xterm'

const LINK_CURSOR_SELECTOR =
  '.xterm-screen, .xterm-viewport, .xterm-canvas-canvas-layer, canvas'

const resetTimers = new WeakMap<Terminal, number>()

/** Apply pointer/text cursor on xterm layers (canvas does not inherit from root). */
export function setCliTerminalLinkCursor(terminal: Terminal, active: boolean): void {
  const root = terminal.element
  if (!root) return

  const cursor = active ? 'pointer' : 'text'
  root.style.cursor = cursor
  root.classList.toggle('xterm-cursor-pointer', active)

  root.querySelectorAll(LINK_CURSOR_SELECTOR).forEach((node) => {
    ;(node as HTMLElement).style.cursor = cursor
  })
}

function clearPendingLinkCursorReset(terminal: Terminal): void {
  const pending = resetTimers.get(terminal)
  if (pending !== undefined) {
    window.clearTimeout(pending)
    resetTimers.delete(terminal)
  }
}

/** Reset cursor when the pointer leaves the terminal surface. */
export function registerCliTerminalLinkCursorCleanup(
  terminal: Terminal,
): IDisposable {
  const root = terminal.element
  if (!root) {
    return { dispose: () => {} }
  }

  const onMouseLeave = () => {
    clearPendingLinkCursorReset(terminal)
    setCliTerminalLinkCursor(terminal, false)
  }

  root.addEventListener('mouseleave', onMouseLeave)

  return {
    dispose: () => {
      clearPendingLinkCursorReset(terminal)
      root.removeEventListener('mouseleave', onMouseLeave)
      setCliTerminalLinkCursor(terminal, false)
    },
  }
}

export function withCliTerminalLinkCursor(
  terminal: Terminal,
  link: ILink,
): ILink {
  return {
    ...link,
    hover: (event, text) => {
      clearPendingLinkCursorReset(terminal)
      setCliTerminalLinkCursor(terminal, true)
      link.hover?.(event, text)
    },
    leave: (event, text) => {
      link.leave?.(event, text)
      const pending = window.setTimeout(() => {
        resetTimers.delete(terminal)
        setCliTerminalLinkCursor(terminal, false)
      }, 0)
      resetTimers.set(terminal, pending)
    },
  }
}
