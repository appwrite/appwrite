import { useCallback, useEffect, useRef } from 'react'
import type { Terminal } from '@xterm/xterm'
import type { FitAddon } from '@xterm/addon-fit'
import type { SearchAddon } from '@xterm/addon-search'
import { useTheme } from 'next-themes'
import { createCliTerminalWebLinksAddon } from '@/lib/cli-shell/cli-terminal-web-links'
import { createCliTerminalSuggestionLinksAddon } from '@/lib/cli-shell/cli-terminal-suggestion-links'
import { createCliTerminalInputHandler } from '@/lib/cli-shell/cli-terminal-input'
import { getCliTerminalTheme } from '@/lib/cli-shell/cli-terminal-theme'
import { getTerminalBufferText } from '@/lib/cli-shell/cli-terminal-buffer'
import { getCliTerminalSearchOptions } from '@/lib/cli-shell/cli-terminal-search-options'
import {
  countTextSearchMatches,
  navigateCliTerminalSearch,
  runCliTerminalSearch,
  type CliTerminalSearchNavigation,
} from '@/lib/cli-shell/cli-terminal-search-run'
import type { CliTerminalApi } from '@/lib/cli-shell/cli-terminal-api'
import { scheduleCliTerminalMount } from '@/lib/cli-shell/schedule-cli-terminal-mount'
import { cn } from '@/lib/utils'
import { CLI_SHELL_COLLAPSE_MS } from '@/lib/cli-shell/constants'
import { useCliShell } from './CliShellProvider'

type CliTerminalSessionProps = {
  sessionId: string
  isVisible: boolean
  isFocused: boolean
  isPanelResizing?: boolean
  isSplitPane?: boolean
  /** Defer xterm init until after the panel expand animation (first open). */
  deferInit?: boolean
}

function runTerminalSearchNavigation(
  sessionId: string,
  searchAddon: SearchAddon,
  terminal: Terminal,
  query: string,
  direction: CliTerminalSearchNavigation,
  options: {
    resolvedTheme: string | undefined
    caseSensitive: boolean
    reportSearchResults: (
      sessionId: string,
      results: { resultIndex: number; resultCount: number },
    ) => void
    terminalSearchOpen: boolean
  },
): boolean {
  const term = query.trim()
  if (!term || !options.terminalSearchOpen) return false

  const result = navigateCliTerminalSearch(
    terminal,
    searchAddon,
    term,
    getCliTerminalSearchOptions(options.resolvedTheme, options.caseSensitive),
    direction,
  )

  if (result.found && result.matchCount > 0) {
    options.reportSearchResults(sessionId, {
      resultIndex: result.resultIndex,
      resultCount: result.matchCount,
    })
  }

  return result.found
}

