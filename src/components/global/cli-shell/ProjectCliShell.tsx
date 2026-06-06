import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type PointerEvent as ReactPointerEvent,
} from 'react'
import {
  Loader2,
  RotateCcw,
  Terminal,
  Trash2,
  X,
  ChevronDown,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useCliShell } from './CliShellProvider'

const WELCOME_LINES = [
  'Appwrite CLI shell (browser runtime via almostnode)',
  'Your console session is used automatically. Project context is preconfigured.',
  'Examples: appwrite users list --json | appwrite functions list | appwrite whoami',
]

export function ProjectCliShell() {
  const {
    open,
    setOpen,
    toggle,
    status,
    lines,
    runCommand,
    clearOutput,
    cancelRunning,
    isRunning,
    height,
    setHeight,
    retryBootstrap,
  } = useCliShell()

  const [input, setInput] = useState('')
  const [history, setHistory] = useState<string[]>([])
  const [historyIndex, setHistoryIndex] = useState<number | null>(null)

  const outputRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const resizeRef = useRef<{ startY: number; startHeight: number } | null>(
    null,
  )

  useEffect(() => {
    if (!open) return
    const el = outputRef.current
    if (el) {
      el.scrollTop = el.scrollHeight
    }
  }, [lines, open])

  useEffect(() => {
    if (open) {
      requestAnimationFrame(() => inputRef.current?.focus())
    }
  }, [open])

  const submitCommand = useCallback(async () => {
    const command = input.trim()
    if (!command || isRunning) return

    setHistory((prev) => {
      if (prev[prev.length - 1] === command) return prev
      return [...prev, command]
    })
    setHistoryIndex(null)
    setInput('')
    await runCommand(command)
  }, [input, isRunning, runCommand])

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    void submitCommand()
  }

  const handleInputKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'c' && event.ctrlKey) {
      if (isRunning) {
        event.preventDefault()
        cancelRunning()
      }
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
    }
  }

  const handleResizePointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
    event.preventDefault()
    resizeRef.current = { startY: event.clientY, startHeight: height }
    event.currentTarget.setPointerCapture(event.pointerId)
  }

  const handleResizePointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    const state = resizeRef.current
    if (!state) return
    const delta = state.startY - event.clientY
    setHeight(state.startHeight + delta)
  }

  const handleResizePointerUp = (event: ReactPointerEvent<HTMLDivElement>) => {
    resizeRef.current = null
    event.currentTarget.releasePointerCapture(event.pointerId)
  }

  const statusLabel =
    status === 'bootstrapping'
      ? 'Setting up'
      : status === 'running'
        ? 'Running'
        : status === 'error'
          ? 'Error'
          : status === 'ready'
            ? 'Ready'
            : 'Idle'

  return (
    <>
      {!open && (
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="fixed bottom-0 left-1/2 z-[105] -translate-x-1/2 rounded-t-lg border border-b-0 border-border bg-card px-4 py-1.5 text-[12px] font-medium text-muted-foreground shadow-sm transition-colors hover:bg-muted/60 hover:text-foreground"
          aria-label="Open Appwrite CLI shell"
        >
          <span className="inline-flex items-center gap-1.5">
            <Terminal className="h-3.5 w-3.5" />
            CLI
          </span>
        </button>
      )}

      <div
        className={cn(
          'fixed inset-x-0 bottom-0 z-[105] flex flex-col border-t border-border bg-[#0d1117] text-[#e6edf3] shadow-[0_-8px_30px_rgba(0,0,0,0.25)] transition-transform duration-200 ease-out',
          open ? 'translate-y-0' : 'translate-y-full pointer-events-none',
        )}
        style={{ height: `${height}px`, maxHeight: '55dvh' }}
        aria-hidden={!open}
      >
        <div
          role="separator"
          aria-orientation="horizontal"
          aria-label="Resize CLI shell"
          className="group flex h-2 shrink-0 cursor-row-resize items-center justify-center border-b border-border/40 bg-[#161b22] touch-none"
          onPointerDown={handleResizePointerDown}
          onPointerMove={handleResizePointerMove}
          onPointerUp={handleResizePointerUp}
          onPointerCancel={handleResizePointerUp}
        >
          <div className="h-1 w-10 rounded-full bg-border/80 group-hover:bg-muted-foreground/60" />
        </div>

        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-border/40 bg-[#161b22] px-3 py-2">
          <div className="flex min-w-0 items-center gap-2">
            <Terminal className="h-4 w-4 shrink-0 text-[#58a6ff]" />
            <span className="truncate text-[13px] font-medium">
              Appwrite CLI
            </span>
            <span
              className={cn(
                'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide',
                status === 'ready' && 'bg-emerald-500/15 text-emerald-400',
                status === 'running' && 'bg-amber-500/15 text-amber-300',
                status === 'bootstrapping' && 'bg-sky-500/15 text-sky-300',
                status === 'error' && 'bg-red-500/15 text-red-300',
                status === 'idle' && 'bg-muted/30 text-muted-foreground',
              )}
            >
              {statusLabel}
            </span>
          </div>

          <div className="flex shrink-0 items-center gap-1">
            {status === 'error' && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-7 w-7 text-muted-foreground hover:text-foreground"
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
              className="h-7 w-7 text-muted-foreground hover:text-foreground"
              onClick={clearOutput}
              title="Clear output"
            >
              <Trash2 className="h-3.5 w-3.5" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-7 w-7 text-muted-foreground hover:text-foreground"
              onClick={toggle}
              title="Minimize shell"
            >
              <ChevronDown className="h-3.5 w-3.5" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-7 w-7 text-muted-foreground hover:text-foreground"
              onClick={() => setOpen(false)}
              title="Close shell"
            >
              <X className="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>

        <div
          ref={outputRef}
          className="min-h-0 flex-1 overflow-y-auto px-3 py-2 font-mono text-[12px] leading-5"
        >
          {lines.length === 0 && (
            <div className="space-y-1 text-[#8b949e]">
              {WELCOME_LINES.map((line) => (
                <p key={line}>{line}</p>
              ))}
            </div>
          )}

          {lines.map((line, index) => (
            <div
              key={`${line.type}-${index}`}
              className={cn(
                'whitespace-pre-wrap break-words',
                line.type === 'command' && 'text-[#79c0ff] mt-2 first:mt-0',
                line.type === 'stdout' && 'text-[#e6edf3]',
                line.type === 'stderr' && 'text-[#ffa198]',
                line.type === 'system' && 'text-[#8b949e] italic',
              )}
            >
              {line.type === 'command' ? `$ ${line.text}` : line.text}
            </div>
          ))}

          {(status === 'bootstrapping' || isRunning) && (
            <div className="mt-2 inline-flex items-center gap-1.5 text-[#8b949e]">
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
              {status === 'bootstrapping' ? 'Bootstrapping...' : 'Running...'}
            </div>
          )}
        </div>

        <form
          onSubmit={handleSubmit}
          className="flex shrink-0 items-center gap-2 border-t border-border/40 bg-[#0d1117] px-3 py-2"
        >
          <span className="shrink-0 font-mono text-[12px] text-[#58a6ff]">
            $
          </span>
          <input
            ref={inputRef}
            value={input}
            onChange={(event) => setInput(event.target.value)}
            onKeyDown={handleInputKeyDown}
            disabled={status === 'bootstrapping'}
            placeholder={
              status === 'bootstrapping'
                ? 'Waiting for CLI setup...'
                : 'appwrite users list --json'
            }
            className="min-w-0 flex-1 bg-transparent font-mono text-[12px] text-[#e6edf3] outline-none placeholder:text-[#484f58] disabled:cursor-not-allowed disabled:opacity-60"
            spellCheck={false}
            autoComplete="off"
            autoCorrect="off"
            autoCapitalize="off"
            aria-label="CLI command input"
          />
          <Button
            type="submit"
            size="sm"
            className="h-7 shrink-0 px-2 text-[12px]"
            disabled={!input.trim() || status === 'bootstrapping' || isRunning}
          >
            Run
          </Button>
        </form>
      </div>
    </>
  )
}
