import {
  getCodeSyntaxColors,
  type CodeSyntaxColors,
} from '@/lib/code-syntax-theme'
import type { CoverCodeToken } from '@/lib/cover-generator/code-share/svg-tokens'
import {
  ensureCoverCodeSnippetPrismGrammars,
  getCoverCodeSnippetPrismGrammar,
  getCoverPrism,
} from '@/lib/cover-generator/code-snippet/prism-setup'
import type { Token } from 'prismjs'
import type { CoverCodeSnippetLanguage } from '@/lib/cover-generator/code-snippet/constants'
import type { CoverThemeFamily } from '@/lib/cover-generator/themes'

function resolvePrismTokenColor(
  types: string[],
  colors: CodeSyntaxColors,
  defaultColor: string,
): string {
  const joined = types.join(' ')

  if (
    joined.includes('comment') ||
    joined.includes('prolog') ||
    joined.includes('doctype') ||
    joined.includes('cdata')
  ) {
    return colors.comment
  }

  if (joined.includes('namespace')) return colors.moduleKeyword

  if (
    joined.includes('keyword') ||
    joined.includes('builtin') ||
    joined.includes('changed') ||
    joined.includes('interpolation-punctuation') ||
    joined.includes('selector') ||
    joined.includes('tag') ||
    joined.includes('delimiter')
  ) {
    return colors.keyword
  }

  if (
    joined.includes('function') ||
    joined.includes('method') ||
    joined.includes('macro')
  ) {
    return colors.function
  }

  if (
    joined.includes('class-name') ||
    joined.includes('return-type') ||
    joined.includes('type')
  ) {
    return colors.moduleKeyword
  }

  if (
    joined.includes('variable') ||
    joined.includes('attr-name') ||
    joined.includes('property') ||
    joined.includes('key')
  ) {
    return colors.property
  }

  if (
    joined.includes('string') ||
    joined.includes('char') ||
    joined.includes('attr-value') ||
    joined.includes('template-punctuation') ||
    joined.includes('regexp') ||
    joined.includes('number') ||
    joined.includes('inserted') ||
    joined.includes('constant')
  ) {
    return colors.string
  }

  if (joined.includes('operator') || joined.includes('punctuation')) {
    return defaultColor
  }

  return defaultColor
}

function flattenPrismTokens(
  tokens: Array<string | Token>,
  colors: CodeSyntaxColors,
  defaultColor: string,
  parentTypes: string[] = [],
  parentColor?: string,
): CoverCodeToken[] {
  const result: CoverCodeToken[] = []

  for (const token of tokens) {
    if (typeof token === 'string') {
      if (!token) continue
      result.push({ content: token, color: parentColor ?? defaultColor })
      continue
    }

    const alias = token.alias
      ? Array.isArray(token.alias)
        ? token.alias
        : [token.alias]
      : []
    const types = [...parentTypes, token.type, ...alias.map(String)]
    const color = resolvePrismTokenColor(types, colors, defaultColor)

    if (typeof token.content === 'string') {
      if (!token.content) continue
      result.push({ content: token.content, color })
      continue
    }

    if (Array.isArray(token.content)) {
      result.push(
        ...flattenPrismTokens(token.content, colors, defaultColor, types, color),
      )
      continue
    }

    result.push(
      ...flattenPrismTokens(
        [token.content],
        colors,
        defaultColor,
        types,
        color,
      ),
    )
  }

  return result
}

function splitCoverCodeTokensIntoLines(
  tokens: CoverCodeToken[],
  defaultColor: string,
): CoverCodeToken[][] {
  const lines: CoverCodeToken[][] = []
  let currentLine: CoverCodeToken[] = []

  const pushLine = () => {
    if (currentLine.length === 0) {
      lines.push([{ content: ' ', color: defaultColor }])
    } else {
      lines.push(currentLine)
    }
    currentLine = []
  }

  for (const token of tokens) {
    let remaining = token.content
    while (remaining.length > 0) {
      const newlineIndex = remaining.indexOf('\n')
      if (newlineIndex === -1) {
        if (remaining.length > 0) {
          currentLine.push({ content: remaining, color: token.color })
        }
        break
      }

      const before = remaining.slice(0, newlineIndex)
      if (before.length > 0) {
        currentLine.push({ content: before, color: token.color })
      }
      pushLine()
      remaining = remaining.slice(newlineIndex + 1)
    }
  }

  if (currentLine.length > 0) {
    lines.push(currentLine)
  }

  return lines.length > 0 ? lines : [[{ content: ' ', color: defaultColor }]]
}

const COVER_SNIPPET_HEURISTIC_KEYWORDS =
  /\b(import|export|from|const|let|var|new|await|async|function|return|if|else|class|interface|type|extends|implements|public|private|protected|readonly|enum|namespace|declare|default|void|typeof|instanceof|in|of|try|catch|finally|throw|switch|case|break|continue|do|while|for|yield|super|this|def|fn|func|package|echo|print|println|using|struct|mut|impl|trait|lambda|yield|raise|except|elif|pass|nil|true|false|null|undefined)\b/yi

