/** Strip trailing brand underscores — templates append `_` in brand pink. */
export function stripCoverTitleSuffix(value: string): string {
  return value.trimEnd().replace(/_+$/u, '')
}

/** Marketing eyebrows are uppercase with wide tracking. */
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

export function wrapTextLines(
  text: string,
  maxCharsPerLine: number,
  maxLines: number,
): string[] {
  const words = text.trim().split(/\s+/).filter(Boolean)
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
    if (lines.length >= maxLines - 1) break
  }

  if (lines.length < maxLines) {
    lines.push(current)
  }

  if (lines.length === maxLines && words.join(' ').length > lines.join(' ').length) {
    const last = lines[maxLines - 1] ?? ''
    lines[maxLines - 1] =
      last.length > maxCharsPerLine - 3
        ? `${last.slice(0, Math.max(0, maxCharsPerLine - 3)).trim()}...`
        : `${last}...`
  }

  return lines.slice(0, maxLines)
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
