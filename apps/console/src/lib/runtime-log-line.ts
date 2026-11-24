/**
 * Reads one runtime log line for display: a JSON object (pino, winston,
 * bunyan, structlog, slog, zap, Serilog compact, OpenTelemetry), a logfmt line
 * (`level=info msg="..."`), or plain text with a leading level word
 * (`ERROR ...`, `[warn] ...`, `TypeError: ...`).
 */

export type RuntimeLogLevel = 'error' | 'warn' | 'info' | 'debug'

/** Lowest first, so a minimum level keeps every entry at or after it. */
export const RUNTIME_LOG_LEVELS: RuntimeLogLevel[] = [
  'debug',
  'info',
  'warn',
  'error',
]

export interface RuntimeLogField {
  /** Dotted path for nested objects: `err.stack`, `req.headers.host`. */
  key: string
  /** Strings as written; anything else as compact JSON. */
  value: string
}

export interface RuntimeLogLine {
  /** `json` and `logfmt` lines carry fields; `text` lines never do. */
  format: 'json' | 'logfmt' | 'text'
  /** The level the line names, or `null` when it names none. */
  level: RuntimeLogLevel | null
  /** What to show first: the message field, or the whole text line. */
  message: string
  /** Every other field, in the order written. */
  fields: RuntimeLogField[]
}

const LEVEL_KEYS = [
  'level',
  'lvl',
  'severity',
  'levelname',
  'log.level',
  'severityText',
  'severity_text',
  '@l',
  'loglevel',
]

const MESSAGE_KEYS = ['msg', 'message', '@m', '@mt', 'event', 'body', 'text']

const LEVEL_NAMES: Record<string, RuntimeLogLevel> = {
  emerg: 'error',
  emergency: 'error',
  alert: 'error',
  panic: 'error',
  fatal: 'error',
  critical: 'error',
  crit: 'error',
  error: 'error',
  err: 'error',
  e: 'error',
  warning: 'warn',
  warn: 'warn',
  w: 'warn',
  notice: 'info',
  information: 'info',
  info: 'info',
  i: 'info',
  debug: 'debug',
  dbg: 'debug',
  d: 'debug',
  trace: 'debug',
  verbose: 'debug',
  silly: 'debug',
}

/** Words that open a plain text line; single letters are too ambiguous here. */
const TEXT_LEVEL_WORDS = new Set(
  Object.keys(LEVEL_NAMES).filter((name) => name.length > 1),
)

/** Nested objects flatten this deep; anything deeper stays JSON. */
const MAX_DEPTH = 3

const ESC = String.fromCharCode(27)

/** Drops ANSI SGR sequences (`ESC[31m`), which runtimes add to colored output. */
export function stripAnsi(text: string): string {
  if (!text.includes(ESC)) return text
  let out = ''
  let i = 0
  while (i < text.length) {
    if (text[i] === ESC && text[i + 1] === '[') {
      let j = i + 2
      while (j < text.length && (isDigit(text[j]) || text[j] === ';')) j++
      if (text[j] === 'm') {
        i = j + 1
        continue
      }
    }
    out += text[i]
    i++
  }
  return out
}

function isDigit(char: string | undefined): boolean {
  return char !== undefined && char >= '0' && char <= '9'
}

/** Maps a level name or number (pino/bunyan 10-60, OTel 1-24) to one of four. */
export function normalizeLevel(value: unknown): RuntimeLogLevel | null {
  if (typeof value === 'number' && Number.isFinite(value)) {
    // pino and bunyan: 10 trace, 20 debug, 30 info, 40 warn, 50 error, 60 fatal
    if (value >= 10) {
      if (value >= 50) return 'error'
      if (value >= 40) return 'warn'
      if (value >= 30) return 'info'
      return 'debug'
    }
    // OpenTelemetry severity numbers: 1-8 trace/debug, 9-12 info, 13-16 warn, 17+ error
    if (value >= 17) return 'error'
    if (value >= 13) return 'warn'
    if (value >= 9) return 'info'
    if (value >= 1) return 'debug'
    return null
  }
  if (typeof value !== 'string') return null
  const name = value.trim().toLowerCase()
  if (name === '') return null
  if (LEVEL_NAMES[name]) return LEVEL_NAMES[name]
  // OTel severity texts such as INFO2 or WARN3
  const base = name.slice(0, -1)
  if (isDigit(name[name.length - 1]) && LEVEL_NAMES[base]) {
    return LEVEL_NAMES[base]
  }
  if (name !== '' && [...name].every(isDigit)) {
    return normalizeLevel(Number(name))
  }
  return null
}

export function parseRuntimeLogLine(raw: string): RuntimeLogLine {
  const text = stripAnsi(raw)
  return parseJson(text) ?? parseLogfmt(text) ?? parseText(text)
}

/** True when `level` is at or above `minimum`. */
export function isAtLeast(
  level: RuntimeLogLevel,
  minimum: RuntimeLogLevel,
): boolean {
  return (
    RUNTIME_LOG_LEVELS.indexOf(level) >= RUNTIME_LOG_LEVELS.indexOf(minimum)
  )
}

