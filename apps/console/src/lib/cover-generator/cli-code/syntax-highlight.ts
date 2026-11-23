import {
  getCodeSyntaxColors,
  type CodeSyntaxColors,
} from '@/lib/code-syntax-theme'
import type { CoverCodeToken } from '@/lib/cover-generator/code-share/svg-tokens'
import { buildCoverCodeTokenTexts } from '@/lib/cover-generator/code-share/svg-tokens'
import type { CoverThemeFamily } from '@/lib/cover-generator/themes'

export type { CoverCodeToken as CoverCliCodeToken } from '@/lib/cover-generator/code-share/svg-tokens'


const CLI_TOKEN_PATTERN = /("[^"]*"|'[^']*'|--?[\w-]+|\S+)/g

function resolveCliTokenColor(
  token: string,
  index: number,
  colors: CodeSyntaxColors,
  defaultColor: string,
): string {
  if (token.startsWith('"') || token.startsWith("'")) return colors.string
  if (token.startsWith('-')) return colors.keyword
  if (index === 0) return colors.function
  if (index === 1) return colors.keyword
  if (index === 2) return colors.moduleKeyword
  return colors.string
}

export function tokenizeCoverCliCodeLine(
  line: string,
  themeFamily: CoverThemeFamily,
  defaultColor: string,
): CoverCodeToken[] {
  const trimmed = line.trim()
  if (!trimmed) {
    return [{ content: ' ', color: defaultColor }]
  }

  const colors = getCodeSyntaxColors(themeFamily === 'dark')
  if (trimmed.startsWith('#')) {
    return [{ content: trimmed, color: colors.comment }]
  }

  const tokens: CoverCodeToken[] = []
  let lastIndex = 0
  let tokenIndex = 0
  let match: RegExpExecArray | null

  CLI_TOKEN_PATTERN.lastIndex = 0
  while ((match = CLI_TOKEN_PATTERN.exec(trimmed)) !== null) {
    if (match.index > lastIndex) {
      tokens.push({
        content: trimmed.slice(lastIndex, match.index),
        color: defaultColor,
      })
    }

    const part = match[0]
    tokens.push({
      content: part,
      color: resolveCliTokenColor(part, tokenIndex, colors, defaultColor),
    })

    lastIndex = CLI_TOKEN_PATTERN.lastIndex
    tokenIndex += 1
  }

  if (lastIndex < trimmed.length) {
    tokens.push({
      content: trimmed.slice(lastIndex),
      color: defaultColor,
    })
  }

  return tokens.length > 0 ? tokens : [{ content: trimmed, color: defaultColor }]
}

export function buildCoverCliCodeTokenTspans(
  tokens: CoverCodeToken[],
  startX: number,
  fontSize: number,
  y: number,
): string {
  return buildCoverCodeTokenTexts(tokens, startX, fontSize, y)
}
