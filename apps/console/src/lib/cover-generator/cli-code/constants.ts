import { COVER_SCREENSHOT_FRAME_WIDTH } from '@/lib/cover-generator/cover-frame-width'
import { clampCoverFrameWidthPercent } from '@/lib/cover-generator/cover-frame-width'
import type { CoverRenderData } from '@/lib/cover-generator/types'

export const COVER_CLI_CODE_DEFAULT_FRAME_WIDTH_PERCENT = 58

export const COVER_CLI_CODE = {
  maxLines: 8,
  codeFontSize: 16,
  codePaddingX: 20,
  codePaddingY: 12,
  promptWidth: 20,
  maxCharsPerLine: 44,
  terminalHeaderHeight: 34,
  terminalTitleFontSize: 13,
  terminalIconSize: 16,
  terminalIconGap: 8,
  terminalDotSize: 10,
  terminalDotGap: 6,
  terminalDotMarginLeft: 14,
} as const

export const DEFAULT_CLI_CODE = `appwrite login
appwrite init project
appwrite deploy function`

export const DEFAULT_CLI_CODE_TITLE = 'Deploy from the CLI'
export const DEFAULT_CLI_CODE_SUBTITLE = 'Ship functions and sites from your terminal'
export const DEFAULT_CLI_TERMINAL_TITLE = 'Appwrite CLI'
export const DEFAULT_CLI_TERMINAL_ICON = 'lucide:terminal'

export function parseCoverCliCodeLines(code: string | undefined): string[] {
  const rawLines = (code ?? '')
    .split(/\r?\n/)
    .map((line) => line.trimEnd())
    .filter((line, index, lines) => {
      if (line.length > 0) return true
      return lines.slice(index + 1).some((next) => next.length > 0)
    })

  return rawLines.slice(0, COVER_CLI_CODE.maxLines).map((line) => {
    const trimmed = line.trim()
    if (trimmed.length <= COVER_CLI_CODE.maxCharsPerLine) return trimmed
    return `${trimmed.slice(0, COVER_CLI_CODE.maxCharsPerLine - 1)}…`
  })
}

export function normalizeCoverCliCodeData(
  data: CoverRenderData,
): Extract<CoverRenderData, { template: 'cli-code' }> {
  const cliData = data.template === 'cli-code' ? data : null

  return {
    template: 'cli-code',
    theme: data.theme,
    format: data.format,
    width: data.width,
    height: data.height,
    title: cliData?.title?.trim() || undefined,
    subtitle: cliData?.subtitle?.trim() || undefined,
    code: parseCoverCliCodeLines(cliData?.code).join('\n') || DEFAULT_CLI_CODE,
    showPrompt: cliData?.showPrompt == null ? true : Boolean(cliData.showPrompt),
    terminalTitle: cliData?.terminalTitle?.trim() || DEFAULT_CLI_TERMINAL_TITLE,
    terminalIcon: cliData?.terminalIcon?.trim() || DEFAULT_CLI_TERMINAL_ICON,
    frameWidthPercent: clampCoverFrameWidthPercent(
      cliData && Number.isFinite(cliData.frameWidthPercent)
        ? cliData.frameWidthPercent
        : COVER_CLI_CODE_DEFAULT_FRAME_WIDTH_PERCENT,
      COVER_SCREENSHOT_FRAME_WIDTH,
    ),
  }
}
