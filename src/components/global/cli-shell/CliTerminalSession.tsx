import { useCallback, useEffect, useRef } from 'react'
import { Terminal } from '@xterm/xterm'
import { FitAddon } from '@xterm/addon-fit'
import { useTheme } from 'next-themes'
import { createCliTerminalWebLinksAddon } from '@/lib/cli-shell/cli-terminal-web-links'
import { createCliTerminalSuggestionLinksAddon } from '@/lib/cli-shell/cli-terminal-suggestion-links'
import { CLI_SHELL_TRY_COMMANDS } from '@/lib/cli-shell/constants'
import { createCliTerminalInputHandler } from '@/lib/cli-shell/cli-terminal-input'
import { getCliTerminalTheme } from '@/lib/cli-shell/cli-terminal-theme'
import type { CliTerminalApi } from '@/lib/cli-shell/cli-terminal-api'
import { cn } from '@/lib/utils'
import { useCliShell } from './CliShellProvider'

type CliTerminalSessionProps = {
  sessionId: string
  isActive: boolean
  isPanelResizing?: boolean
}

export function CliTerminalSession({
  sessionId,
  isActive,
  isPanelResizing = false,
}: CliTerminalSessionProps) {
  const {
    open,
    registerTerminal,
    unregisterTerminal,
    writeSessionWelcome,
    runCommand,
    completeTab,
    isRunning,
    fullscreen,
    exitFullscreen,
    getTerminalPrompt,
  } = useCliShell()

  const { resolvedTheme } = useTheme()

  const terminalContainerRef = useRef<HTMLDivElement>(null)
  const terminalRef = useRef<Terminal | null>(null)
  const fitAddonRef = useRef<FitAddon | null>(null)
  const isRunningRef = useRef(isRunning)
  const showInputPromptRef = useRef<(() => void) | null>(null)
  const inputSessionRef = useRef<ReturnType<
    typeof createCliTerminalInputHandler
  > | null>(null)
  const isPanelResizingRef = useRef(isPanelResizing)
  const fitRafRef = useRef<number | null>(null)
  const runCommandRef = useRef(runCommand)
  const completeTabRef = useRef(completeTab)
  const registerTerminalRef = useRef(registerTerminal)
  const unregisterTerminalRef = useRef(unregisterTerminal)
  const writeSessionWelcomeRef = useRef(writeSessionWelcome)
  const isActiveRef = useRef(isActive)
  const welcomeFitAttemptsRef = useRef(0)
  const welcomeCompleteRef = useRef(false)
  const fullscreenRef = useRef(fullscreen)
  const exitFullscreenRef = useRef(exitFullscreen)
  const getTerminalPromptRef = useRef(getTerminalPrompt)
  const sessionIdRef = useRef(sessionId)

  isRunningRef.current = isRunning
  fullscreenRef.current = fullscreen
  exitFullscreenRef.current = exitFullscreen
  getTerminalPromptRef.current = getTerminalPrompt
  isPanelResizingRef.current = isPanelResizing
  runCommandRef.current = runCommand
  completeTabRef.current = completeTab
  registerTerminalRef.current = registerTerminal
  unregisterTerminalRef.current = unregisterTerminal
  writeSessionWelcomeRef.current = writeSessionWelcome
  isActiveRef.current = isActive
  sessionIdRef.current = sessionId

  const tryWriteSessionWelcome = useCallback(() => {
    if (!isActiveRef.current) return

    const fitAddon = fitAddonRef.current
    const terminal = terminalRef.current
    if (!fitAddon || !terminal) return

    const proposed = fitAddon.proposeDimensions()
    if (!proposed || proposed.cols <= 0 || proposed.rows <= 0) {
      welcomeFitAttemptsRef.current += 1
      if (welcomeFitAttemptsRef.current < 120) {
        requestAnimationFrame(tryWriteSessionWelcome)
      }
      return
    }

    welcomeFitAttemptsRef.current = 0

    if (!writeSessionWelcomeRef.current(sessionIdRef.current)) {
      return
    }

    welcomeCompleteRef.current = true

    try {
      fitAddon.fit()
    } catch {
      /* container may have zero size during layout */
    }

    requestAnimationFrame(() => {
      if (isActiveRef.current) {
        terminal.focus()
      }
    })
  }, [])

  const fitTerminal = useCallback(() => {
    if (fitRafRef.current !== null) {
      cancelAnimationFrame(fitRafRef.current)
    }

    fitRafRef.current = requestAnimationFrame(() => {
      fitRafRef.current = null

      try {
        const fitAddon = fitAddonRef.current
        const terminal = terminalRef.current
        if (!fitAddon || !terminal) return

        const proposed = fitAddon.proposeDimensions()
        if (!proposed || proposed.cols <= 0 || proposed.rows <= 0) return
        if (
          proposed.cols === terminal.cols &&
          proposed.rows === terminal.rows
        ) {
          return
        }

        fitAddon.fit()
      } catch {
        /* container may have zero size during layout */
      }
    })
  }, [])

  useEffect(() => {
    const container = terminalContainerRef.current
    if (!container) return

    const terminal = new Terminal({
      cursorBlink: true,
      fontSize: 13,
      lineHeight: 1.4,
      fontFamily:
        'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
      theme: getCliTerminalTheme(resolvedTheme),
      scrollback: 5000,
      convertEol: true,
      allowTransparency: true,
      scrollSensitivity: 1,
    })

    const fitAddon = new FitAddon()
    const openLink = (event: MouseEvent, uri: string) => {
      window.open(uri, '_blank', 'noopener,noreferrer')
      event.preventDefault()
    }
    const webLinksAddon = createCliTerminalWebLinksAddon(openLink)
    const suggestionLinksAddon = createCliTerminalSuggestionLinksAddon(
      CLI_SHELL_TRY_COMMANDS,
      (event, command) => {
        event.preventDefault()
        inputSessionRef.current?.setInput(command)
      },
    )

    terminal.loadAddon(fitAddon)
    terminal.loadAddon(webLinksAddon)
    terminal.loadAddon(suggestionLinksAddon)
    terminal.attachCustomKeyEventHandler((event) => {
      if (event.type !== 'keydown' || event.key !== 'Escape') return true
      if (!fullscreenRef.current) return true
      event.preventDefault()
      exitFullscreenRef.current()
      return false
    })
    terminal.open(container)

    terminalRef.current = terminal
    fitAddonRef.current = fitAddon

    const currentSessionId = sessionIdRef.current
    const inputSession = createCliTerminalInputHandler({
      terminal,
      getIsRunning: () => isRunningRef.current,
      getPrompt: () => getTerminalPromptRef.current(),
      onRunCommand: (command) =>
        runCommandRef.current(command, currentSessionId),
      onTabComplete: (...args) =>
        completeTabRef.current(...args, currentSessionId),
    })
    inputSessionRef.current = inputSession

    const api: CliTerminalApi = {
      write: (data, callback) => terminal.write(data, callback),
      writeln: (data, callback) => terminal.writeln(data, callback),
      clear: () => terminal.clear(),
      focus: () => terminal.focus(),
      showInputPrompt: inputSession.showPrompt,
      prepareInputLine: inputSession.prepareInputLine,
      resetForWelcome: inputSession.resetForWelcome,
      markWelcomeComplete: inputSession.markWelcomeComplete,
      clearScreen: inputSession.clearScreen,
      getPrompt: () => getTerminalPromptRef.current(),
      afterOutputLine: () => {
        webLinksAddon.scanLastWrittenLine()
        suggestionLinksAddon.scanLastWrittenLine()
      },
    }

    showInputPromptRef.current = inputSession.showPrompt

    registerTerminalRef.current(currentSessionId, api)

    requestAnimationFrame(tryWriteSessionWelcome)

    const dataDisposable = terminal.onData(inputSession.onData)

    const resizeObserver = new ResizeObserver(() => {
      if (!isPanelResizingRef.current) {
        fitTerminal()
        if (!welcomeCompleteRef.current) {
          requestAnimationFrame(tryWriteSessionWelcome)
        }
      }
    })
    resizeObserver.observe(container)

    return () => {
      if (fitRafRef.current !== null) {
        cancelAnimationFrame(fitRafRef.current)
        fitRafRef.current = null
      }
      dataDisposable.dispose()
      resizeObserver.disconnect()
      unregisterTerminalRef.current(currentSessionId)
      terminal.dispose()
      showInputPromptRef.current = null
      inputSessionRef.current = null
      terminalRef.current = null
      fitAddonRef.current = null
      welcomeFitAttemptsRef.current = 0
      welcomeCompleteRef.current = false
    }
  }, [fitTerminal, sessionId, tryWriteSessionWelcome])

  useEffect(() => {
    const terminal = terminalRef.current
    if (!terminal) return
    terminal.options.theme = getCliTerminalTheme(resolvedTheme)
  }, [resolvedTheme])

  useEffect(() => {
    if (!open || !isActive) return
    requestAnimationFrame(tryWriteSessionWelcome)
  }, [isActive, open, tryWriteSessionWelcome])

  useEffect(() => {
    if (!open || !isActive) return
    fitTerminal()
    const focusRaf = requestAnimationFrame(() => {
      terminalRef.current?.focus()
    })
    return () => cancelAnimationFrame(focusRaf)
  }, [fitTerminal, fullscreen, isActive, open])

  useEffect(() => {
    if (!isPanelResizing) {
      fitTerminal()
    }
  }, [fitTerminal, isPanelResizing])

  return (
    <div
      ref={terminalContainerRef}
      className={cn(
        'cli-terminal h-full min-h-0',
        '[&_.xterm]:h-full [&_.xterm-viewport]:!overflow-y-auto',
        !isActive && 'hidden',
      )}
      onPointerDown={() => {
        if (isActive) {
          terminalRef.current?.focus()
        }
      }}
    />
  )
}
