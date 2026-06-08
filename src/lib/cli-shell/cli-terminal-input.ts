import type { Terminal } from '@xterm/xterm'
import { CLI_TERMINAL_PROMPT, CLI_TERMINAL_RESET } from './cli-terminal-api'
import {
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

type CliTerminalInputOptions = {
  terminal: Terminal
  getIsRunning: () => boolean
  onRunCommand: (command: string) => void
  onTabComplete: TabCompleteFn
}

export type CliTerminalInputSession = {
  onData: (data: string) => void
  showPrompt: () => void
  clearScreen: () => void
  /** Prefill the input line without submitting (user presses Enter to run). */
  setInput: (command: string) => void
}

export function createCliTerminalInputHandler(
  options: CliTerminalInputOptions,
): CliTerminalInputSession {
  let inputBuffer = ''
  let cursorPos = 0
  const history: string[] = []
  let historyIndex: number | null = null
  let lastTab: { input: string; cursor: number; at: number } | null = null
  let awaitingPrompt = true

  const showPrompt = () => {
    options.terminal.write(CLI_TERMINAL_PROMPT)
    awaitingPrompt = false
  }

  const markAwaitingPrompt = () => {
    awaitingPrompt = true
  }

  const resetInputState = () => {
    inputBuffer = ''
    cursorPos = 0
    historyIndex = null
  }

  const clearScreen = () => {
    resetInputState()
    options.terminal.reset()
    showPrompt()
  }

  const redrawInputLine = () => {
    // Overwrite in place so the cursor never sits on a blank line before the prompt.
    options.terminal.write(
      `\r${CLI_TERMINAL_PROMPT}${inputBuffer}${CLI_TERMINAL_RESET}\x1b[K`,
    )
    const tail = inputBuffer.length - cursorPos
    if (tail > 0) {
      options.terminal.write(`\x1b[${tail}D`)
    }
    awaitingPrompt = false
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
    const command = inputBuffer.trim()
    resetInputState()

    if (command && CLEAR_COMMANDS.has(command)) {
      clearScreen()
      return
    }

    options.terminal.writeln('')
    if (command) {
      if (history[history.length - 1] !== command) {
        history.push(command)
      }
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

  const applyKeyAction = (action: ReturnType<typeof resolveTerminalKeyAction>) => {
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

  const handleLocalData = (data: string) => {
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
    if (options.getIsRunning()) return

    // Escape sequences (arrows, home/end, etc.) must stay atomic.
    if (data.startsWith('\x1b') || data.length === 1) {
      ensurePromptBeforeInput(data)
      handleLocalData(data)
      return
    }

    // Paste or batched input — try whole chunk first, then per-sequence tokens.
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
        let end = index + 1
        while (end < data.length && end - index < 8 && data[end]! >= '\x40') {
          end += 1
        }
        if (end - index < 2) {
          index += 1
          continue
        }
        const sequence = data.slice(index, end)
        applyKeyAction(resolveTerminalKeyAction(sequence))
        index = end
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

  return { onData, showPrompt, clearScreen, setInput }
}
