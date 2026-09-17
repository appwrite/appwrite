/** Tracking for small uppercase cover eyebrows (`cover-eyebrow`). */
export const COVER_EYEBROW_LETTER_SPACING = '0.1em'

/** Strip trailing brand underscores - templates append `_` in brand pink. */
export function stripCoverTitleSuffix(value: string): string {
  return value.trimEnd().replace(/_+$/u, '')
}

/** Marketing eyebrows are uppercase with modest tracking. */
export function formatCoverEyebrow(value: string | undefined): string | undefined {
  const raw = value?.trim()
  if (!raw) return undefined
  return stripCoverTitleSuffix(raw).toUpperCase() || undefined
}

export function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

function withLineEllipsis(line: string, maxCharsPerLine: number): string {
  const withoutTrail = line.replace(/[.\s]+$/u, '').trimEnd()
  const budget = Math.max(0, maxCharsPerLine - 3)
  if (withoutTrail.length > budget) {
    return `${withoutTrail.slice(0, budget).trimEnd()}...`
  }
  return `${withoutTrail}...`
}

function splitWordToFit(word: string, maxCharsPerLine: number): string[] {
  if (word.length <= maxCharsPerLine) return [word]

  const parts: string[] = []
  for (let index = 0; index < word.length; index += maxCharsPerLine) {
    parts.push(word.slice(index, index + maxCharsPerLine))
  }
  return parts
}

export function wrapTextLines(
  text: string,
  maxCharsPerLine: number,
  maxLines: number,
): string[] {
  if (maxLines <= 0) return []

  const words = text
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .flatMap((word) => splitWordToFit(word, maxCharsPerLine))
  if (!words.length) return []

  const lines: string[] = []
  let current = words[0] ?? ''

  for (const word of words.slice(1)) {
    const next = `${current} ${word}`
    if (next.length <= maxCharsPerLine) {
      current = next
      continue
    }
    lines.push(current)
    current = word
    if (lines.length >= maxLines) break
  }

  if (lines.length < maxLines) {
    lines.push(current)
  }

  const wrapped = lines.slice(0, maxLines)
  const joinedLines = wrapped.join(' ')
  const sourceText = words.join(' ')
  if (sourceText.length > joinedLines.length) {
    wrapped[maxLines - 1] = withLineEllipsis(wrapped[maxLines - 1] ?? '', maxCharsPerLine)
  }

  return wrapped
}

/**
 * Like {@link wrapTextLines}, but explicit line breaks (`\n`) in `text` start a
 * new line. Each segment still wraps at `maxCharsPerLine`; the total never
 * exceeds `maxLines` and the last line is ellipsized when text is cut.
 */
export function wrapTextLinesWithBreaks(
  text: string,
  maxCharsPerLine: number,
  maxLines: number,
): string[] {
  if (maxLines <= 0) return []

  const segments = text
    .split(/\r?\n/u)
    .map((segment) => segment.trim())
    .filter(Boolean)
  if (segments.length <= 1) {
    return wrapTextLines(segments[0] ?? '', maxCharsPerLine, maxLines)
  }

  // `wrapTextLines` ellipsizes a segment it has to cut, so only whole segments
  // that never fit need a trailing marker here.
  const lines: string[] = []
  let droppedSegment = false
  for (const [index, segment] of segments.entries()) {
    const remaining = maxLines - lines.length
    if (remaining <= 0) {
      droppedSegment = true
      break
    }
    const isLastSegment = index === segments.length - 1
    // Leave room for later segments so a long first line does not eat every slot.
    const budget = isLastSegment
      ? remaining
      : Math.max(1, remaining - (segments.length - index - 1))
    lines.push(...wrapTextLines(segment, maxCharsPerLine, budget))
  }

  if (droppedSegment && lines.length) {
    const last = lines[lines.length - 1] ?? ''
    if (!last.endsWith('...')) {
      lines[lines.length - 1] = withLineEllipsis(last, maxCharsPerLine)
    }
  }

  return lines
}

export function clampNumber(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

export function parseBooleanParam(
  value: string | null | undefined,
  fallback: boolean,
): boolean {
  if (value == null || value === '') return fallback
  const normalized = value.trim().toLowerCase()
  if (normalized === '1' || normalized === 'true' || normalized === 'yes') {
    return true
  }
  if (normalized === '0' || normalized === 'false' || normalized === 'no') {
    return false
  }
  return fallback
}

export function parseNumberParam(
  value: string | null | undefined,
  fallback: number,
  min?: number,
  max?: number,
): number {
  if (value == null || value === '') return fallback
  const parsed = Number(value)
  if (!Number.isFinite(parsed)) return fallback
  if (min != null && max != null) return clampNumber(parsed, min, max)
  return parsed
}
