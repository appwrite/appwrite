import { COVER_SCREENSHOT_FRAME_WIDTH } from '@/lib/cover-generator/cover-frame-width'
import { clampCoverFrameWidthPercent } from '@/lib/cover-generator/cover-frame-width'
import { getCoverCodeLineIndentPx } from '@/lib/cover-generator/code-share/svg-tokens'
import type { CodeEditorLanguage } from '@/components/global/shared/CodeEditor'
import type { CoverRenderData } from '@/lib/cover-generator/types'

export const COVER_CODE_SNIPPET_DEFAULT_FRAME_WIDTH_PERCENT = 72

export const COVER_CODE_SNIPPET_FONT_SIZE = {
  min: 11,
  max: 22,
  default: 15,
  step: 1,
} as const

export const COVER_CODE_SNIPPET_LANGUAGES = [
  'javascript',
  'typescript',
  'python',
  'php',
  'ruby',
  'go',
  'java',
  'kotlin',
  'swift',
  'dart',
  'csharp',
  'rust',
  'bash',
  'json',
  'yaml',
  'graphql',
  'css',
  'markup',
  'plaintext',
] as const

export type CoverCodeSnippetLanguage = (typeof COVER_CODE_SNIPPET_LANGUAGES)[number]

export const COVER_CODE_SNIPPET_LANGUAGE_LABELS: Record<CoverCodeSnippetLanguage, string> = {
  javascript: 'JavaScript',
  typescript: 'TypeScript',
  python: 'Python',
  php: 'PHP',
  ruby: 'Ruby',
  go: 'Go',
  java: 'Java',
  kotlin: 'Kotlin',
  swift: 'Swift',
  dart: 'Dart',
  csharp: 'C#',
  rust: 'Rust',
  bash: 'Bash',
  json: 'JSON',
  yaml: 'YAML',
  graphql: 'GraphQL',
  css: 'CSS',
  markup: 'Markup',
  plaintext: 'Plain text',
}

export const COVER_CODE_SNIPPET = {
  /** Fallback when canvas height is unknown; render uses dynamic max from canvas size. */
  maxLines: 12,
  /** Vertical margin so the card does not touch the canvas edge when centered. */
  compositionVerticalMargin: 24,
  codeFontSize: COVER_CODE_SNIPPET_FONT_SIZE.default,
  /** Line box height as a multiple of code font size. */
  codeLineHeightRatio: 1.5,
  /** Gap between the glass shell and the inner code background. */
  shellInsetX: 20,
  /** Abstract IDE activity bar on the left inside the card. */
  activityBarWidth: 52,
  activityBarIconSize: 18,
  activityBarIconGap: 16,
  activityBarPaddingTop: 18,
  /** Min code column height so activity bar icons are not clipped. */
  minCodeContentHeight: 184,
  /** Padding inside the inner code background around the text. */
  codePaddingX: 16,
  codePaddingY: 16,
  shellPaddingBottom: 20,
  /** Monospace advance width estimate for indent offset (× font size). */
  monoCharWidthRatio: 0.6,
  tabWidthSpaces: 2,
  maxTitleChars: 52,
  titleFontSize: 42,
  titleLineHeight: 50,
  titleCardGap: 28,
  headerHeight: 32,
  headerFontSize: 12,
  outerRadius: 24,
  innerRadius: 14,
} as const

export const DEFAULT_CODE_SNIPPET_TITLE = 'List databases'
export const DEFAULT_CODE_SNIPPET_LANGUAGE: CoverCodeSnippetLanguage = 'typescript'
export const DEFAULT_CODE_SNIPPET = `import { Client, Databases } from 'appwrite'

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')

const databases = new Databases(client)
await databases.list()`

export function isCoverCodeSnippetLanguage(
  value: string,
): value is CoverCodeSnippetLanguage {
  return (COVER_CODE_SNIPPET_LANGUAGES as readonly string[]).includes(value)
}

export function parseCoverCodeSnippetLanguage(
  value: string | null | undefined,
): CoverCodeSnippetLanguage {
  const normalized = value?.trim().toLowerCase()
  if (normalized && isCoverCodeSnippetLanguage(normalized)) {
    return normalized
  }
  return DEFAULT_CODE_SNIPPET_LANGUAGE
}

