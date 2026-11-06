import type { Terminal } from '@xterm/xterm'
import { stripAnsi } from './cli-terminal-buffer'
import { CLI_TERMINAL_RESET } from './cli-terminal-api'
import {
  consumeTerminalEscapeSequence,
  findWordBoundaryLeft,
  findWordBoundaryRight,
  resolveTerminalKeyAction,
} from './cli-terminal-keybindings'

type TabCompleteFn = (
  input: string,
  cursor: number,
  listOnly?: boolean,
) => { input: string; cursor: number } | null

const CLEAR_COMMANDS = new Set(['clear', 'cls'])
const INTERRUPT_SEQUENCE = '\x03'

function normalizeSubmittedCommand(raw: string): string {
  return stripAnsi(raw).trim()
}

function isPersistableHistoryCommand(command: string): boolean {
  if (!command) return false
  if (!/[a-zA-Z0-9]/.test(command)) return false
  return /^[\x20-\x7e]+$/.test(command)
}

type CliTerminalInputOptions = {
  terminal: Terminal
  getIsRunning: () => boolean
  getPrompt: () => string
  onRunCommand: (command: string) => void
  onTabComplete: TabCompleteFn
  initialHistory?: string[]
  onHistoryChange?: (history: string[]) => void
  onCancelRunning?: () => void
}

export type CliTerminalInputSession = {
  onData: (data: string) => void
  showPrompt: () => void
  /** Move to a fresh line and show the prompt (used after welcome/bootstrap output). */
  prepareInputLine: (force?: boolean) => void
  /** Reset input state before writing static welcome output. */
  resetForWelcome: () => void
  /** Allow prompt rendering after welcome/bootstrap static output is written. */
  markWelcomeComplete: () => void
  clearScreen: () => void
  /** Prefill the input line without submitting (user presses Enter to run). */
  setInput: (command: string) => void
  getLastCommand: () => string | null
  getHistory: () => string[]
}

