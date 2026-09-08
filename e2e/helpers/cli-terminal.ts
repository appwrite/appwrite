import { expect, type Page } from '@playwright/test'
import { clickInPage } from './ui'

const SHELL = '[data-cli-shell]'
const TERMINAL_READY_MS = 60_000

function terminalRows(page: Page) {
  return page.locator(`${SHELL} .xterm-rows`)
}

function terminalTextarea(page: Page) {
  return page.locator(`${SHELL} .xterm-helper-textarea`)
}

const PROMPT_LINE = /[a-z0-9._-]+@[a-z0-9._-]+\$$/

function lastNonEmptyLine(text: string): string {
  const lines = text
    .split('\n')
    .map((line) => line.replace(/\s+$/g, ''))
    .filter(Boolean)
  return lines[lines.length - 1] ?? ''
}

/** Expand the project CLI panel if it is collapsed, then wait for xterm. */
export async function openProjectTerminal(page: Page): Promise<void> {
  const shell = page.locator(SHELL)
  await expect(shell).toBeAttached({ timeout: 20_000 })

  const openButton = page.getByRole('button', {
    name: 'Open terminal',
    exact: true,
  })
  if (await openButton.isVisible().catch(() => false)) {
    await clickInPage(openButton)
  }

  await expect(page.locator(`${SHELL} .xterm`)).toBeVisible({
    timeout: 15_000,
  })

  const fullscreenButton = page.getByRole('button', {
    name: 'Full screen',
    exact: true,
  })
  if (await fullscreenButton.isVisible().catch(() => false)) {
    await clickInPage(fullscreenButton)
  }

  await expect(terminalRows(page)).toContainText('Appwrite CLI', {
    timeout: 20_000,
  })
  await waitForTerminalPrompt(page, 20_000)
}

export async function waitForTerminalPrompt(
  page: Page,
  timeout = TERMINAL_READY_MS,
): Promise<void> {
  await expect
    .poll(
      async () => lastNonEmptyLine(await terminalRows(page).innerText()),
      { timeout },
    )
    .toMatch(PROMPT_LINE)
}

export async function expectTerminalText(
  page: Page,
  text: string | RegExp,
  timeout = TERMINAL_READY_MS,
): Promise<void> {
  await expect(terminalRows(page)).toContainText(text, { timeout })
}

/** Type a command into xterm and submit it. Call only while the prompt is idle. */
export async function runTerminalCommand(
  page: Page,
  command: string,
): Promise<void> {
  const textarea = terminalTextarea(page)
  // xterm positions this hidden input at the cursor; focus it directly rather
  // than force-clicking through terminal output or overlapping controls.
  await textarea.focus()
  await expect(textarea).toBeFocused()
  // Paste the whole command. Typing character-by-character wraps at the
  // panel width and xterm treats that wrap as Enter, splitting the command.
  await page.keyboard.insertText(command)
  await page.keyboard.press('Enter')
}

export async function runTerminalCommandAndWait(
  page: Page,
  command: string,
  expected: string | RegExp,
  timeout = TERMINAL_READY_MS,
): Promise<void> {
  await waitForTerminalPrompt(page, timeout)
  await runTerminalCommand(page, command)
  await expectTerminalText(page, expected, timeout)
  await waitForTerminalPrompt(page, timeout)
}
