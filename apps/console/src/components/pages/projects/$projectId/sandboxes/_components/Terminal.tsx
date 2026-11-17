import { useEffect, useRef, useState } from 'react'
import type { Terminal as XTerm } from '@xterm/xterm'
import { useTheme } from 'next-themes'
import { RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { sdk } from '@/lib/appwrite/sdk'
import { getCliTerminalTheme } from '@/lib/cli-shell/cli-terminal-theme'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/** Seconds one output request waits for new output (Cloud allows at most 30). */
const OUTPUT_WAIT = 25

/** Delay before reading output again after a failed request. */
const RETRY_DELAY = 2 * 1000

/** Coalesce resize events while the panel is being dragged. */
const RESIZE_DELAY = 150

type State =
  | { kind: 'starting' }
  | { kind: 'running' }
  | { kind: 'exited'; exitCode: number | null }
  | { kind: 'error'; message: string }

interface TerminalProps {
  projectId: string
  sandboxId: string
}

/**
 * An interactive shell in the sandbox. It runs as a terminal process: output
 * is read by long polling from a byte offset, keystrokes are posted as input,
 * and the process is killed when the terminal closes.
 */
export function Terminal({ projectId, sandboxId }: TerminalProps) {
  const t = useT()
  const { resolvedTheme } = useTheme()
  const containerRef = useRef<HTMLDivElement>(null)
  const terminalRef = useRef<XTerm | null>(null)
  const resolvedThemeRef = useRef(resolvedTheme)
  resolvedThemeRef.current = resolvedTheme
  const [session, setSession] = useState(0)
  const [state, setState] = useState<State>({ kind: 'starting' })

  // Read the latest copy inside the session effect without restarting it.
  const exitedLabel = t('Process exited with code')
  const labelRef = useRef(exitedLabel)
  labelRef.current = exitedLabel

  useEffect(() => {
    const container = containerRef.current
    if (!container) return

    const sandboxes = sdk.forProject(projectId).sandboxes
    let disposed = false
    let processId: string | null = null
    let terminal: XTerm | null = null
    let observer: ResizeObserver | null = null
    let resizeTimer: ReturnType<typeof setTimeout> | undefined
    setState({ kind: 'starting' })

    // Keystrokes go out one request at a time, in order; typing during a
    // request is sent together after it.
    let pendingInput = ''
    let sending = false
    const flushInput = async () => {
      if (sending || !processId) return
      sending = true
      while (pendingInput !== '' && !disposed) {
        const data = pendingInput
        pendingInput = ''
        try {
          await sandboxes.createProcessInput({ sandboxId, processId, data })
        } catch {
          // The process has exited; the output loop reports it.
          pendingInput = ''
        }
      }
      sending = false
    }

    const readOutput = async (id: string) => {
      let offset = 0
      while (!disposed) {
        try {
          const output = await sandboxes.getProcessOutput({
            sandboxId,
            processId: id,
            offset,
            wait: OUTPUT_WAIT,
          })
          if (disposed) return
          if (output.data) terminal?.write(output.data)
          offset = output.nextOffset
          if (!output.running) {
            const code = output.exitCode ?? '?'
            terminal?.write(
              `\r\n\x1b[2m[${labelRef.current} ${code}]\x1b[0m\r\n`,
            )
            processId = null
            setState({ kind: 'exited', exitCode: output.exitCode })
            return
          }
        } catch (error) {
          if (disposed) return
          const code = (error as { code?: number }).code
          if (code === 404) {
            processId = null
            setState({ kind: 'exited', exitCode: null })
            return
          }
          await new Promise((resolve) => setTimeout(resolve, RETRY_DELAY))
        }
      }
    }

    const start = async () => {
      const [{ Terminal: XTermClass }, { FitAddon }] = await Promise.all([
        import('@xterm/xterm'),
        import('@xterm/addon-fit'),
      ])
      if (disposed) return

      terminal = new XTermClass({
        cursorBlink: true,
        fontSize: 13,
        lineHeight: 1.4,
        fontFamily:
          'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
        theme: getCliTerminalTheme(resolvedThemeRef.current),
        scrollback: 5000,
        allowTransparency: true,
      })
      terminalRef.current = terminal
      const fit = new FitAddon()
      terminal.loadAddon(fit)
      terminal.open(container)
      fit.fit()

      terminal.onData((data) => {
        pendingInput += data
        void flushInput()
      })

      observer = new ResizeObserver(() => {
        clearTimeout(resizeTimer)
        resizeTimer = setTimeout(() => {
          if (disposed || !terminal) return
          fit.fit()
          if (!processId) return
          void sandboxes
            .updateProcessSize({
              sandboxId,
              processId,
              cols: terminal.cols,
              rows: terminal.rows,
            })
            .catch(() => undefined)
        }, RESIZE_DELAY)
      })
      observer.observe(container)

      try {
        const process = await sandboxes.createProcess({
          sandboxId,
          pty: true,
          cols: terminal.cols,
          rows: terminal.rows,
        })
        if (disposed) {
          void sandboxes
            .deleteProcess({ sandboxId, processId: process.$id })
            .catch(() => undefined)
          return
        }
        processId = process.$id
        setState({ kind: 'running' })
        terminal.focus()
        void flushInput()
        void readOutput(process.$id)
      } catch (error) {
        if (!disposed) {
          setState({ kind: 'error', message: getErrorMessage(error) })
        }
      }
    }

    void start()

    return () => {
      disposed = true
      clearTimeout(resizeTimer)
      observer?.disconnect()
      terminal?.dispose()
      terminalRef.current = null
      if (processId) {
        void sandboxes
          .deleteProcess({ sandboxId, processId })
          .catch(() => undefined)
      }
    }
  }, [projectId, sandboxId, session])

  useEffect(() => {
    if (terminalRef.current) {
      terminalRef.current.options.theme = getCliTerminalTheme(resolvedTheme)
    }
  }, [resolvedTheme])

  return (
    <div className="flex flex-col gap-3">
      <div className="relative h-[480px] overflow-hidden rounded-lg border border-border bg-card p-3">
        <div ref={containerRef} className="h-full w-full" />
        {state.kind === 'starting' && (
          <div className="absolute inset-0 flex items-center justify-center text-[13px] text-muted-foreground">
            {t('Starting terminal...')}
          </div>
        )}
        {state.kind === 'error' && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-card/90 px-6 text-center">
            <p className="text-[13px] text-destructive">{state.message}</p>
          </div>
        )}
      </div>
      {(state.kind === 'exited' || state.kind === 'error') && (
        <div className="flex justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-8 text-[13px]"
            onClick={() => setSession((value) => value + 1)}
          >
            <RotateCcw className="h-3.5 w-3.5 me-1.5" />
            {t('Restart terminal')}
          </Button>
        </div>
      )}
    </div>
  )
}