export function createCliTerminalInputHandler(
  options: CliTerminalInputOptions,
): CliTerminalInputSession {
  let inputBuffer = ''
  let cursorPos = 0
  const history: string[] = [...(options.initialHistory ?? [])]
  let historyIndex: number | null = null
  let lastTab: { input: string; cursor: number; at: number } | null = null
  let awaitingPrompt = true
  let promptVisible = false
  let welcomeOutputComplete = false
  let lastSubmittedCommand: string | null =
    history.length > 0 ? history[history.length - 1] ?? null : null

  const persistHistory = () => {
    options.onHistoryChange?.([...history])
  }

  const writePrompt = () => {
    if (!welcomeOutputComplete || options.terminal.cols <= 0) return
    options.terminal.write(options.getPrompt())
  }

  const showPrompt = () => {
    if (!welcomeOutputComplete) return
    if (promptVisible && inputBuffer.length === 0 && cursorPos === 0) {
      return
    }
    if (options.terminal.cols <= 0) {
      awaitingPrompt = true
      promptVisible = false
      return
    }
    writePrompt()
    promptVisible = true
    awaitingPrompt = false
  }

  const prepareInputLine = (force = false) => {
    if (!welcomeOutputComplete || options.terminal.cols <= 0) return

    if (
      promptVisible &&
      inputBuffer.length === 0 &&
      cursorPos === 0
    ) {
      if (force) {
        redrawInputLine()
      }
      return
    }

    inputBuffer = ''
    cursorPos = 0
    historyIndex = null
    awaitingPrompt = false

    const buffer = options.terminal.buffer.active
    const line = buffer.getLine(buffer.baseY + buffer.cursorY)
    const lineLength =
      line?.translateToString(false).replace(/\s+$/, '').length ?? 0

    if (lineLength > 0) {
      options.terminal.write('\r\n')
    }

    writePrompt()
    promptVisible = true
    awaitingPrompt = false
  }

  const resetForWelcome = () => {
    welcomeOutputComplete = false
    resetInputState()
    awaitingPrompt = true
    promptVisible = false
    options.terminal.reset()
    options.terminal.clear()
  }

  const markWelcomeComplete = () => {
    welcomeOutputComplete = true
  }

  const markAwaitingPrompt = () => {
    awaitingPrompt = true
    promptVisible = false
  }

  const resetInputState = () => {
    inputBuffer = ''
    cursorPos = 0
    historyIndex = null
    promptVisible = false
  }

  const clearScreen = () => {
    welcomeOutputComplete = true
    resetInputState()
    options.terminal.reset()
    resetInputState()
    showPrompt()
  }

  const redrawInputLine = () => {
    if (!welcomeOutputComplete) return
    options.terminal.write(
      `\r${options.getPrompt()}${inputBuffer}${CLI_TERMINAL_RESET}\x1b[K`,
    )
    const tail = inputBuffer.length - cursorPos
    if (tail > 0) {
      options.terminal.write(`\x1b[${tail}D`)
    }
    awaitingPrompt = false
    promptVisible = true
  }

  const moveCursorTo = (nextPos: number) => {
    const clamped = Math.max(0, Math.min(nextPos, inputBuffer.length))
    if (clamped === cursorPos) return

    const delta = clamped - cursorPos
    if (delta > 0) {
      options.terminal.write(`\x1b[${delta}C`)
    } else {
      options.terminal.write(`\x1b[${-delta}D`)
    }
    cursorPos = clamped
  }

  const moveCursorToStart = () => {
    moveCursorTo(0)
  }

  const moveCursorToEnd = () => {
    moveCursorTo(inputBuffer.length)
  }

  const deleteBackward = () => {
    if (cursorPos <= 0) return

    const deletingAtEnd = cursorPos === inputBuffer.length
    inputBuffer =
      inputBuffer.slice(0, cursorPos - 1) + inputBuffer.slice(cursorPos)
    cursorPos -= 1

    if (deletingAtEnd) {
      options.terminal.write('\b \b')
    } else {
      redrawInputLine()
    }
  }

  const deleteForward = () => {
    if (cursorPos >= inputBuffer.length) return

    const deletingAtEnd = cursorPos === inputBuffer.length - 1
    inputBuffer =
      inputBuffer.slice(0, cursorPos) + inputBuffer.slice(cursorPos + 1)

    if (deletingAtEnd) {
      options.terminal.write('\x1b[K')
    } else {
      redrawInputLine()
    }
  }

  const deleteWordBackward = () => {
    if (cursorPos <= 0) return

    const start = findWordBoundaryLeft(inputBuffer, cursorPos)
    inputBuffer = inputBuffer.slice(0, start) + inputBuffer.slice(cursorPos)
    cursorPos = start
    redrawInputLine()
  }

  const killLineBefore = () => {
    if (cursorPos <= 0) return

    inputBuffer = inputBuffer.slice(cursorPos)
    cursorPos = 0
    redrawInputLine()
  }

  const killLineAfter = () => {
    if (cursorPos >= inputBuffer.length) return

    inputBuffer = inputBuffer.slice(0, cursorPos)
    redrawInputLine()
  }

  const clearLine = () => {
    inputBuffer = ''
    cursorPos = 0
    historyIndex = null
    redrawInputLine()
  }

  const submitCommand = () => {
    const command = normalizeSubmittedCommand(inputBuffer)
    resetInputState()

    if (command && CLEAR_COMMANDS.has(command)) {
      if (
        isPersistableHistoryCommand(command) &&
        history[history.length - 1] !== command
      ) {
        history.push(command)
        persistHistory()
      }
      lastSubmittedCommand = command
      clearScreen()
      return
    }

    options.terminal.writeln('')
    if (command) {
      if (
        isPersistableHistoryCommand(command) &&
        history[history.length - 1] !== command
      ) {
        history.push(command)
        persistHistory()
      }
      lastSubmittedCommand = command
      markAwaitingPrompt()
      void options.onRunCommand(command)
    } else {
      showPrompt()
    }
  }

  const handleTab = () => {
    const now = Date.now()
    const listOnly =
      !!lastTab &&
      lastTab.input === inputBuffer &&
      lastTab.cursor === cursorPos &&
      now - lastTab.at < 400
    lastTab = { input: inputBuffer, cursor: cursorPos, at: now }

    const result = options.onTabComplete(inputBuffer, cursorPos, listOnly)
    if (!result) return

    inputBuffer = result.input
    cursorPos = result.cursor
    redrawInputLine()
  }

  const handleHistoryPrev = () => {
    if (history.length === 0) return

    historyIndex =
      historyIndex === null
        ? history.length - 1
        : Math.max(0, historyIndex - 1)
    inputBuffer = history[historyIndex] ?? ''
    cursorPos = inputBuffer.length
    redrawInputLine()
  }

  const handleHistoryNext = () => {
    if (historyIndex === null) return

    const nextIndex = historyIndex + 1
    if (nextIndex >= history.length) {
      historyIndex = null
      inputBuffer = ''
    } else {
      historyIndex = nextIndex
      inputBuffer = history[nextIndex] ?? ''
    }
    cursorPos = inputBuffer.length
    redrawInputLine()
  }

  const insertText = (text: string) => {
    inputBuffer =
      inputBuffer.slice(0, cursorPos) + text + inputBuffer.slice(cursorPos)
    cursorPos += text.length
    options.terminal.write(text)
  }

  const applyKeyAction = (
    action: ReturnType<typeof resolveTerminalKeyAction>,
  ) => {
    if (!action) return

    switch (action.type) {
      case 'cursor-start':
        moveCursorToStart()
        return
      case 'cursor-end':
        moveCursorToEnd()
        return
      case 'cursor-left':
        moveCursorTo(cursorPos - 1)
        return
      case 'cursor-right':
        moveCursorTo(cursorPos + 1)
        return
      case 'cursor-word-left':
        moveCursorTo(findWordBoundaryLeft(inputBuffer, cursorPos))
        return
      case 'cursor-word-right':
        moveCursorTo(findWordBoundaryRight(inputBuffer, cursorPos))
        return
      case 'history-prev':
        handleHistoryPrev()
        return
      case 'history-next':
        handleHistoryNext()
        return
      case 'delete-backward':
        deleteBackward()
        return
      case 'delete-forward':
        deleteForward()
        return
      case 'delete-word-backward':
        deleteWordBackward()
        return
      case 'kill-line-before':
        killLineBefore()
        return
      case 'kill-line-after':
        killLineAfter()
        return
      case 'clear-line':
        clearLine()
        return
      case 'clear-screen':
        clearScreen()
        return
      case 'submit':
        submitCommand()
        return
      case 'tab':
        handleTab()
        return
      case 'insert':
        insertText(action.text)
        return
    }
  }

  const handleInterrupt = () => {
    if (options.getIsRunning()) {
      options.onCancelRunning?.()
      return
    }
    applyKeyAction({ type: 'clear-line' })
  }

  const handleLocalData = (data: string) => {
    if (data === INTERRUPT_SEQUENCE) {
      handleInterrupt()
      return
    }

    const action = resolveTerminalKeyAction(data)
    if (action) {
      if (action.type === 'submit' && data === '\n' && inputBuffer === '') {
        return
      }
      applyKeyAction(action)
      return
    }

    if (data.startsWith('\x1b')) {
      return
    }
  }

  const isSubmitKey = (char: string) => char === '\r' || char === '\n'

  const ensurePromptBeforeInput = (char: string) => {
    if (awaitingPrompt && !isSubmitKey(char)) {
      showPrompt()
    }
  }

  const onData = (data: string) => {
    if (data === INTERRUPT_SEQUENCE || data.includes(INTERRUPT_SEQUENCE)) {
      handleInterrupt()
      return
    }

    if (options.getIsRunning()) return

    if (data.startsWith('\x1b') || data.length === 1) {
      ensurePromptBeforeInput(data)
      handleLocalData(data)
      return
    }

    ensurePromptBeforeInput(data[0] ?? '')
    const action = resolveTerminalKeyAction(data)
    if (action?.type === 'insert' && data.length > 1) {
      insertText(data)
      return
    }

    if (action) {
      applyKeyAction(action)
      return
    }

    let index = 0
    while (index < data.length) {
      if (data[index] === '\x1b') {
        const nextIndex = consumeTerminalEscapeSequence(data, index)
        if (nextIndex <= index + 1) {
          index += 1
          continue
        }
        const sequence = data.slice(index, nextIndex)
        applyKeyAction(resolveTerminalKeyAction(sequence))
        index = nextIndex
        continue
      }

      const char = data[index]!
      ensurePromptBeforeInput(char)
      applyKeyAction(resolveTerminalKeyAction(char))
      index += 1
    }
  }

  const setInput = (command: string) => {
    if (options.getIsRunning()) return

    inputBuffer = command
    cursorPos = command.length
    historyIndex = null
    awaitingPrompt = false
    redrawInputLine()
    options.terminal.focus()
  }

  return {
    onData,
    showPrompt,
    prepareInputLine,
    resetForWelcome,
    markWelcomeComplete,
    clearScreen,
    setInput,
    getLastCommand: () => lastSubmittedCommand,
    getHistory: () => [...history],
  }
}
