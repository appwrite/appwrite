import type { Terminal } from '@xterm/xterm'
import { CLI_TERMINAL_PROMPT, CLI_TERMINAL_RESET } from './cli-terminal-api'

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

  const handleLocalData = (data: string) => {
    if (data === '\r' || data === '\r\n') {
      submitCommand()
      return
    }

    // xterm often sends \n right after \r; ignore the trailing newline.
    if (data === '\n') {
      if (inputBuffer === '') return
      submitCommand()
      return
    }

    if (data === '\u007f' || data === '\b') {
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
      return
    }

    if (data === '\t') {
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
      return
    }

    if (data === '\x1b[A') {
      if (history.length === 0) return
      historyIndex =
        historyIndex === null
          ? history.length - 1
          : Math.max(0, historyIndex - 1)
      inputBuffer = history[historyIndex] ?? ''
      cursorPos = inputBuffer.length
      redrawInputLine()
      return
    }

    if (data === '\x1b[B') {
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
      return
    }

    if (data === '\x1b[C' && cursorPos < inputBuffer.length) {
      cursorPos += 1
      options.terminal.write(data)
      return
    }

    if (data === '\x1b[D' && cursorPos > 0) {
      cursorPos -= 1
      options.terminal.write(data)
      return
    }

    if (data === '\x03') {
      inputBuffer = ''
      cursorPos = 0
      redrawInputLine()
      return
    }

    if (data < ' ' && data !== '\t') return

    inputBuffer =
      inputBuffer.slice(0, cursorPos) + data + inputBuffer.slice(cursorPos)
    cursorPos += data.length
    options.terminal.write(data)
  }

  const isSubmitKey = (char: string) => char === '\r' || char === '\n'

  const ensurePromptBeforeInput = (char: string) => {
    if (awaitingPrompt && !isSubmitKey(char)) {
      showPrompt()
    }
  }

  const onData = (data: string) => {
    if (options.getIsRunning()) return

    // Escape sequences (arrows, etc.) must stay atomic.
    if (data.startsWith('\x1b') || data.length === 1) {
      ensurePromptBeforeInput(data)
      handleLocalData(data)
      return
    }

    // Paste or batched input (e.g. "clear\r") — process per character.
    for (const char of data) {
      ensurePromptBeforeInput(char)
      handleLocalData(char)
    }
  }

  return { onData, showPrompt, clearScreen }
}
