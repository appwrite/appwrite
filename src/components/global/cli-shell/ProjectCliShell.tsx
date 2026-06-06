import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type MouseEvent as ReactMouseEvent,
  type PointerEvent as ReactPointerEvent,
} from 'react'
import { createPortal } from 'react-dom'
import {
  Maximize2,
  Minimize2,
  RotateCcw,
  Terminal,
  Trash2,
  X,
  ChevronDown,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { CLI_SHELL_COLLAPSED_HEIGHT_PX } from '@/lib/cli-shell/constants'
import { CliTerminalSpinner } from './CliTerminalSpinner'
import { useCliShell } from './CliShellProvider'

export function ProjectCliShell() {
  const { open, fullscreen } = useCliShell()

  if (fullscreen && open && typeof document !== 'undefined') {
    return createPortal(
      <div className="fixed inset-0 z-[140] flex min-h-0 flex-col bg-background">
        <ProjectCliShellPanel />
      </div>,
      document.body,
    )
  }

  return (
    <div
      className="shrink-0 border-t border-border bg-background"
      style={open ? undefined : { height: CLI_SHELL_COLLAPSED_HEIGHT_PX }}
    >
      {!open ? <ProjectCliShellCollapsedBar /> : <ProjectCliShellPanel />}
    </div>
  )
}

function ProjectCliShellCollapsedBar() {
  const { setOpen } = useCliShell()

  return (
    <button
      type="button"
      onClick={() => setOpen(true)}
      className="flex h-full w-full cursor-pointer items-center gap-2 px-4 text-left text-[13px] font-medium text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground sm:px-6"
      aria-label="Open terminal"
    >
      <Terminal className="h-3.5 w-3.5 shrink-0 opacity-60" />
      <span>Terminal</span>
    </button>
  )
}

function ProjectCliShellPanel() {
  const {
    open,
    setOpen,
    toggle,
    status,
    lines,
    runCommand,
    clearOutput,
    completeTab,
    isRunning,
    height,
    setHeight,
    retryBootstrap,
    bootstrapError,
    fullscreen,
    toggleFullscreen,
  } = useCliShell()

  const [input, setInput] = useState('')
  const [history, setHistory] = useState<string[]>([])
  const [historyIndex, setHistoryIndex] = useState<number | null>(null)
  const [isInputFocused, setIsInputFocused] = useState(false)
  const [cursorOffset, setCursorOffset] = useState(0)
  const [isResizing, setIsResizing] = useState(false)

  const terminalRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const cursorMeasureRef = useRef<HTMLSpanElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const lastTabRef = useRef<{ input: string; cursor: number; at: number } | null>(
    null,
  )
  const terminalPointerRef = useRef<{
    pointerId: number
    x: number
    y: number
  } | null>(null)

  const inputDisabled = status === 'bootstrapping' || isRunning

  const isInteractiveTerminalTarget = useCallback((target: EventTarget | null) => {
    if (!(target instanceof HTMLElement)) return false
    if (target === inputRef.current) return true
    return !!target.closest('button, input, form')
  }, [])

  const syncCursorPosition = useCallback(() => {
    const inputEl = inputRef.current
    const measureEl = cursorMeasureRef.current
    if (!inputEl || !measureEl) return

    const position = inputEl.selectionStart ?? inputEl.value.length
    measureEl.textContent = inputEl.value.slice(0, position) || '\u200b'
    setCursorOffset(measureEl.offsetWidth)
  }, [])

  const scrollToBottom = useCallback(() => {
    const el = terminalRef.current
    if (!el) return
    el.scrollTop = el.scrollHeight
  }, [])

  const focusInput = useCallback(() => {
    requestAnimationFrame(() => {
      inputRef.current?.focus()
      scrollToBottom()
    })
  }, [scrollToBottom])

  const applySuggestedCommand = useCallback(
    (command: string) => {
      if (inputDisabled) return
      setInput(command)
      setHistoryIndex(null)
      requestAnimationFrame(() => {
        inputRef.current?.focus()
        syncCursorPosition()
        scrollToBottom()
      })
    },
    [inputDisabled, scrollToBottom, syncCursorPosition],
  )

  useEffect(() => {
    if (!open) return
    scrollToBottom()
  }, [lines, open, isRunning, input, scrollToBottom])

  useEffect(() => {
    syncCursorPosition()
  }, [input, syncCursorPosition])

  useEffect(() => {
    if (open && !inputDisabled) {
      focusInput()
    }
  }, [open, inputDisabled, focusInput])

  const submitCommand = useCallback(async () => {
    if (inputDisabled) return

    const command = input.trim()
    if (command) {
      setHistory((prev) => {
        if (prev[prev.length - 1] === command) return prev
        return [...prev, command]
      })
    }
    setHistoryIndex(null)
    setInput('')
    await runCommand(command)
    focusInput()
  }, [input, inputDisabled, runCommand, focusInput])

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    void submitCommand()
  }

  const handleInputKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Tab') {
      event.preventDefault()
      if (inputDisabled) return

      const cursor = event.currentTarget.selectionStart ?? input.length
      const now = Date.now()
      const lastTab = lastTabRef.current
      const listOnly =
        !!lastTab &&
        lastTab.input === input &&
        lastTab.cursor === cursor &&
        now - lastTab.at < 400
      lastTabRef.current = { input, cursor, at: now }

      const result = completeTab(input, cursor, listOnly)
      if (!result) return

      setInput(result.input)
      requestAnimationFrame(() => {
        inputRef.current?.setSelectionRange(result.cursor, result.cursor)
        syncCursorPosition()
      })
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      if (history.length === 0) return
      const nextIndex =
        historyIndex === null
          ? history.length - 1
          : Math.max(0, historyIndex - 1)
      setHistoryIndex(nextIndex)
      setInput(history[nextIndex] ?? '')
      requestAnimationFrame(syncCursorPosition)
      return
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      if (historyIndex === null) return
      const nextIndex = historyIndex + 1
      if (nextIndex >= history.length) {
        setHistoryIndex(null)
        setInput('')
      } else {
        setHistoryIndex(nextIndex)
        setInput(history[nextIndex] ?? '')
      }
      requestAnimationFrame(syncCursorPosition)
    }
  }

  const handleTerminalPointerDown = (
    event: ReactPointerEvent<HTMLDivElement>,
  ) => {
    if (isInteractiveTerminalTarget(event.target)) return
    terminalPointerRef.current = {
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
    }
  }

  const clearTerminalPointer = (pointerId: number) => {
    if (terminalPointerRef.current?.pointerId === pointerId) {
      terminalPointerRef.current = null
    }
  }

  const handleTerminalPointerUp = (
    event: ReactPointerEvent<HTMLDivElement>,
  ) => {
    const start = terminalPointerRef.current
    clearTerminalPointer(event.pointerId)
    if (!start || start.pointerId !== event.pointerId) return
    if (isInteractiveTerminalTarget(event.target)) return

    const moved =
      Math.abs(event.clientX - start.x) > 4 ||
      Math.abs(event.clientY - start.y) > 4
    const selectedText = window.getSelection()?.toString().trim() ?? ''

    if (!moved && !selectedText) {
      focusInput()
    }
  }

  const handleTerminalPointerCancel = (
    event: ReactPointerEvent<HTMLDivElement>,
  ) => {
    clearTerminalPointer(event.pointerId)
  }

  const handleResizeMouseDown = useCallback((event: ReactMouseEvent) => {
    event.preventDefault()
    setIsResizing(true)
  }, [])

  useEffect(() => {
    if (!isResizing || fullscreen) return

    const handleMouseMove = (event: MouseEvent) => {
      if (!panelRef.current) return
      const panelBottom = panelRef.current.getBoundingClientRect().bottom
      setHeight(panelBottom - event.clientY)
    }

    const handleMouseUp = () => {
      setIsResizing(false)
    }

    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)
    document.body.style.cursor = 'row-resize'
    document.body.style.userSelect = 'none'

    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
  }, [fullscreen, isResizing, setHeight])

  const inputBusyLabel =
    status === 'bootstrapping' ? 'setting up' : isRunning ? 'working' : null

  return (
    <div
      ref={panelRef}
      className={cn(
        'relative flex min-h-0 flex-col overflow-hidden bg-background',
        fullscreen && 'h-full min-h-0 flex-1',
      )}
      style={fullscreen ? undefined : { height: `${height}px` }}
    >
      {!fullscreen && (
        <div
          role="separator"
          aria-orientation="horizontal"
          aria-label="Resize terminal"
          onMouseDown={handleResizeMouseDown}
          className={cn(
            'absolute inset-x-0 top-0 z-10 flex h-1.5 w-full cursor-row-resize items-center justify-center transition-colors hover:bg-primary/20 dark:hover:bg-sidebar-accent/60',
            isResizing && 'bg-primary/30 dark:bg-sidebar-accent/70',
          )}
        />
      )}

          <div className="flex shrink-0 items-center justify-between gap-3 border-b border-border px-4 py-2.5">
            <div className="flex min-w-0 items-center gap-2">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
              <Terminal className="h-3.5 w-3.5 text-muted-foreground" />
            </span>
            <span className="truncate text-[13px] font-semibold text-foreground">
              Terminal
            </span>
            </div>

            <div className="flex shrink-0 items-center gap-1">
            {(status === 'error' || bootstrapError) && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8"
                onClick={retryBootstrap}
                title="Retry setup"
              >
                <RotateCcw className="h-3.5 w-3.5" />
              </Button>
            )}
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              onClick={clearOutput}
              title="Clear output"
            >
              <Trash2 className="h-3.5 w-3.5" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              onClick={toggleFullscreen}
              title={fullscreen ? 'Exit full screen' : 'Full screen'}
            >
              {fullscreen ? (
                <Minimize2 className="h-3.5 w-3.5" />
              ) : (
                <Maximize2 className="h-3.5 w-3.5" />
              )}
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              onClick={toggle}
              title="Minimize shell"
            >
              <ChevronDown className="h-3.5 w-3.5" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              onClick={() => setOpen(false)}
              title="Close shell"
            >
              <X className="h-3.5 w-3.5" />
            </Button>
            </div>
          </div>

          <div
            ref={terminalRef}
            className="flex min-h-0 flex-1 flex-col overflow-y-auto overflow-x-hidden bg-muted/[0.06] px-4 py-3 font-mono text-[12px] leading-5 select-text selection:bg-primary/20"
            onPointerDown={handleTerminalPointerDown}
            onPointerUp={handleTerminalPointerUp}
            onPointerCancel={handleTerminalPointerCancel}
          >
          {lines.map((line, index) => (
            <div
              key={`${line.type}-${index}`}
              className={cn(
                'whitespace-pre-wrap break-words',
                line.type === 'command' &&
                  'mt-2 text-foreground first:mt-0 font-medium',
                line.type === 'stdout' && 'text-foreground',
                line.type === 'stderr' && 'text-destructive',
                line.type === 'system' && 'text-muted-foreground italic',
                line.type === 'suggestions' && 'text-muted-foreground italic',
              )}
            >
              {line.type === 'command' ? (
                <>
                  <span className="mr-1 text-muted-foreground">$</span>
                  {line.text}
                </>
              ) : line.type === 'suggestions' ? (
                <>
                  <span>Try: </span>
                  {line.commands.map((command, commandIndex) => (
                    <span key={command}>
                      {commandIndex > 0 ? ', ' : null}
                      <button
                        type="button"
                        disabled={inputDisabled}
                        onClick={() => applySuggestedCommand(command)}
                        className="cursor-pointer rounded-sm px-0.5 -mx-0.5 font-mono text-[12px] not-italic text-cyan-600 underline-offset-2 transition-colors hover:bg-cyan-500/10 hover:text-cyan-700 hover:underline dark:text-cyan-400 dark:hover:text-cyan-300 disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        {command}
                      </button>
                    </span>
                  ))}
                </>
              ) : (
                line.text
              )}
            </div>
          ))}

          <form
            onSubmit={handleSubmit}
            className={cn(
              'flex min-w-0 cursor-text items-center',
              lines.length > 0 ? 'mt-2' : 'mt-0',
            )}
          >
            <span
              aria-hidden
              className="mr-1 shrink-0 select-none text-muted-foreground"
            >
              $
            </span>
            {inputBusyLabel ? (
              <CliTerminalSpinner label={inputBusyLabel} />
            ) : (
            <div className="relative min-w-0 flex-1">
              <span
                ref={cursorMeasureRef}
                aria-hidden
                className="pointer-events-none invisible absolute left-0 top-0 whitespace-pre font-mono text-[12px] leading-5"
              />
              <input
                ref={inputRef}
                value={input}
                onChange={(event) => {
                  setInput(event.target.value)
                  requestAnimationFrame(syncCursorPosition)
                }}
                onFocus={() => {
                  setIsInputFocused(true)
                  requestAnimationFrame(syncCursorPosition)
                }}
                onBlur={() => setIsInputFocused(false)}
                onSelect={syncCursorPosition}
                onKeyUp={syncCursorPosition}
                onClick={syncCursorPosition}
                onKeyDown={handleInputKeyDown}
                disabled={inputDisabled}
                className={cn(
                  'relative w-full min-w-0 bg-transparent p-0 font-mono text-[12px] leading-5 text-foreground outline-none',
                  'caret-transparent selection:bg-primary/25',
                  'disabled:cursor-not-allowed disabled:text-transparent',
                )}
                spellCheck={false}
                autoComplete="off"
                autoCorrect="off"
                autoCapitalize="off"
                aria-label="CLI command input"
              />
              {isInputFocused && (
                <span
                  aria-hidden
                  className={cn(
                    'pointer-events-none absolute top-0 z-[1] h-5 w-[1ch] bg-foreground',
                    'animate-[terminal-cursor-blink_1.06s_step-end_infinite]',
                    'motion-reduce:animate-none motion-reduce:opacity-100',
                  )}
                  style={{ left: cursorOffset }}
                />
              )}
            </div>
            )}
          </form>
        </div>
    </div>
  )
}
