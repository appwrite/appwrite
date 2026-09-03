/**
 * Day 5 grand prize entries come from a verified social-post CSV the host
 * uploads during the stream. Online presence never feeds this draw.
 */

export const INIT_GRAND_PRIZE_CSV_COLUMNS = [
  'name',
  'username',
  'post_url',
  'ticket_url',
  'ticket_id',
  'ticket_domain',
  'post_date',
  'within_7d_cutoff',
  'matched_text',
  'verified_at',
] as const

type InitGrandPrizeCsvColumn = (typeof INIT_GRAND_PRIZE_CSV_COLUMNS)[number]

export interface InitGrandPrizeEntry {
  /** Stable per-upload key (row order, username, ticket ID). */
  id: string
  name: string
  username: string
  postUrl: string
  ticketUrl: string
  ticketId: string
  ticketDomain: string
  postDate: string
  withinCutoff: boolean
  matchedText: string
  verifiedAt: string
}

export interface InitGrandPrizeEntriesParseResult {
  /** Rows eligible for the wheel, in CSV order. */
  entries: InitGrandPrizeEntry[]
  /** Rows whose `within_7d_cutoff` column is explicitly false. */
  excludedOutsideCutoff: number
  /** Rows with neither a name nor a username. */
  skippedIncomplete: number
  /** Data rows seen, excluding the header and blank lines. */
  totalRows: number
}

export type InitGrandPrizeCsvErrorCode = 'empty' | 'missing-columns' | 'unterminated-quote'

const CSV_ERROR_MESSAGES: Record<Exclude<InitGrandPrizeCsvErrorCode, 'missing-columns'>, string> = {
  empty: 'The CSV file is empty.',
  'unterminated-quote': 'The CSV has an unterminated quoted value.',
}

export class InitGrandPrizeCsvError extends Error {
  readonly code: InitGrandPrizeCsvErrorCode
  readonly missingColumns: string[]

  constructor(code: InitGrandPrizeCsvErrorCode, missingColumns: string[] = []) {
    super(
      code === 'missing-columns'
        ? `Missing required CSV columns: ${missingColumns.join(', ')}`
        : CSV_ERROR_MESSAGES[code],
    )
    this.name = 'InitGrandPrizeCsvError'
    this.code = code
    this.missingColumns = missingColumns
  }
}

/**
 * RFC 4180 style: quoted cells, doubled quotes, CRLF or LF line endings.
 * Throws on an unterminated quote rather than flushing a merged, misaligned row.
 */
export function parseCsvRows(text: string): string[][] {
  const rows: string[][] = []
  let row: string[] = []
  let cell = ''
  let inQuotes = false

  const source = text.charCodeAt(0) === 0xfeff ? text.slice(1) : text

  for (let index = 0; index < source.length; index += 1) {
    const char = source[index]

    if (inQuotes) {
      if (char === '"') {
        if (source[index + 1] === '"') {
          cell += '"'
          index += 1
        } else {
          inQuotes = false
        }
      } else {
        cell += char
      }
      continue
    }

    if (char === '"') {
      inQuotes = true
      continue
    }

    if (char === ',') {
      row.push(cell)
      cell = ''
      continue
    }

    if (char === '\r') {
      if (source[index + 1] === '\n') index += 1
      row.push(cell)
      rows.push(row)
      row = []
      cell = ''
      continue
    }

    if (char === '\n') {
      row.push(cell)
      rows.push(row)
      row = []
      cell = ''
      continue
    }

    cell += char
  }

  if (inQuotes) throw new InitGrandPrizeCsvError('unterminated-quote')

  if (cell.length > 0 || row.length > 0) {
    row.push(cell)
    rows.push(row)
  }

  return rows.filter((cells) => cells.some((value) => value.trim().length > 0))
}

function normalizeHeader(value: string): string {
  return value.trim().toLowerCase().replace(/\s+/g, '_')
}

/** Only an explicit negative excludes a row; blank or unknown values keep it. */
export function parseCutoffFlag(value: string): boolean {
  const normalized = value.trim().toLowerCase()
  return !['false', '0', 'no', 'n', 'f'].includes(normalized)
}

function normalizeUsername(value: string): string {
  return value.trim().replace(/^@+/, '')
}

export function parseInitGrandPrizeEntries(text: string): InitGrandPrizeEntriesParseResult {
  const rows = parseCsvRows(text)
  if (rows.length === 0) throw new InitGrandPrizeCsvError('empty')

  const [headerRow, ...dataRows] = rows
  const headers = headerRow.map(normalizeHeader)
  const columnIndex = new Map<InitGrandPrizeCsvColumn, number>()

  for (const column of INIT_GRAND_PRIZE_CSV_COLUMNS) {
    const index = headers.indexOf(column)
    if (index !== -1) columnIndex.set(column, index)
  }

  const missingColumns = INIT_GRAND_PRIZE_CSV_COLUMNS.filter(
    (column) => !columnIndex.has(column),
  )
  if (missingColumns.length > 0) {
    throw new InitGrandPrizeCsvError('missing-columns', missingColumns)
  }

  const read = (cells: string[], column: InitGrandPrizeCsvColumn): string =>
    (cells[columnIndex.get(column) ?? -1] ?? '').trim()

  const entries: InitGrandPrizeEntry[] = []
  let excludedOutsideCutoff = 0
  let skippedIncomplete = 0

  dataRows.forEach((cells, rowIndex) => {
    const name = read(cells, 'name')
    const username = normalizeUsername(read(cells, 'username'))

    if (!name && !username) {
      skippedIncomplete += 1
      return
    }

    const withinCutoff = parseCutoffFlag(read(cells, 'within_7d_cutoff'))
    if (!withinCutoff) {
      excludedOutsideCutoff += 1
      return
    }

    const ticketId = read(cells, 'ticket_id')

    entries.push({
      id: `${rowIndex}:${username || name}:${ticketId}`,
      name: name || username,
      username,
      postUrl: read(cells, 'post_url'),
      ticketUrl: read(cells, 'ticket_url'),
      ticketId,
      ticketDomain: read(cells, 'ticket_domain'),
      postDate: read(cells, 'post_date'),
      withinCutoff,
      matchedText: read(cells, 'matched_text'),
      verifiedAt: read(cells, 'verified_at'),
    })
  })

  return {
    entries,
    excludedOutsideCutoff,
    skippedIncomplete,
    totalRows: dataRows.length,
  }
}
