import { useCallback, useEffect, useRef } from 'react'
import { Terminal } from '@xterm/xterm'
import { FitAddon } from '@xterm/addon-fit'
import { SearchAddon } from '@xterm/addon-search'
import { useTheme } from 'next-themes'
import { createCliTerminalWebLinksAddon } from '@/lib/cli-shell/cli-terminal-web-links'
import { createCliTerminalSuggestionLinksAddon } from '@/lib/cli-shell/cli-terminal-suggestion-links'
import { createCliTerminalInputHandler } from '@/lib/cli-shell/cli-terminal-input'
import { getCliTerminalTheme } from '@/lib/cli-shell/cli-terminal-theme'
import { getTerminalBufferText } from '@/lib/cli-shell/cli-terminal-buffer'
import { getCliTerminalSearchOptions } from '@/lib/cli-shell/cli-terminal-search-options'
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
    isSessionRunning,
    cancelRunning,
    fullscreen,
    toggleFullscreen,
    exitFullscreen,
    getTerminalPrompt,
    getCommandHistory,
    persistCommandHistory,
    getSuggestionCommands,
    registerSearchController,
    unregisterSearchController,
    reportSearchResults,
    terminalSearchOpen,
    setTerminalSearchOpen,
  } = useCliShell()

  const { resolvedTheme } = useTheme()

  const terminalContainerRef = useRef<HTMLDivElement>(null)
  const terminalRef = useRef<Terminal | null>(null)
  const fitAddonRef = useRef<FitAddon | null>(null)
  const searchAddonRef = useRef<SearchAddon | null>(null)
  const isSessionRunningRef = useRef(isSessionRunning)
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
  const toggleFullscreenRef = useRef(toggleFullscreen)
  const exitFullscreenRef = useRef(exitFullscreen)
  const getTerminalPromptRef = useRef(getTerminalPrompt)
  const sessionIdRef = useRef(sessionId)
  const getSuggestionCommandsRef = useRef(getSuggestionCommands)
  const persistCommandHistoryRef = useRef(persistCommandHistory)
  const cancelRunningRef = useRef(cancelRunning)
  const getCommandHistoryRef = useRef(getCommandHistory)
  const registerSearchControllerRef = useRef(registerSearchController)
  const unregisterSearchControllerRef = useRef(unregisterSearchController)
  const reportSearchResultsRef = useRef(reportSearchResults)
  const terminalSearchOpenRef = useRef(terminalSearchOpen)
  const setTerminalSearchOpenRef = useRef(setTerminalSearchOpen)

  isSessionRunningRef.current = isSessionRunning
  fullscreenRef.current = fullscreen
  toggleFullscreenRef.current = toggleFullscreen
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
  getSuggestionCommandsRef.current = getSuggestionCommands
  persistCommandHistoryRef.current = persistCommandHistory
  cancelRunningRef.current = cancelRunning
  getCommandHistoryRef.current = getCommandHistory
  registerSearchControllerRef.current = registerSearchController
  unregisterSearchControllerRef.current = unregisterSearchController
  reportSearchResultsRef.current = reportSearchResults
  terminalSearchOpenRef.current = terminalSearchOpen
  setTerminalSearchOpenRef.current = setTerminalSearchOpen

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
    const searchAddon = new SearchAddon()
    const openLink = (event: MouseEvent, uri: string) => {
      window.open(uri, '_blank', 'noopener,noreferrer')
      event.preventDefault()
    }
    const webLinksAddon = createCliTerminalWebLinksAddon(openLink)
    const suggestionLinksAddon = createCliTerminalSuggestionLinksAddon(
      () => getSuggestionCommandsRef.current(),
      (event, command) => {
        event.preventDefault()
        inputSessionRef.current?.setInput(command)
      },
    )

    terminal.loadAddon(fitAddon)
    terminal.loadAddon(searchAddon)
    terminal.loadAddon(webLinksAddon)
    terminal.loadAddon(suggestionLinksAddon)
    terminal.attachCustomKeyEventHandler((event) => {
      if (event.type !== 'keydown') return true

      if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault()
        if (!fullscreenRef.current) {
          toggleFullscreenRef.current()
        }
        return false
      }

      if (event.key === 'Escape' && fullscreenRef.current) {
        event.preventDefault()
        if (terminalSearchOpenRef.current) {
          setTerminalSearchOpenRef.current(false)
        } else {
          exitFullscreenRef.current()
        }
        return false
      }

      return true
    })
    terminal.open(container)

    terminalRef.current = terminal
    fitAddonRef.current = fitAddon
    searchAddonRef.current = searchAddon

    const currentSessionId = sessionIdRef.current
    const inputSession = createCliTerminalInputHandler({
      terminal,
      getIsRunning: () =>
        isSessionRunningRef.current(currentSessionId),
      getPrompt: () => getTerminalPromptRef.current(),
      initialHistory: getCommandHistoryRef.current(),
      onHistoryChange: (history) =>
        persistCommandHistoryRef.current(history),
      onCancelRunning: () =>
        cancelRunningRef.current(currentSessionId),
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
      getLastCommand: () => inputSession.getLastCommand(),
      getSelection: () => terminal.getSelection(),
      getBufferText: () => getTerminalBufferText(terminal),
    }

    showInputPromptRef.current = inputSession.showPrompt

    registerTerminalRef.current(currentSessionId, api)
    let lastSearchQuery = ''
    const searchOptionsFor = (caseSensitive: boolean) =>
      getCliTerminalSearchOptions(resolvedTheme, caseSensitive)

    const resultsDisposable = searchAddon.onDidChangeResults((results) => {
      reportSearchResultsRef.current(currentSessionId, results)
    })

    registerSearchControllerRef.current(currentSessionId, {
      search: (query, options) => {
        lastSearchQuery = query
        return searchAddon.findNext(
          query,
          searchOptionsFor(options.caseSensitive),
        )
      },
      findNext: () =>
        lastSearchQuery
          ? searchAddon.findNext(lastSearchQuery, searchOptionsFor(false))
          : false,
      findPrevious: () =>
        lastSearchQuery
          ? searchAddon.findPrevious(lastSearchQuery, searchOptionsFor(false))
          : false,
      clear: () => {
        lastSearchQuery = ''
        searchAddon.clearDecorations()
        reportSearchResultsRef.current(currentSessionId, {
          resultIndex: -1,
          resultCount: 0,
        })
      },
    })

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
      resultsDisposable.dispose()
      resizeObserver.disconnect()
      unregisterTerminalRef.current(currentSessionId)
      unregisterSearchControllerRef.current(currentSessionId)
      terminal.dispose()
      showInputPromptRef.current = null
      inputSessionRef.current = null
      terminalRef.current = null
      fitAddonRef.current = null
      searchAddonRef.current = null
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