function parseJson(text: string): RuntimeLogLine | null {
  const trimmed = text.trim()
  if (!trimmed.startsWith('{') || !trimmed.endsWith('}')) return null
  let value: unknown
  try {
    value = JSON.parse(trimmed)
  } catch {
    return null
  }
  if (!isRecord(value)) return null

  const levelKey = LEVEL_KEYS.find((key) => normalizeLevel(value[key]) !== null)
  const level = levelKey ? normalizeLevel(value[levelKey]) : null
  // OTel puts the level name and number side by side; drop the number too
  const skip = new Set(levelKey ? [levelKey] : [])
  if (levelKey && 'severityNumber' in value) skip.add('severityNumber')

  const messageKey = MESSAGE_KEYS.find(
    (key) => typeof value[key] === 'string' && value[key] !== '',
  )
  if (messageKey) skip.add(messageKey)
  const message = messageKey ? (value[messageKey] as string) : ''

  const fields: RuntimeLogField[] = []
  for (const [key, entry] of Object.entries(value)) {
    if (skip.has(key)) continue
    flatten(key, entry, 1, fields)
  }

  return { format: 'json', level, message, fields }
}

function flatten(
  key: string,
  value: unknown,
  depth: number,
  out: RuntimeLogField[],
) {
  if (isRecord(value) && depth < MAX_DEPTH && Object.keys(value).length > 0) {
    for (const [child, entry] of Object.entries(value)) {
      flatten(`${key}.${child}`, entry, depth + 1, out)
    }
    return
  }
  out.push({ key, value: formatValue(value) })
}

function formatValue(value: unknown): string {
  if (typeof value === 'string') return value
  if (value === undefined) return 'undefined'
  try {
    return JSON.stringify(value) ?? String(value)
  } catch {
    return String(value)
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * `key=value key2="quoted value"`. Taken as logfmt only when every token is a
 * pair and one of them names a level or a message, so prose with an `=` in it
 * stays text.
 */
function parseLogfmt(text: string): RuntimeLogLine | null {
  if (!text.includes('=') || text.includes('\n')) return null
  const pairs = tokenizeLogfmt(text.trim())
  if (!pairs || pairs.length < 2) return null

  const levelPair = pairs.find(
    ([key, value]) => LEVEL_KEYS.includes(key) && normalizeLevel(value),
  )
  const messagePair = pairs.find(
    ([key, value]) => MESSAGE_KEYS.includes(key) && value !== '',
  )
  if (!levelPair && !messagePair) return null

  const fields = pairs
    .filter((pair) => pair !== levelPair && pair !== messagePair)
    .map(([key, value]) => ({ key, value }))
  return {
    format: 'logfmt',
    level: levelPair ? normalizeLevel(levelPair[1]) : null,
    message: messagePair?.[1] ?? '',
    fields,
  }
}

function tokenizeLogfmt(text: string): [string, string][] | null {
  const pairs: [string, string][] = []
  let i = 0
  while (i < text.length) {
    while (text[i] === ' ' || text[i] === '\t') i++
    if (i >= text.length) break
    const keyStart = i
    while (i < text.length && text[i] !== '=' && text[i] !== ' ') i++
    if (text[i] !== '=' || i === keyStart) return null
    const key = text.slice(keyStart, i)
    i++
    let value = ''
    if (text[i] === '"') {
      i++
      let closed = false
      while (i < text.length) {
        const char = text[i]
        if (char === '\\' && i + 1 < text.length) {
          const next = text[i + 1]
          value += next === 'n' ? '\n' : next === 't' ? '\t' : next
          i += 2
          continue
        }
        if (char === '"') {
          closed = true
          i++
          break
        }
        value += char
        i++
      }
      if (!closed) return null
      if (i < text.length && text[i] !== ' ' && text[i] !== '\t') return null
    } else {
      const valueStart = i
      while (i < text.length && text[i] !== ' ' && text[i] !== '\t') i++
      value = text.slice(valueStart, i)
      if (value.includes('"')) return null
    }
    pairs.push([key, value])
  }
  return pairs
}

/** Plain text: the level is the first word, when it is a level name or an `...Error:`. */
function parseText(text: string): RuntimeLogLine {
  return {
    format: 'text',
    level: textLevel(text),
    message: text,
    fields: [],
  }
}

function textLevel(text: string): RuntimeLogLevel | null {
  const first = text.trimStart()
  let end = 0
  while (end < first.length && first[end].trim() !== '') end++
  let word = first.slice(0, end)
  // [WARN], <info>, (error), ERROR:, WARN|
  while (word !== '' && '[(<'.includes(word[0])) word = word.slice(1)
  while (word !== '' && '])>:|,'.includes(word[word.length - 1])) {
    word = word.slice(0, -1)
  }
  if (word === '') return null
  const name = word.toLowerCase()
  if (TEXT_LEVEL_WORDS.has(name)) return LEVEL_NAMES[name]
  // Uncaught exceptions: "TypeError: ...", "SomeException: ..."
  const colon = first.indexOf(':')
  if (colon > 0) {
    const head = first.slice(0, colon)
    if (
      !head.includes(' ') &&
      (head.endsWith('Error') || head.endsWith('Exception'))
    ) {
      return 'error'
    }
  }
  return null
}
