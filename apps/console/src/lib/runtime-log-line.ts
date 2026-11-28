/**
 * Reads one runtime log line for display: a JSON object (pino, winston,
 * bunyan, structlog, slog, zap, Serilog compact, OpenTelemetry), a logfmt line
 * (`level=info msg="..."`), or plain text with a level near its start
 * (`ERROR ...`, `[warn] ...`, `TypeError: ...`, or after a timestamp, as
 * PostgreSQL and MySQL write: `2026-11-27 10:00:00 UTC [42] LOG:  ...`).
 */

export type RuntimeLogLevel = 'error' | 'warn' | 'info' | 'debug'

/** Every level a runtime log line can carry, lowest first. */
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
  /**
   * What to show first: the message field, or the text line without the
   * timestamp and level it opens with, which the viewer shows on their own.
   */
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

/** OpenTelemetry severity numbers: 1-8 trace/debug, 9-12 info, 13-16 warn, 17-24 error. */
function otelSeverity(value: unknown): RuntimeLogLevel | null {
  if (typeof value !== 'number' || !Number.isInteger(value)) return null
  if (value < 1 || value > 24) return null
  if (value >= 17) return 'error'
  if (value >= 13) return 'warn'
  if (value >= 9) return 'info'
  return 'debug'
}

export function parseRuntimeLogLine(raw: string): RuntimeLogLine {
  const text = stripAnsi(raw)
  return parseJson(text) ?? parseLogfmt(text) ?? parseText(text)
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
  // OTel may send only a severity number, on its own 1-24 scale
  const otel = levelKey ? null : otelSeverity(value.severityNumber)
  const level = levelKey ? normalizeLevel(value[levelKey]) : otel
  // OTel puts the level name and number side by side; drop the number too
  const skip = new Set(levelKey ? [levelKey] : [])
  if ((levelKey || otel) && 'severityNumber' in value) {
    skip.add('severityNumber')
  }

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

/**
 * The `key=value` pairs of a text line that is made of nothing else, for the
 * expanded row. Lines without a level or message key stay text on the row, but
 * their pairs still read better as fields than as one long run.
 */
export function logfmtFields(text: string): RuntimeLogField[] | null {
  if (!text.includes('=') || text.includes('\n')) return null
  const pairs = tokenizeLogfmt(text.trim())
  if (!pairs || pairs.length < 2) return null
  return pairs.map(([key, value]) => ({ key, value }))
}

const TRACEBACK = 'Traceback (most recent call last)'

/**
 * One line that stands for a message on a list row: its first line, marked
 * as cut when more follow. A Python traceback ends with the exception, so it
 * is named by its last line instead.
 */
export function summarizeRuntimeLogMessage(message: string): string {
  const newline = message.indexOf('\n')
  if (newline === -1) return message
  if (message.trimStart().startsWith(TRACEBACK)) {
    const lines = message.split('\n')
    for (let i = lines.length - 1; i > 0; i--) {
      if (lines[i].trim() !== '') return lines[i].trim()
    }
  }
  return `${message.slice(0, newline)} …`
}

/** The whole entry as logged: JSON indented, anything else as it came. */
export function formatRawLog(text: string): string {
  const trimmed = text.trim()
  const json =
    (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
    (trimmed.startsWith('[') && trimmed.endsWith(']'))
  if (!json) return text
  try {
    return JSON.stringify(JSON.parse(trimmed), null, 2)
  } catch {
    return text
  }
}

/** Tokens read past at the start of a text line while looking for its level. */
const MAX_PREFIX_TOKENS = 6

/** Marks some runtimes print instead of a level word (Next.js). */
const LEVEL_MARKS: Record<string, RuntimeLogLevel> = {
  '⚠': 'warn',
  '⨯': 'error',
}

/**
 * Plain text: a level word at the start, or right after a timestamp, or an
 * uncaught `...Error:`. The timestamp and the level word leave the message,
 * since the viewer shows both on their own.
 */
function parseText(text: string): RuntimeLogLine {
  const newline = text.indexOf('\n')
  const first = newline === -1 ? text : text.slice(0, newline)
  const after = newline === -1 ? '' : text.slice(newline)

  let i = 0
  let sawTime = false
  // Where the message starts once the timestamp, and any pid after it, are left out
  let start = -1
  for (let n = 0; n < MAX_PREFIX_TOKENS; n++) {
    while (i < first.length && isSpace(first[i])) i++
    if (i >= first.length) break
    let end = i
    while (end < first.length && !isSpace(first[end])) end++
    const token = first.slice(i, end)

    const level = levelWord(token, n === 0 || sawTime)
    if (level) {
      // Python's `INFO:root:message` keeps its logger next to the message
      let rest = isLoggerPrefixed(token) ? i : end
      while (rest < first.length && isSpace(first[rest])) rest++
      const message = first.slice(rest) + after
      return {
        format: 'text',
        level,
        message: message.trim() === '' ? text : message,
        fields: [],
      }
    }
    if (isTimeToken(token)) {
      sawTime = true
      start = end
    } else if (sawTime && (isBracketedNumber(token) || isDigits(token))) {
      // `[42]` after a PostgreSQL timestamp, `0` after a MySQL one
      start = end
    } else {
      break
    }
    i = end
  }

  let message = text
  if (sawTime && start > 0) {
    let rest = start
    while (rest < first.length && isSpace(first[rest])) rest++
    if (rest < first.length) message = first.slice(rest) + after
  }
  // Runtimes indent some lines (` GET /pricing 200`); the column aligns them
  message = message.trimStart() || text
  return {
    format: 'text',
    level: exceptionLevel(message) ?? markLevel(message),
    message,
    fields: [],
  }
}

function isSpace(char: string | undefined): boolean {
  return char === ' ' || char === '\t'
}

function isDigits(token: string): boolean {
  return token !== '' && [...token].every(isDigit)
}

function isBracketedNumber(token: string): boolean {
  return (
    token.length > 2 &&
    token[0] === '[' &&
    token[token.length - 1] === ']' &&
    isDigits(token.slice(1, -1))
  )
}

/**
 * A level as a text line names it: `ERROR`, `[warn]`, `<info>`, `ERROR:`,
 * `DEBUG|`, `LOG:` (PostgreSQL), or `INFO:root:...` (Python's default format).
 * A bare word only counts in capitals, so prose ("Alert sent") is not read as
 * one. `leading` is false for tokens past the start that no timestamp led to.
 */
function levelWord(token: string, leading: boolean): RuntimeLogLevel | null {
  if (!leading) return null
  if (token === 'LOG:') return 'info'
  let word = token
  let delimited = false
  while (word !== '' && '[(<'.includes(word[0])) {
    word = word.slice(1)
    delimited = true
  }
  while (word !== '' && '])>:|,'.includes(word[word.length - 1])) {
    word = word.slice(0, -1)
    delimited = true
  }
  // Python's default `LEVEL:logger:message` keeps the logger in the message
  const colon = word.indexOf(':')
  if (colon > 0) {
    const head = word.slice(0, colon)
    return isUpper(head) && TEXT_LEVEL_WORDS.has(head.toLowerCase())
      ? LEVEL_NAMES[head.toLowerCase()]
      : null
  }
  if (word === '') return null
  const name = word.toLowerCase()
  if (!TEXT_LEVEL_WORDS.has(name)) return null
  return delimited || isUpper(word) ? LEVEL_NAMES[name] : null
}

/** `INFO:root:...`: a colon inside the token, not only at its end. */
function isLoggerPrefixed(token: string): boolean {
  const colon = token.indexOf(':')
  return colon > 0 && colon < token.length - 1
}

function isUpper(word: string): boolean {
  return (
    word !== '' && word === word.toUpperCase() && word !== word.toLowerCase()
  )
}

/** Uncaught exceptions: "TypeError: ...", "SomeException: ...", a Python traceback. */
function exceptionLevel(text: string): RuntimeLogLevel | null {
  const first = text.trimStart()
  if (first.startsWith(TRACEBACK)) return 'error'
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

function markLevel(text: string): RuntimeLogLevel | null {
  const first = text.trimStart()
  return LEVEL_MARKS[first[0] ?? ''] ?? null
}

/**
 * A date, a time of day or a whole timestamp, as log prefixes write them:
 * `2026-11-27`, `10:00:00.123`, `2026-11-27T10:00:00.123Z`, `UTC`, `+00:00`,
 * optionally in brackets.
 */
function isTimeToken(raw: string): boolean {
  let token = raw
  if (token.startsWith('[')) token = token.slice(1)
  if (token.endsWith(']') || token.endsWith(',')) token = token.slice(0, -1)
  if (token === 'UTC' || token === 'GMT' || token === 'Z') return true
  if ((token[0] === '+' || token[0] === '-') && token.length >= 5) {
    return [...token.slice(1)].every((char) => isDigit(char) || char === ':')
  }
  if (isDate(token.slice(0, 10))) {
    const rest = token.slice(10)
    return (
      rest === '' ||
      ((rest[0] === 'T' || rest[0] === '_') && isClock(rest.slice(1)))
    )
  }
  return isClock(token)
}

function isDate(token: string): boolean {
  if (token.length !== 10) return false
  const separator = token[4]
  if (separator !== '-' && separator !== '/') return false
  if (token[7] !== separator) return false
  return [...token].every(
    (char, index) => index === 4 || index === 7 || isDigit(char),
  )
}

/** `HH:MM:SS` with optional fractions and a trailing zone: `10:00:00.123456Z`. */
function isClock(token: string): boolean {
  if (token.length < 8) return false
  if (token[2] !== ':' || token[5] !== ':') return false
  for (const index of [0, 1, 3, 4, 6, 7]) {
    if (!isDigit(token[index])) return false
  }
  return [...token.slice(8)].every(
    (char) => isDigit(char) || '.,:+-Z'.includes(char),
  )
}