const COVER_SNIPPET_HEURISTIC_STRING =
  /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`/y

const COVER_SNIPPET_HEURISTIC_DELIMITER = /<\?php|<\?=|\?>|<\/?[a-zA-Z][^>]*>/y

const COVER_SNIPPET_HEURISTIC_COMMENT = /\/\/.*|#.*$/y

const COVER_SNIPPET_HEURISTIC_CLASS = /\b[A-Z][a-zA-Z0-9_]*\b/y

const COVER_SNIPPET_HEURISTIC_METHOD = /\.[a-zA-Z_][\w]*/y

const COVER_SNIPPET_HEURISTIC_NUMBER = /\b\d+(?:\.\d+)?\b/y

function tokenizeCoverCodeSnippetHeuristicLine(
  line: string,
  colors: CodeSyntaxColors,
  defaultColor: string,
): CoverCodeToken[] {
  const tokens: CoverCodeToken[] = []
  let index = 0

  while (index < line.length) {
    const rest = line.slice(index)

    COVER_SNIPPET_HEURISTIC_STRING.lastIndex = 0
    const stringMatch = COVER_SNIPPET_HEURISTIC_STRING.exec(rest)
    if (stringMatch && stringMatch.index === 0) {
      tokens.push({ content: stringMatch[0], color: colors.string })
      index += stringMatch[0].length
      continue
    }

    COVER_SNIPPET_HEURISTIC_COMMENT.lastIndex = 0
    const commentMatch = COVER_SNIPPET_HEURISTIC_COMMENT.exec(rest)
    if (commentMatch && commentMatch.index === 0) {
      tokens.push({ content: commentMatch[0], color: colors.comment })
      index += commentMatch[0].length
      continue
    }

    COVER_SNIPPET_HEURISTIC_DELIMITER.lastIndex = 0
    const delimiterMatch = COVER_SNIPPET_HEURISTIC_DELIMITER.exec(rest)
    if (delimiterMatch && delimiterMatch.index === 0) {
      tokens.push({ content: delimiterMatch[0], color: colors.keyword })
      index += delimiterMatch[0].length
      continue
    }

    COVER_SNIPPET_HEURISTIC_KEYWORDS.lastIndex = 0
    const keywordMatch = COVER_SNIPPET_HEURISTIC_KEYWORDS.exec(rest)
    if (keywordMatch && keywordMatch.index === 0) {
      tokens.push({ content: keywordMatch[0], color: colors.keyword })
      index += keywordMatch[0].length
      continue
    }

    COVER_SNIPPET_HEURISTIC_CLASS.lastIndex = 0
    const classMatch = COVER_SNIPPET_HEURISTIC_CLASS.exec(rest)
    if (classMatch && classMatch.index === 0) {
      tokens.push({ content: classMatch[0], color: colors.moduleKeyword })
      index += classMatch[0].length
      continue
    }

    COVER_SNIPPET_HEURISTIC_METHOD.lastIndex = 0
    const methodMatch = COVER_SNIPPET_HEURISTIC_METHOD.exec(rest)
    if (methodMatch && methodMatch.index === 0) {
      tokens.push({ content: methodMatch[0], color: colors.function })
      index += methodMatch[0].length
      continue
    }

    COVER_SNIPPET_HEURISTIC_NUMBER.lastIndex = 0
    const numberMatch = COVER_SNIPPET_HEURISTIC_NUMBER.exec(rest)
    if (numberMatch && numberMatch.index === 0) {
      tokens.push({ content: numberMatch[0], color: colors.string })
      index += numberMatch[0].length
      continue
    }

    tokens.push({ content: rest[0], color: defaultColor })
    index += 1
  }

  return tokens.length > 0 ? tokens : [{ content: ' ', color: defaultColor }]
}

function tokenizeCoverCodeSnippetHeuristicLines(
  code: string,
  colors: CodeSyntaxColors,
  defaultColor: string,
): CoverCodeToken[][] {
  return code.split('\n').map((line) => {
    const trimmed = line.trimEnd()
    if (!trimmed) return [{ content: ' ', color: defaultColor }]
    return tokenizeCoverCodeSnippetHeuristicLine(trimmed, colors, defaultColor)
  })
}

export function tokenizeCoverCodeSnippet(
  code: string,
  language: CoverCodeSnippetLanguage,
  themeFamily: CoverThemeFamily,
  defaultColor: string,
): CoverCodeToken[][] {
  const colors = getCodeSyntaxColors(themeFamily === 'dark')
  const normalizedCode = code.replace(/\t/g, '  ')

  if (language === 'plaintext') {
    return normalizedCode.split('\n').map((line) => {
      const trimmed = line.trimEnd()
      return trimmed
        ? [{ content: trimmed, color: defaultColor }]
        : [{ content: ' ', color: defaultColor }]
    })
  }

  ensureCoverCodeSnippetPrismGrammars()
  const grammar = getCoverCodeSnippetPrismGrammar(language)

  if (!grammar) {
    return tokenizeCoverCodeSnippetHeuristicLines(
      normalizedCode,
      colors,
      defaultColor,
    )
  }

  try {
    const prismTokens = getCoverPrism().tokenize(
      normalizedCode,
      grammar,
    ) as Array<string | Token>
    const flattened = flattenPrismTokens(prismTokens, colors, defaultColor)

    if (flattened.length === 0) {
      return tokenizeCoverCodeSnippetHeuristicLines(
        normalizedCode,
        colors,
        defaultColor,
      )
    }

    return splitCoverCodeTokensIntoLines(flattened, defaultColor)
  } catch {
    return tokenizeCoverCodeSnippetHeuristicLines(
      normalizedCode,
      colors,
      defaultColor,
    )
  }
}
