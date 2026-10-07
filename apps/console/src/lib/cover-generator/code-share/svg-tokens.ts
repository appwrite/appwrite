export type CoverCodeToken = {
  content: string
  color: string
}

function escapeCoverCodeTokenContent(content: string): string {
  return content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/ /g, '&#160;')
}

/** Expand tabs and measure leading whitespace for monospace indent offset. */
export function getCoverCodeLineIndentPx(
  line: string,
  fontSize: number,
  options?: { tabWidthSpaces?: number; charWidthRatio?: number },
): number {
  const leading = line.match(/^(\s*)/)?.[1] ?? ''
  if (!leading) return 0

  const tabWidthSpaces = options?.tabWidthSpaces ?? 2
  const charWidthRatio = options?.charWidthRatio ?? 0.6
  const expanded = leading.replace(/\t/g, ' '.repeat(tabWidthSpaces))
  return expanded.length * fontSize * charWidthRatio
}

/** Remove leading whitespace tokens after indent is applied via x offset. */
export function stripLeadingWhitespaceCoverTokens(
  tokens: CoverCodeToken[],
  defaultColor: string,
): CoverCodeToken[] {
  const result = [...tokens]
  while (result.length > 0 && /^\s*$/.test(result[0].content)) {
    result.shift()
  }

  return result.length > 0 ? result : [{ content: ' ', color: defaultColor }]
}

/** Move leading whitespace off each token onto the previous token (librsvg trims tspan leading space). */
export function normalizeCoverCodeTspanTokens(tokens: CoverCodeToken[]): CoverCodeToken[] {
  const result: CoverCodeToken[] = []

  for (const token of tokens) {
    if (!token.content) continue

    const leading = token.content.match(/^\s*/)?.[0] ?? ''
    const rest = token.content.slice(leading.length)

    if (leading && result.length > 0) {
      result[result.length - 1].content += leading
    } else if (leading && !rest) {
      result.push({ content: leading, color: token.color })
      continue
    } else if (leading && rest) {
      result.push({ content: leading + rest, color: token.color })
      continue
    }

    if (rest) {
      result.push({ content: rest, color: token.color })
    }
  }

  return result
}

/** Align syntax tokens to the exact line content so every character (including spaces) is kept. */
export function alignCoverCodeTokensToContent(
  content: string,
  tokens: CoverCodeToken[],
  defaultColor: string,
): CoverCodeToken[] {
  if (!content) return [{ content: ' ', color: defaultColor }]

  const joined = tokens.map((token) => token.content).join('')
  if (joined === content) {
    return normalizeCoverCodeTspanTokens(tokens)
  }

  const aligned: CoverCodeToken[] = []
  let contentIndex = 0
  let tokenIndex = 0
  let tokenOffset = 0

  while (contentIndex < content.length) {
    if (tokenIndex < tokens.length) {
      const token = tokens[tokenIndex]
      const remaining = token.content.slice(tokenOffset)

      if (remaining.length > 0 && remaining[0] !== content[contentIndex]) {
        if (/^\s/.test(remaining[0]) && !/\s/.test(content[contentIndex])) {
          tokenOffset += 1
          continue
        }
        if (tokenOffset > 0 || remaining[0] !== content[contentIndex]) {
          tokenIndex += 1
          tokenOffset = 0
          continue
        }
      }

      if (remaining.length > 0 && remaining[0] === content[contentIndex]) {
        let runLength = 0
        while (
          runLength < remaining.length &&
          contentIndex + runLength < content.length &&
          remaining[runLength] === content[contentIndex + runLength]
        ) {
          runLength += 1
        }

        if (runLength > 0) {
          const chunk = remaining.slice(0, runLength)
          const previous = aligned[aligned.length - 1]
          if (previous && previous.color === token.color) {
            previous.content += chunk
          } else {
            aligned.push({ content: chunk, color: token.color })
          }
          contentIndex += runLength
          tokenOffset += runLength
          if (tokenOffset >= token.content.length) {
            tokenIndex += 1
            tokenOffset = 0
          }
          continue
        }
      }
    }

    const character = content[contentIndex]
    const previous = aligned[aligned.length - 1]
    if (previous && previous.color === defaultColor) {
      previous.content += character
    } else {
      aligned.push({ content: character, color: defaultColor })
    }
    contentIndex += 1
  }

  return normalizeCoverCodeTspanTokens(aligned)
}

/** One `<text>` per line with colored `<tspan>` children so spaces flow naturally. */
export function buildCoverCodeTokenTexts(
  tokens: CoverCodeToken[],
  startX: number,
  fontSize: number,
  y: number,
  _charWidthRatio = 0.6,
): string {
  if (tokens.length === 0) return ''

  const normalizedTokens = normalizeCoverCodeTspanTokens(tokens)
  const tspans = normalizedTokens
    .map((token) => {
      const escaped = escapeCoverCodeTokenContent(token.content)
      return `<tspan fill="${token.color}">${escaped}</tspan>`
    })
    .join('')

  return `<text class="cover-code" xml:space="preserve" font-size="${fontSize}" x="${Math.round(startX)}" y="${y}">${tspans}</text>`
}
