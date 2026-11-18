/**
 * Subtitle cue model for the subtitle editor: parsing WebVTT and SubRip,
 * serializing back to WebVTT or SubRip, and checking cues for common timing
 * and readability problems.
 */

export type SubtitleCue = {
  id: string
  start: number
  end: number
  text: string
  /** WebVTT cue settings after the end timestamp (`align:start line:0`). */
  settings?: string
}

/** Same rule as the Videos API `name` attribute. */
export const VIDEO_SUBTITLE_NAME_PATTERN = /^[A-Za-z0-9 \-.,()_']*$/

export const SUBTITLE_MIN_CUE_DURATION = 0.1
export const SUBTITLE_DEFAULT_CUE_DURATION = 2
export const SUBTITLE_MAX_CHARS_PER_SECOND = 20
export const SUBTITLE_MAX_LINE_LENGTH = 42
export const SUBTITLE_MAX_LINES = 2

let cueCounter = 0

export function createSubtitleCueId(): string {
  cueCounter += 1
  return `cue-${Date.now().toString(36)}-${cueCounter.toString(36)}`
}

const TIMESTAMP_PATTERN = /^(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:[.,](\d{1,3}))?$/

function parseCueTimestamp(value: string): number | null {
  const match = TIMESTAMP_PATTERN.exec(value.trim())
  if (!match) return null
  const [, hours = '0', minutes, seconds, fraction = '0'] = match
  return (
    Number(hours) * 3600 +
    Number(minutes) * 60 +
    Number(seconds) +
    Number(fraction.padEnd(3, '0')) / 1000
  )
}

/**
 * Parses what people type into a timestamp field: `1:02.5`, `00:01:02,500`,
 * or plain seconds (`62.5`).
 */
export function parseTimestampInput(value: string): number | null {
  const trimmed = value.trim()
  if (!trimmed) return null
  if (/^\d+(?:[.,]\d+)?$/.test(trimmed)) {
    const seconds = Number(trimmed.replace(',', '.'))
    return Number.isFinite(seconds) ? seconds : null
  }
  return parseCueTimestamp(trimmed)
}

function splitTime(seconds: number) {
  const totalMs = Math.max(0, Math.round(seconds * 1000))
  return {
    hours: Math.floor(totalMs / 3_600_000),
    minutes: Math.floor((totalMs % 3_600_000) / 60_000),
    seconds: Math.floor((totalMs % 60_000) / 1000),
    ms: totalMs % 1000,
  }
}

const pad = (value: number, length = 2) => String(value).padStart(length, '0')

/** `HH:MM:SS.mmm` (WebVTT) or `HH:MM:SS,mmm` (SubRip). */
export function formatCueTimestamp(
  seconds: number,
  format: 'vtt' | 'srt' = 'vtt',
): string {
  const t = splitTime(seconds)
  const separator = format === 'srt' ? ',' : '.'
  return `${pad(t.hours)}:${pad(t.minutes)}:${pad(t.seconds)}${separator}${pad(t.ms, 3)}`
}

/** Compact editor timestamp: `M:SS.mmm`, with hours only when needed. */
export function formatEditorTimestamp(seconds: number): string {
  const t = splitTime(seconds)
  const minutes = t.hours > 0 ? pad(t.minutes) : String(t.minutes)
  return `${t.hours > 0 ? `${t.hours}:` : ''}${minutes}:${pad(t.seconds)}.${pad(t.ms, 3)}`
}

/** Parses WebVTT or SubRip text. Header, NOTE, STYLE, and REGION blocks are skipped. */
export function parseSubtitleText(input: string): SubtitleCue[] {
  const text = input.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n')
  const cues: SubtitleCue[] = []
  for (const block of text.split(/\n{2,}/)) {
    const lines = block.split('\n')
    const timingIndex = lines.findIndex((line) => line.includes('-->'))
    if (timingIndex < 0) continue
    const first = lines[0]?.trim() ?? ''
    if (/^(NOTE|STYLE|REGION)(\s|$)/.test(first)) continue
    const [startRaw, rest = ''] = lines[timingIndex].split('-->')
    const [endRaw = '', ...settingParts] = rest.trim().split(/\s+/)
    const start = parseCueTimestamp(startRaw)
    const end = parseCueTimestamp(endRaw)
    if (start == null || end == null) continue
    const settings = settingParts.join(' ').trim()
    cues.push({
      id: createSubtitleCueId(),
      start,
      end,
      text: lines
        .slice(timingIndex + 1)
        .join('\n')
        .trim(),
      ...(settings ? { settings } : {}),
    })
  }
  return sortSubtitleCues(cues)
}

export function sortSubtitleCues(cues: SubtitleCue[]): SubtitleCue[] {
  return [...cues].sort((a, b) => a.start - b.start || a.end - b.end)
}

/** Merges cue lists from segmented tracks, dropping cues repeated across segments. */
export function dedupeSubtitleCues(cues: SubtitleCue[]): SubtitleCue[] {
  const seen = new Set<string>()
  return sortSubtitleCues(cues).filter((cue) => {
    const key = `${cue.start.toFixed(3)}|${cue.end.toFixed(3)}|${cue.text}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

/** Empty cues are not valid in either format, so serializers skip them. */
function writableCues(cues: SubtitleCue[]): SubtitleCue[] {
  return sortSubtitleCues(cues).filter((cue) => cue.text.trim())
}

export function serializeWebVtt(cues: SubtitleCue[]): string {
  const body = writableCues(cues)
    .map((cue) => {
      const timing = `${formatCueTimestamp(cue.start)} --> ${formatCueTimestamp(cue.end)}`
      return `${cue.settings ? `${timing} ${cue.settings}` : timing}\n${cue.text.trim()}`
    })
    .join('\n\n')
  return `WEBVTT\n\n${body}${body ? '\n' : ''}`
}

export function serializeSubRip(cues: SubtitleCue[]): string {
  return writableCues(cues)
    .map(
      (cue, index) =>
        `${index + 1}\n${formatCueTimestamp(cue.start, 'srt')} --> ${formatCueTimestamp(cue.end, 'srt')}\n${cue.text.trim()}`,
    )
    .join('\n\n')
    .concat('\n')
}

/** Cue text without WebVTT markup (`<i>`, `<v Speaker>`, `<c.yellow>`). */
export type CueMarkupTag = 'i' | 'b' | 'u' | 'c' | 'v' | 'lang' | 'ruby' | 'rt'

export type CueMarkupElement = {
  tag: CueMarkupTag
  /** Only the standard WebVTT color classes (e.g. `yellow`, `bg_black`). */
  classes: CueColorClass[]
  /** Speaker name from `<v Name>`. */
  voice?: string
  /** BCP 47 tag from `<lang xx>`. */
  lang?: string
  /** Validated color from SRT `<font color="...">`. */
  color?: string
  children: CueMarkupNode[]
}

export type CueMarkupNode = string | CueMarkupElement

export const CUE_COLOR_CLASSES = [
  'white', 'lime', 'cyan', 'red', 'yellow', 'magenta', 'blue', 'black',
] as const
export type CueColorClass =
  | (typeof CUE_COLOR_CLASSES)[number]
  | `bg_${(typeof CUE_COLOR_CLASSES)[number]}`

const CUE_COLOR_CLASS_SET = new Set<string>([
  ...CUE_COLOR_CLASSES,
  ...CUE_COLOR_CLASSES.map((name) => `bg_${name}`),
])
const CSS_COLOR_PATTERN = /^(#[0-9a-f]{3,8}|[a-z]{3,20})$/i
const LANG_TAG_PATTERN = /^[a-z]{1,8}(-[a-z0-9]{1,8})*$/i
const NAMED_ENTITIES: Record<string, string> = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: '\u00a0',
  lrm: '\u200e',
  rlm: '\u200f',
}

function decodeCueEntities(text: string): string {
  return text.replace(/&(#x[0-9a-f]{1,6}|#[0-9]{1,7}|[a-z]+);/gi, (match, body: string) => {
    if (body[0] !== '#') return NAMED_ENTITIES[body.toLowerCase()] ?? match
    const code =
      body[1] === 'x' || body[1] === 'X'
        ? parseInt(body.slice(2), 16)
        : parseInt(body.slice(1), 10)
    return code > 0 && code <= 0x10ffff ? String.fromCodePoint(code) : match
  })
}

function parseOpenTag(raw: string): CueMarkupElement | null {
  // WebVTT: `<tag.class1.class2 annotation>`; SRT: `<font color="red">`.
  const match = /^([a-z]+)((?:\.[\w-]+)*)(?:\s+([\s\S]*))?$/i.exec(raw.trim())
  if (!match) return null
  const name = match[1].toLowerCase()
  const classes = match[2]
    .split('.')
    .filter((value): value is CueColorClass => CUE_COLOR_CLASS_SET.has(value))
  const annotation = match[3]?.trim() ?? ''
  const element = (tag: CueMarkupTag, extra: Partial<CueMarkupElement> = {}) => ({
    tag,
    classes,
    children: [],
    ...extra,
  })
  switch (name) {
    case 'i':
    case 'b':
    case 'u':
    case 'c':
    case 'ruby':
    case 'rt':
      return element(name)
    case 'v':
      return element('v', annotation ? { voice: annotation } : {})
    case 'lang':
      return element('lang', LANG_TAG_PATTERN.test(annotation) ? { lang: annotation } : {})
    case 'font': {
      const color = /color\s*=\s*["']?([^"'\s>]+)/i.exec(annotation)?.[1]
      return element('c', color && CSS_COLOR_PATTERN.test(color) ? { color } : {})
    }
    default:
      return null
  }
}

/**
 * Parses WebVTT/SRT cue markup into a tree for safe rendering (never as HTML).
 * Unknown tags and timestamp tags are dropped while their text is kept.
 */
export function parseCueMarkup(text: string): CueMarkupNode[] {
  const root: CueMarkupElement = { tag: 'c', classes: [], children: [] }
  // Each stack entry keeps the source tag name so `</font>` closes a `<font>`.
  const stack: Array<{ name: string; element: CueMarkupElement }> = [
    { name: '', element: root },
  ]
  const tagPattern = /<(\/?)([^<>]*)>/g
  let last = 0
  const pushText = (value: string) => {
    if (!value) return
    const children = stack[stack.length - 1].element.children
    const decoded = decodeCueEntities(value)
    const previous = children[children.length - 1]
    if (typeof previous === 'string') children[children.length - 1] = previous + decoded
    else children.push(decoded)
  }
  for (const match of text.matchAll(tagPattern)) {
    const start = match.index ?? 0
    pushText(text.slice(last, start))
    last = start + match[0].length
    const [, closing, body] = match
    const name = /^[a-z]+/i.exec(body.trim())?.[0]?.toLowerCase() ?? ''
    if (closing) {
      for (let index = stack.length - 1; index > 0; index--) {
        if (stack[index].name !== name) continue
        stack.length = index
        break
      }
      continue
    }
    const element = parseOpenTag(body)
    if (!element) continue
    stack[stack.length - 1].element.children.push(element)
    stack.push({ name, element })
  }
  pushText(text.slice(last))
  return root.children
}

function cueMarkupText(nodes: CueMarkupNode[]): string {
  return nodes
    .map((node) => (typeof node === 'string' ? node : cueMarkupText(node.children)))
    .join('')
}

export function stripCueMarkup(text: string): string {
  if (!text.includes('<') && !text.includes('&')) return text
  return cueMarkupText(parseCueMarkup(text)).replace(/\u00a0/g, ' ')
}

/** Text alignment from WebVTT cue settings (`align:start`, etc.). */
export function cueTextAlign(
  settings: string | undefined,
): 'start' | 'center' | 'end' | 'left' | 'right' {
  const value = /(?:^|\s)align:(start|center|middle|end|left|right)(?:\s|$)/.exec(
    settings ?? '',
  )?.[1]
  if (!value || value === 'middle') return 'center'
  return value as 'start' | 'center' | 'end' | 'left' | 'right'
}

export function cueCharsPerSecond(cue: SubtitleCue): number {
  const duration = cue.end - cue.start
  if (duration <= 0) return 0
  const chars = stripCueMarkup(cue.text).replace(/\s+/g, ' ').trim().length
  return chars / duration
}

export type SubtitleCueIssueKind =
  | 'invalid-timing'
  | 'empty-text'
  | 'overlap'
  | 'past-end'
  | 'reading-speed'
  | 'line-length'
  | 'too-many-lines'

export type SubtitleCueIssue = {
  kind: SubtitleCueIssueKind
  severity: 'error' | 'warning'
}

/** English issue copy, translated at the render site. */
export const SUBTITLE_CUE_ISSUE_LABELS: Record<SubtitleCueIssueKind, string> = {
  'invalid-timing': 'End time must be after the start time.',
  'empty-text': 'Cue has no text.',
  overlap: 'Overlaps the previous cue.',
  'past-end': 'Ends after the video does.',
  'reading-speed': 'Reading speed is above 20 characters per second.',
  'line-length': 'A line is longer than 42 characters.',
  'too-many-lines': 'More than 2 lines.',
}

/** Issues per cue ID. Errors block saving; warnings are advisory. */
export function validateSubtitleCues(
  cues: SubtitleCue[],
  duration?: number,
): Map<string, SubtitleCueIssue[]> {
  const issues = new Map<string, SubtitleCueIssue[]>()
  const add = (id: string, issue: SubtitleCueIssue) => {
    const list = issues.get(id)
    if (list) list.push(issue)
    else issues.set(id, [issue])
  }
  let previousEnd = -Infinity
  for (const cue of sortSubtitleCues(cues)) {
    if (cue.end - cue.start < SUBTITLE_MIN_CUE_DURATION / 2) {
      add(cue.id, { kind: 'invalid-timing', severity: 'error' })
    }
    const plain = stripCueMarkup(cue.text).trim()
    if (!plain) add(cue.id, { kind: 'empty-text', severity: 'error' })
    if (cue.start < previousEnd - 0.0005) {
      add(cue.id, { kind: 'overlap', severity: 'warning' })
    }
    if (duration && duration > 0 && cue.end > duration + 0.5) {
      add(cue.id, { kind: 'past-end', severity: 'warning' })
    }
    if (cueCharsPerSecond(cue) > SUBTITLE_MAX_CHARS_PER_SECOND) {
      add(cue.id, { kind: 'reading-speed', severity: 'warning' })
    }
    const lines = plain.split('\n')
    if (lines.some((line) => line.length > SUBTITLE_MAX_LINE_LENGTH)) {
      add(cue.id, { kind: 'line-length', severity: 'warning' })
    }
    if (lines.length > SUBTITLE_MAX_LINES) {
      add(cue.id, { kind: 'too-many-lines', severity: 'warning' })
    }
    previousEnd = Math.max(previousEnd, cue.end)
  }
  return issues
}

/** Cues showing at `time`. */
export function activeSubtitleCues(
  cues: SubtitleCue[],
  time: number,
): SubtitleCue[] {
  return cues.filter((cue) => cue.start <= time && time < cue.end)
}

/** Shortens each cue so it ends where the next one starts. */
export function trimSubtitleOverlaps(cues: SubtitleCue[]): SubtitleCue[] {
  const sorted = sortSubtitleCues(cues)
  return sorted.map((cue, index) => {
    const next = sorted[index + 1]
    if (!next || cue.end <= next.start) return cue
    return {
      ...cue,
      end: Math.max(cue.start + SUBTITLE_MIN_CUE_DURATION, next.start),
    }
  })
}

/** Moves cues by `offset` seconds, never before 0. */
export function shiftSubtitleCues(
  cues: SubtitleCue[],
  offset: number,
  ids?: ReadonlySet<string>,
): SubtitleCue[] {
  return cues.map((cue) => {
    if (ids && !ids.has(cue.id)) return cue
    const shift = Math.max(offset, -cue.start)
    return { ...cue, start: cue.start + shift, end: cue.end + shift }
  })
}

/** Splits a cue at `time`, dividing the text at the nearest line or word break. */
export function splitSubtitleCue(
  cue: SubtitleCue,
  time: number,
): [SubtitleCue, SubtitleCue] | null {
  if (
    time - cue.start < SUBTITLE_MIN_CUE_DURATION ||
    cue.end - time < SUBTITLE_MIN_CUE_DURATION
  ) {
    return null
  }
  const text = cue.text.trim()
  const ratio = (time - cue.start) / (cue.end - cue.start)
  let firstText = text
  let secondText = ''
  const lines = text.split('\n')
  if (lines.length > 1) {
    const cut = Math.min(
      lines.length - 1,
      Math.max(1, Math.round(lines.length * ratio)),
    )
    firstText = lines.slice(0, cut).join('\n')
    secondText = lines.slice(cut).join('\n')
  } else {
    const words = text.split(/\s+/).filter(Boolean)
    if (words.length > 1) {
      const cut = Math.min(
        words.length - 1,
        Math.max(1, Math.round(words.length * ratio)),
      )
      firstText = words.slice(0, cut).join(' ')
      secondText = words.slice(cut).join(' ')
    }
  }
  return [
    { ...cue, end: time, text: firstText },
    { ...cue, id: createSubtitleCueId(), start: time, text: secondText },
  ]
}

export function mergeSubtitleCues(
  first: SubtitleCue,
  second: SubtitleCue,
): SubtitleCue {
  return {
    ...first,
    start: Math.min(first.start, second.start),
    end: Math.max(first.end, second.end),
    text: [first.text.trim(), second.text.trim()].filter(Boolean).join('\n'),
  }
}

const RTL_LANGUAGES = new Set([
  'ar', 'ara', 'arc', 'ckb', 'dv', 'div', 'fa', 'fas', 'per', 'he', 'heb',
  'iw', 'ps', 'pus', 'syr', 'ur', 'urd', 'yi', 'yid',
])

/** Text direction for cue text in a track language (ISO 639-1/2 codes, with or without a region). */
export function subtitleTextDirection(code: string | undefined): 'rtl' | 'auto' {
  const base = code?.split(/[-_]/)[0]?.toLowerCase() ?? ''
  return RTL_LANGUAGES.has(base) ? 'rtl' : 'auto'
}

/** Storage-safe file name for a track, e.g. `english-eng.vtt`. */
export function subtitleFileName(
  name: string,
  code: string,
  extension: 'vtt' | 'srt',
): string {
  const slug = name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
  return `${[slug || 'subtitles', code].filter(Boolean).join('-')}.${extension}`
}