/** Maps cover snippet languages to Monaco editor language ids. */
export function mapCoverCodeSnippetLanguageToCodeEditorLanguage(
  language: CoverCodeSnippetLanguage | string | null | undefined,
): CodeEditorLanguage {
  const parsed = parseCoverCodeSnippetLanguage(
    typeof language === 'string' ? language : language ?? undefined,
  )

  const languageMap: Record<CoverCodeSnippetLanguage, CodeEditorLanguage> = {
    javascript: 'javascript',
    typescript: 'typescript',
    python: 'python',
    php: 'php',
    ruby: 'ruby',
    go: 'go',
    java: 'java',
    kotlin: 'kotlin',
    swift: 'swift',
    dart: 'dart',
    csharp: 'csharp',
    rust: 'rust',
    bash: 'shell',
    json: 'json',
    yaml: 'yaml',
    graphql: 'graphql',
    css: 'css',
    markup: 'html',
    plaintext: 'plaintext',
  }

  return languageMap[parsed]
}

export function clampCoverCodeSnippetFontSize(value: number): number {
  const snapped = Math.round(value)
  return Math.min(
    COVER_CODE_SNIPPET_FONT_SIZE.max,
    Math.max(COVER_CODE_SNIPPET_FONT_SIZE.min, snapped),
  )
}

export function getCoverCodeSnippetLineHeight(codeFontSize: number): number {
  return codeFontSize * COVER_CODE_SNIPPET.codeLineHeightRatio
}

export function getCoverCodeSnippetMaxCharsForLine(
  line: string,
  codeColumnWidthPx: number,
  codeFontSize: number,
): number {
  const charWidth = codeFontSize * COVER_CODE_SNIPPET.monoCharWidthRatio
  if (charWidth <= 0) return 1

  const textAreaWidth =
    codeColumnWidthPx - COVER_CODE_SNIPPET.codePaddingX * 2
  const indentPx = getCoverCodeLineIndentPx(line, codeFontSize, {
    tabWidthSpaces: COVER_CODE_SNIPPET.tabWidthSpaces,
    charWidthRatio: COVER_CODE_SNIPPET.monoCharWidthRatio,
  })

  return Math.max(1, Math.floor((textAreaWidth - indentPx) / charWidth))
}

export function getCoverCodeSnippetMaxLines(
  canvasHeight: number,
  hasTitle = true,
  codeFontSize = COVER_CODE_SNIPPET_FONT_SIZE.default,
): number {
  const titleHeight = hasTitle ? COVER_CODE_SNIPPET.titleLineHeight : 0
  const titleCardGap = hasTitle ? COVER_CODE_SNIPPET.titleCardGap : 0
  const fixedCardHeight =
    COVER_CODE_SNIPPET.headerHeight +
    COVER_CODE_SNIPPET.shellPaddingBottom +
    COVER_CODE_SNIPPET.codePaddingY * 2
  const available =
    canvasHeight -
    titleHeight -
    titleCardGap -
    fixedCardHeight -
    COVER_CODE_SNIPPET.compositionVerticalMargin

  const codeLineHeight = getCoverCodeSnippetLineHeight(codeFontSize)

  return Math.max(1, Math.floor(available / codeLineHeight))
}

export function parseCoverCodeSnippetLines(
  code: string | undefined,
  maxLines = COVER_CODE_SNIPPET.maxLines,
  maxCharsPerLine: number | ((line: string) => number) = 120,
): string[] {
  const rawLines = (code ?? '')
    .split(/\r?\n/)
    .map((line) => line.trimEnd())
    .filter((line, index, lines) => {
      if (line.length > 0) return true
      return lines.slice(index + 1).some((next) => next.length > 0)
    })

  return rawLines.slice(0, maxLines).map((line) => {
    const limit =
      typeof maxCharsPerLine === 'function' ? maxCharsPerLine(line) : maxCharsPerLine
    if (line.length <= limit) return line
    return `${line.slice(0, limit - 1)}…`
  })
}

export function normalizeCoverCodeSnippetData(
  data: CoverRenderData,
): Extract<CoverRenderData, { template: 'code-snippet' }> {
  const snippetData = data.template === 'code-snippet' ? data : null

  return {
    template: 'code-snippet',
    theme: data.theme,
    format: data.format,
    width: data.width,
    height: data.height,
    title: snippetData?.title?.trim() || DEFAULT_CODE_SNIPPET_TITLE,
    code: snippetData?.code?.trim() ? snippetData.code : DEFAULT_CODE_SNIPPET,
    language: parseCoverCodeSnippetLanguage(snippetData?.language),
    codeFontSize: clampCoverCodeSnippetFontSize(
      snippetData && Number.isFinite(snippetData.codeFontSize)
        ? snippetData.codeFontSize
        : COVER_CODE_SNIPPET_FONT_SIZE.default,
    ),
    frameWidthPercent: clampCoverFrameWidthPercent(
      snippetData && Number.isFinite(snippetData.frameWidthPercent)
        ? snippetData.frameWidthPercent
        : COVER_CODE_SNIPPET_DEFAULT_FRAME_WIDTH_PERCENT,
      COVER_SCREENSHOT_FRAME_WIDTH,
    ),
  }
}