export function CliTerminalSession({
  sessionId,
  isVisible,
  isFocused,
  isPanelResizing = false,
  isSplitPane = false,
  deferInit = false,
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
    focusSessionPane,
    reportSearchResults,
    terminalSearchOpen,
    terminalSearchQuery,
    searchSessionIds,
    searchCaseSensitive,
    setTerminalSearchOpen,
  } = useCliShell()

  const { resolvedTheme } = useTheme()
  const resolvedThemeRef = useRef(resolvedTheme)
  resolvedThemeRef.current = resolvedTheme

  const terminalContainerRef = useRef<HTMLDivElement>(null)
  const terminalRef = useRef<Terminal | null>(null)
  const fitAddonRef = useRef<FitAddon | null>(null)
  const searchAddonRef = useRef<SearchAddon | null>(null)
  const isSessionRunningRef = useRef(isSessionRunning)
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
  const isFocusedRef = useRef(isFocused)
  const isVisibleRef = useRef(isVisible)
  const welcomeFitAttemptsRef = useRef(0)
  const welcomeCompleteRef = useRef(false)
  const fullscreenRef = useRef(fullscreen)
  const toggleFullscreenRef = useRef(toggleFullscreen)
  const exitFullscreenRef = useRef(exitFullscreen)
  const getTerminalPromptRef = useRef(getTerminalPrompt)
  const getSuggestionCommandsRef = useRef(getSuggestionCommands)
  const sessionIdRef = useRef(sessionId)
  const persistCommandHistoryRef = useRef(persistCommandHistory)
  const cancelRunningRef = useRef(cancelRunning)
  const getCommandHistoryRef = useRef(getCommandHistory)
  const reportSearchResultsRef = useRef(reportSearchResults)
  const terminalSearchOpenRef = useRef(terminalSearchOpen)
  const setTerminalSearchOpenRef = useRef(setTerminalSearchOpen)
  const searchCaseSensitiveRef = useRef(searchCaseSensitive)
  const prevOpenForFitRef = useRef(false)
  const wasPanelResizingRef = useRef(isPanelResizing)
  const prevIsFocusedRef = useRef(isFocused)

  isSessionRunningRef.current = isSessionRunning
  fullscreenRef.current = fullscreen
  toggleFullscreenRef.current = toggleFullscreen
  exitFullscreenRef.current = exitFullscreen
  getTerminalPromptRef.current = getTerminalPrompt
  getSuggestionCommandsRef.current = getSuggestionCommands
  isPanelResizingRef.current = isPanelResizing
  runCommandRef.current = runCommand
  completeTabRef.current = completeTab
  registerTerminalRef.current = registerTerminal
  unregisterTerminalRef.current = unregisterTerminal
  writeSessionWelcomeRef.current = writeSessionWelcome
  isFocusedRef.current = isFocused
  isVisibleRef.current = isVisible
  sessionIdRef.current = sessionId
  persistCommandHistoryRef.current = persistCommandHistory
  cancelRunningRef.current = cancelRunning
  getCommandHistoryRef.current = getCommandHistory
  reportSearchResultsRef.current = reportSearchResults
  terminalSearchOpenRef.current = terminalSearchOpen
  setTerminalSearchOpenRef.current = setTerminalSearchOpen
  searchCaseSensitiveRef.current = searchCaseSensitive

  const tryWriteSessionWelcome = useCallback(async () => {
    if (!isVisibleRef.current) return

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

    const welcomeWritten = await writeSessionWelcomeRef.current(
      sessionIdRef.current,
    )
    if (!welcomeWritten) {
      return
    }

    welcomeCompleteRef.current = true

    try {
      fitAddon.fit()
    } catch {
      /* container may have zero size during layout */
    }
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
        // Refit once layout settles (e.g. horizontal scrollbar in narrow panes).
        requestAnimationFrame(() => {
          try {
            const next = fitAddon.proposeDimensions()
            if (
              next &&
              next.cols > 0 &&
              next.rows > 0 &&
              (next.cols !== terminal.cols || next.rows !== terminal.rows)
            ) {
              fitAddon.fit()
            }
          } catch {
            /* container may have zero size during layout */
          }
        })
      } catch {
        /* container may have zero size during layout */
      }
    })
  }, [])

  useEffect(() => {
    const container = terminalContainerRef.current
    if (!container) return

    let disposed = false
    let cancelScheduledMount = () => {}
    let terminalCleanup: (() => void) | undefined

    const mountTerminal = async () => {
      const [
        { Terminal },
        { FitAddon },
        { SearchAddon },
      ] = await Promise.all([
        import('@xterm/xterm'),
        import('@xterm/addon-fit'),
        import('@xterm/addon-search'),
      ])
      if (disposed || !terminalContainerRef.current) return

      const terminal = new Terminal({
        cursorBlink: true,
        fontSize: 13,
        lineHeight: 1.4,
        fontFamily:
          'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
        theme: getCliTerminalTheme(resolvedThemeRef.current),
        scrollback: 5000,
        convertEol: true,
        allowTransparency: true,
        scrollSensitivity: 1,
        // Required by @xterm/addon-search match highlighting (registerDecoration).
        allowProposedApi: true,
      })

      const fitAddon = new FitAddon()
      const searchAddon = new SearchAddon()
      const openLink = (event: MouseEvent, uri: string) => {
        window.open(uri, '_blank', 'noopener,noreferrer')
        event.preventDefault()
      }
      const webLinksAddon = createCliTerminalWebLinksAddon(openLink)
      const suggestionLinksController = createCliTerminalSuggestionLinksAddon(
        () => getSuggestionCommandsRef.current(),
        (event, command) => {
          event.preventDefault()
          inputSessionRef.current?.setInput(command)
        },
      )

      terminal.loadAddon(fitAddon)
      terminal.loadAddon(searchAddon)
      terminal.loadAddon(webLinksAddon)
      terminal.loadAddon(suggestionLinksController.addon)
      terminal.attachCustomKeyEventHandler((event) => {
        if (event.type !== 'keydown') return true

        if (event.key === 'Escape') {
          if (terminalSearchOpenRef.current) {
            event.preventDefault()
            setTerminalSearchOpenRef.current(false)
            return false
          }
          if (fullscreenRef.current) {
            event.preventDefault()
            exitFullscreenRef.current()
            return false
          }
        }

        return true
      })
      terminal.open(container)

      if (disposed) {
        terminal.dispose()
        return
      }

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
        focusInputLine: () => {
          terminal.scrollToBottom()
          terminal.focus()
        },
        showInputPrompt: inputSession.showPrompt,
        prepareInputLine: inputSession.prepareInputLine,
        resetForWelcome: () => {
          suggestionLinksController.clearSuggestionCommandLinks()
          inputSession.resetForWelcome()
        },
        markWelcomeComplete: inputSession.markWelcomeComplete,
        clearScreen: inputSession.clearScreen,
        getPrompt: () => getTerminalPromptRef.current(),
        getLastCommand: () => inputSession.getLastCommand(),
        getSelection: () => terminal.getSelection(),
        getBufferText: () => getTerminalBufferText(terminal),
        getBufferCursor: () => {
          const buffer = terminal.buffer.active
          return {
            x: buffer.cursorX + 1,
            y: buffer.baseY + buffer.cursorY + 1,
          }
        },
        registerSuggestionCommandLinks: (links) => {
          suggestionLinksController.setSuggestionCommandLinks(links)
        },
        clearTerminalSearch: () => {
          searchAddon.clearDecorations()
        },
        findNextMatch: (query) =>
          runTerminalSearchNavigation(
            currentSessionId,
            searchAddon,
            terminal,
            query,
            'next',
            {
              resolvedTheme: resolvedThemeRef.current,
              caseSensitive: searchCaseSensitiveRef.current,
              reportSearchResults: reportSearchResultsRef.current,
              terminalSearchOpen: terminalSearchOpenRef.current,
            },
          ),
        findPreviousMatch: (query) =>
          runTerminalSearchNavigation(
            currentSessionId,
            searchAddon,
            terminal,
            query,
            'previous',
            {
              resolvedTheme: resolvedThemeRef.current,
              caseSensitive: searchCaseSensitiveRef.current,
              reportSearchResults: reportSearchResultsRef.current,
              terminalSearchOpen: terminalSearchOpenRef.current,
            },
          ),
        findFirstMatch: (query) =>
          runTerminalSearchNavigation(
            currentSessionId,
            searchAddon,
            terminal,
            query,
            'first',
            {
              resolvedTheme: resolvedThemeRef.current,
              caseSensitive: searchCaseSensitiveRef.current,
              reportSearchResults: reportSearchResultsRef.current,
              terminalSearchOpen: terminalSearchOpenRef.current,
            },
          ),
        findLastMatch: (query) =>
          runTerminalSearchNavigation(
            currentSessionId,
            searchAddon,
            terminal,
            query,
            'last',
            {
              resolvedTheme: resolvedThemeRef.current,
              caseSensitive: searchCaseSensitiveRef.current,
              reportSearchResults: reportSearchResultsRef.current,
              terminalSearchOpen: terminalSearchOpenRef.current,
            },
          ),
      }

      registerTerminalRef.current(currentSessionId, api)

      const resultsDisposable = searchAddon.onDidChangeResults((results) => {
        if (!terminalSearchOpenRef.current) return
        if (results.resultCount <= 0) return
        reportSearchResultsRef.current(currentSessionId, {
          resultIndex: results.resultIndex,
          resultCount: results.resultCount,
        })
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

      terminalCleanup = () => {
        if (fitRafRef.current !== null) {
          cancelAnimationFrame(fitRafRef.current)
          fitRafRef.current = null
        }
        dataDisposable.dispose()
        resultsDisposable.dispose()
        resizeObserver.disconnect()
        unregisterTerminalRef.current(currentSessionId)
        terminal.dispose()
        inputSessionRef.current = null
        terminalRef.current = null
        fitAddonRef.current = null
        searchAddonRef.current = null
        welcomeFitAttemptsRef.current = 0
        welcomeCompleteRef.current = false
      }
    }

    const startMount = () => {
      void mountTerminal()
    }

    if (deferInit) {
      cancelScheduledMount = scheduleCliTerminalMount(startMount)
    } else {
      startMount()
    }

    return () => {
      disposed = true
      cancelScheduledMount()
      terminalCleanup?.()
    }
  }, [deferInit, fitTerminal, sessionId, tryWriteSessionWelcome])

  useEffect(() => {
    const terminal = terminalRef.current
    if (!terminal) return
    terminal.options.theme = getCliTerminalTheme(resolvedTheme)
  }, [resolvedTheme])

  useEffect(() => {
    if (!open || !isVisible) return
    requestAnimationFrame(tryWriteSessionWelcome)
  }, [isVisible, open, tryWriteSessionWelcome])

  useEffect(() => {
    const opening = open && !prevOpenForFitRef.current
    prevOpenForFitRef.current = open
    if (!isVisible || !open || isPanelResizing) return

    const runFit = () => fitTerminal()
    if (opening) {
      const timer = window.setTimeout(runFit, CLI_SHELL_COLLAPSE_MS)
      return () => clearTimeout(timer)
    }
    runFit()
  }, [fitTerminal, isPanelResizing, isVisible, open])

  useEffect(() => {
    const wasResizing = wasPanelResizingRef.current
    wasPanelResizingRef.current = isPanelResizing
    if (!open || !isVisible || isPanelResizing || !wasResizing) return
    fitTerminal()
  }, [fitTerminal, isPanelResizing, isVisible, open])

  useEffect(() => {
    const wasFocused = prevIsFocusedRef.current
    prevIsFocusedRef.current = isFocused
    if (!open || !isFocused || !isVisible || terminalSearchOpen) return
    if (wasFocused || !isFocused) return
    requestAnimationFrame(() => {
      const terminal = terminalRef.current
      if (!terminal) return
      terminal.scrollToBottom()
      terminal.focus()
    })
  }, [isFocused, isVisible, open, terminalSearchOpen])

  useEffect(() => {
    if (!open || !terminalSearchOpen) return

    const query = terminalSearchQuery.trim()
    if (!query || !searchSessionIds.includes(sessionId)) return

    const terminal = terminalRef.current
    const searchAddon = searchAddonRef.current
    if (!terminal || !searchAddon) return

    const result = runCliTerminalSearch(
      terminal,
      searchAddon,
      query,
      getCliTerminalSearchOptions(resolvedTheme, searchCaseSensitive),
    )

    let matchCount = result.matchCount
    let resultIndex = result.resultIndex

    if (matchCount === 0) {
      matchCount = countTextSearchMatches(
        getTerminalBufferText(terminal),
        query,
        searchCaseSensitive,
      )
      if (matchCount > 0 && resultIndex < 0) {
        resultIndex = result.found ? 0 : -1
      }
    }

    if (matchCount > 0 && resultIndex < 0) {
      resultIndex = 0
    }

    reportSearchResults(sessionId, {
      resultIndex,
      resultCount: matchCount,
    })
  }, [
    open,
    reportSearchResults,
    resolvedTheme,
    searchCaseSensitive,
    searchSessionIds,
    sessionId,
    terminalSearchOpen,
    terminalSearchQuery,
  ])

  return (
    <div
      className={cn(
        'relative flex h-full min-h-0 flex-col',
        !isSplitPane && 'pt-2 pb-2',
        !isVisible && 'hidden',
      )}
    >
      <div
        ref={terminalContainerRef}
        className={cn(
          'cli-terminal min-h-0 w-full flex-1 overflow-hidden',
          '[&_.xterm]:h-full [&_.xterm-viewport]:!overflow-y-auto',
        )}
        onPointerDown={() => {
          focusSessionPane(sessionId)
          if (!terminalSearchOpenRef.current) {
            terminalRef.current?.focus()
          }
        }}
      />
    </div>
  )
}
