/**
 * A small, dependency-free PDF writer: pages, vector shapes, SVG-style paths
 * and text in embedded TrueType/OpenType fonts (the brand fonts), with each
 * font's own advance widths so text aligns and truncates precisely.
 *
 * Coordinates are top-left based (like the DOM); the writer flips them to
 * PDF's bottom-left space. Text is encoded as WinAnsi (simple fonts), so Latin
 * scripts render fully; characters outside it (CJK, Hebrew, emoji) fall back
 * to "?". If a brand font can't be loaded, the matching standard Helvetica is
 * used instead, so an export never fails over typography.
 */

export const A4 = { width: 595.28, height: 841.89 }

/** Text roles, mapped to fonts by the document. */
export type Font = 'regular' | 'bold' | 'heading'
export type Rgb = [number, number, number]

export function hex(color: string): Rgb {
  const value = color.replace('#', '')
  const n = parseInt(value.length === 3 ? value.replace(/./g, '$&$&') : value, 16)
  return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255]
}

/** Mix a colour toward white; stands in for opacity on a white page. */
export function tint(color: string, amount: number): Rgb {
  const [r, g, b] = hex(color)
  return [r + (1 - r) * amount, g + (1 - g) * amount, b + (1 - b) * amount]
}

// ─── WinAnsi ────────────────────────────────────────────────────────────────

/** WinAnsi codes 128–159 → Unicode (160–255 match Latin-1 directly). */
const WIN_ANSI_HIGH: Record<number, number> = {
  128: 0x20ac, 130: 0x201a, 131: 0x0192, 132: 0x201e, 133: 0x2026, 134: 0x2020,
  135: 0x2021, 136: 0x02c6, 137: 0x2030, 138: 0x0160, 139: 0x2039, 140: 0x0152,
  142: 0x017d, 145: 0x2018, 146: 0x2019, 147: 0x201c, 148: 0x201d, 149: 0x2022,
  150: 0x2013, 151: 0x2014, 152: 0x02dc, 153: 0x2122, 154: 0x0161, 155: 0x203a,
  156: 0x0153, 158: 0x017e, 159: 0x0178,
}
const UNICODE_TO_WIN_ANSI = new Map<number, number>(
  Object.entries(WIN_ANSI_HIGH).map(([code, unicode]) => [unicode, Number(code)]),
)

function winAnsiToUnicode(code: number): number | null {
  if (code >= 32 && code <= 126) return code
  if (code >= 160 && code <= 255) return code
  return WIN_ANSI_HIGH[code] ?? null
}

/** Unicode combining diacritical marks (U+0300–U+036F), via an escaped range. */
const COMBINING_MARKS = new RegExp('[\\u0300-\\u036f]', 'g')

/** Map a JS string to WinAnsi byte codes, degrading gracefully. */
function toWinAnsi(text: string): number[] {
  const codes: number[] = []
  for (const char of text) {
    const code = char.codePointAt(0)!
    if ((code >= 32 && code <= 126) || (code >= 160 && code <= 255)) {
      codes.push(code)
    } else if (UNICODE_TO_WIN_ANSI.has(code)) {
      codes.push(UNICODE_TO_WIN_ANSI.get(code)!)
    } else {
      // Strip diacritics (e.g. "ő" → "o") before giving up.
      const base = char.normalize('NFKD').replace(COMBINING_MARKS, '')
      const baseCode = base.codePointAt(0)
      codes.push(baseCode && baseCode >= 32 && baseCode <= 126 ? baseCode : 63)
    }
  }
  return codes
}

// ─── Standard-font fallback metrics ─────────────────────────────────────────

// Helvetica / Helvetica-Bold advance widths (1/1000 em) for ASCII 32..126,
// from the standard Adobe AFM metrics.
const HELVETICA = [
  278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278,
  278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584,
  584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556,
  833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278,
  278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222,
  500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500,
  500, 334, 260, 334, 584,
]
const HELVETICA_BOLD = [
  278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278,
  278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584,
  584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611,
  833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333,
  278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278,
  556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556,
  500, 389, 280, 389, 584,
]

function standardWidths(bold: boolean): number[] {
  const table = bold ? HELVETICA_BOLD : HELVETICA
  return Array.from({ length: 256 }, (_, code) => {
    if (code >= 32 && code <= 126) return table[code - 32]
    if (code === 133 || code === 151) return 1000
    return 556
  })
}

// ─── TrueType / OpenType parsing ────────────────────────────────────────────

export type EmbeddedFont = {
  /** PostScript-safe name, used as /BaseFont. */
  name: string
  data: Uint8Array
  /** OpenType with CFF outlines ('OTTO') rather than TrueType glyphs. */
  cff: boolean
  /** Advance widths in 1/1000 em, indexed by WinAnsi code 0–255. */
  widths: number[]
  ascent: number
  descent: number
  capHeight: number
  bbox: [number, number, number, number]
  italicAngle: number
}

/**
 * Read the metrics a PDF simple font needs from a TTF/OTF: unitsPerEm and
 * bbox (head), ascent/descent and hmtx count (hhea), cap height (OS/2),
 * advance widths (hmtx) and the Unicode → glyph map (cmap 4 or 12).
 */
export function parseFont(bytes: Uint8Array, name: string): EmbeddedFont {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
  const u16 = (offset: number) => view.getUint16(offset)
  const i16 = (offset: number) => view.getInt16(offset)
  const u32 = (offset: number) => view.getUint32(offset)

  const signature = u32(0)
  const cff = signature === 0x4f54544f // 'OTTO'
  if (!cff && signature !== 0x00010000 && signature !== 0x74727565) {
    throw new Error(`Unsupported font format for ${name}`)
  }

  const tables = new Map<string, number>()
  const numTables = u16(4)
  for (let i = 0; i < numTables; i++) {
    const record = 12 + i * 16
    const tag = String.fromCharCode(
      bytes[record],
      bytes[record + 1],
      bytes[record + 2],
      bytes[record + 3],
    )
    tables.set(tag, u32(record + 8))
  }
  const table = (tag: string) => {
    const offset = tables.get(tag)
    if (offset === undefined) throw new Error(`Font ${name} is missing ${tag}`)
    return offset
  }

  const head = table('head')
  const unitsPerEm = u16(head + 18)
  const scale = (value: number) => Math.round((value * 1000) / unitsPerEm)
  const bbox: [number, number, number, number] = [
    scale(i16(head + 36)),
    scale(i16(head + 38)),
    scale(i16(head + 40)),
    scale(i16(head + 42)),
  ]

  const hhea = table('hhea')
  const ascent = scale(i16(hhea + 4))
  const descent = scale(i16(hhea + 6))
  const numberOfHMetrics = u16(hhea + 34)

  let capHeight = ascent
  const os2 = tables.get('OS/2')
  if (os2 !== undefined && u16(os2) >= 2) capHeight = scale(i16(os2 + 88))

  let italicAngle = 0
  const post = tables.get('post')
  if (post !== undefined) italicAngle = view.getInt32(post + 4) / 65536

  const hmtx = table('hmtx')
  const advance = (glyph: number) =>
    u16(hmtx + Math.min(glyph, numberOfHMetrics - 1) * 4)

  // Unicode → glyph, from the best Unicode cmap subtable available.
  const cmap = table('cmap')
  let format4: number | null = null
  let format12: number | null = null
  for (let i = 0; i < u16(cmap + 2); i++) {
    const record = cmap + 4 + i * 8
    const platform = u16(record)
    const encoding = u16(record + 2)
    const subtable = cmap + u32(record + 4)
    const format = u16(subtable)
    const isUnicode = platform === 0 || (platform === 3 && (encoding === 1 || encoding === 10))
    if (!isUnicode) continue
    if (format === 4 && format4 === null) format4 = subtable
    if (format === 12 && format12 === null) format12 = subtable
  }

  const glyphFor = (unicode: number): number => {
    if (format12 !== null) {
      const groups = u32(format12 + 12)
      for (let g = 0; g < groups; g++) {
        const group = format12 + 16 + g * 12
        const start = u32(group)
        const end = u32(group + 4)
        if (unicode >= start && unicode <= end) return u32(group + 8) + (unicode - start)
      }
    }
    if (format4 !== null && unicode <= 0xffff) {
      const segX2 = u16(format4 + 6)
      const ends = format4 + 14
      const starts = ends + segX2 + 2
      const deltas = starts + segX2
      const rangeOffsets = deltas + segX2
      for (let s = 0; s < segX2; s += 2) {
        if (unicode > u16(ends + s)) continue
        const start = u16(starts + s)
        if (unicode < start) return 0
        const delta = i16(deltas + s)
        const rangeOffset = u16(rangeOffsets + s)
        if (rangeOffset === 0) return (unicode + delta) & 0xffff
        const glyph = u16(rangeOffsets + s + rangeOffset + (unicode - start) * 2)
        return glyph === 0 ? 0 : (glyph + delta) & 0xffff
      }
    }
    return 0
  }

  const widths = Array.from({ length: 256 }, (_, code) => {
    const unicode = winAnsiToUnicode(code)
    return unicode === null ? 0 : scale(advance(glyphFor(unicode)))
  })

  return {
    name: name.replace(/[^A-Za-z0-9-]/g, ''),
    data: bytes,
    cff,
    widths,
    ascent,
    descent,
    capHeight,
    bbox,
    italicAngle,
  }
}

// ─── Strings ────────────────────────────────────────────────────────────────

function pdfString(text: string): string {
  return (
    '(' +
    toWinAnsi(text)
      .map((code) => {
        if (code === 40 || code === 41 || code === 92) return '\\' + String.fromCharCode(code)
        if (code < 32 || code > 126) return '\\' + code.toString(8).padStart(3, '0')
        return String.fromCharCode(code)
      })
      .join('') +
    ')'
  )
}

/**
 * Document-info strings (title, etc.) are PDFDocEncoding, not WinAnsi, so
 * write them as UTF-16BE hex with a BOM: correct for any Unicode title.
 */
function pdfTextString(text: string): string {
  let hexString = 'FEFF'
  for (let i = 0; i < text.length; i++) {
    hexString += text.charCodeAt(i).toString(16).padStart(4, '0').toUpperCase()
  }
  return `<${hexString}>`
}

const n = (value: number) => (Math.round(value * 100) / 100).toString()

async function deflate(bytes: Uint8Array): Promise<Uint8Array | null> {
  if (typeof CompressionStream === 'undefined') return null
  return new Uint8Array(
    await new Response(
      new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate')),
    ).arrayBuffer(),
  )
}

// ─── Document ───────────────────────────────────────────────────────────────

export type TextOptions = {
  size?: number
  font?: Font
  color?: Rgb
  align?: 'left' | 'right' | 'center'
  maxWidth?: number
  /** Extra space between characters, in points (for small caps labels). */
  tracking?: number
}

const FONT_RESOURCES: Record<Font, string> = { regular: 'F1', bold: 'F2', heading: 'F3' }

export type DocumentFonts = Partial<Record<Font, EmbeddedFont>>

export class PdfDocument {
  readonly width = A4.width
  readonly height = A4.height
  private pages: string[][] = []
  private ops: string[] = []

  constructor(
    private readonly title: string,
    private readonly fonts: DocumentFonts = {},
  ) {
    this.addPage()
  }

  get pageCount() {
    return this.pages.length
  }

  addPage() {
    this.ops = []
    this.pages.push(this.ops)
  }

  /** Switch drawing to an existing page (e.g. to stamp footers at the end). */
  usePage(index: number) {
    this.ops = this.pages[index]
  }

  private widthsFor(font: Font): number[] {
    return this.fonts[font]?.widths ?? standardWidths(font !== 'regular')
  }

  measure(text: string, size: number, font: Font = 'regular', tracking = 0) {
    const widths = this.widthsFor(font)
    const codes = toWinAnsi(text)
    return (
      (codes.reduce((sum, code) => sum + (widths[code] || 556), 0) * size) / 1000 +
      tracking * Math.max(0, codes.length - 1)
    )
  }

  /** Truncate with an ellipsis so the text fits `maxWidth`. */
  fit(text: string, size: number, maxWidth: number, font: Font = 'regular') {
    if (this.measure(text, size, font) <= maxWidth) return text
    const chars = Array.from(text)
    while (chars.length > 0 && this.measure(chars.join('') + '…', size, font) > maxWidth) {
      chars.pop()
    }
    return chars.length > 0 ? chars.join('').trimEnd() + '…' : ''
  }

  private y(value: number) {
    return this.height - value
  }

  private fillColor([r, g, b]: Rgb) {
    this.ops.push(`${n(r)} ${n(g)} ${n(b)} rg`)
  }

  private strokeColor([r, g, b]: Rgb) {
    this.ops.push(`${n(r)} ${n(g)} ${n(b)} RG`)
  }

  rect(
    x: number,
    y: number,
    w: number,
    h: number,
    options: {
      fill?: Rgb
      stroke?: Rgb
      lineWidth?: number
      radius?: number
      /**
       * Which sides get the radius (default both). Segments of a bar round
       * only their outer end: the first its left, the last its right.
       */
      roundLeft?: boolean
      roundRight?: boolean
    },
  ) {
    const {
      fill,
      stroke,
      lineWidth = 0.75,
      radius = 0,
      roundLeft = true,
      roundRight = true,
    } = options
    this.ops.push('q')
    if (fill) this.fillColor(fill)
    if (stroke) {
      this.strokeColor(stroke)
      this.ops.push(`${n(lineWidth)} w`)
    }
    const r = Math.min(radius, w / 2, h / 2)
    const rl = roundLeft ? r : 0
    const rr = roundRight ? r : 0
    if (rl > 0 || rr > 0) {
      // Rounded corners as cubic Béziers (k ≈ 0.5523 for a quarter circle);
      // a zero radius collapses its corner's curve to a square corner.
      const kl = rl * 0.5523
      const kr = rr * 0.5523
      const left = x, right = x + w, top = this.y(y), bottom = this.y(y + h)
      this.ops.push(
        `${n(left + rl)} ${n(top)} m`,
        `${n(right - rr)} ${n(top)} l`,
        `${n(right - rr + kr)} ${n(top)} ${n(right)} ${n(top - rr + kr)} ${n(right)} ${n(top - rr)} c`,
        `${n(right)} ${n(bottom + rr)} l`,
        `${n(right)} ${n(bottom + rr - kr)} ${n(right - rr + kr)} ${n(bottom)} ${n(right - rr)} ${n(bottom)} c`,
        `${n(left + rl)} ${n(bottom)} l`,
        `${n(left + rl - kl)} ${n(bottom)} ${n(left)} ${n(bottom + rl - kl)} ${n(left)} ${n(bottom + rl)} c`,
        `${n(left)} ${n(top - rl)} l`,
        `${n(left)} ${n(top - rl + kl)} ${n(left + rl - kl)} ${n(top)} ${n(left + rl)} ${n(top)} c`,
        'h',
      )
    } else {
      this.ops.push(`${n(x)} ${n(this.y(y + h))} ${n(w)} ${n(h)} re`)
    }
    this.ops.push(fill && stroke ? 'B' : fill ? 'f' : 'S', 'Q')
  }

  line(
    x1: number,
    y1: number,
    x2: number,
    y2: number,
    options: { color: Rgb; width?: number; dash?: [number, number] },
  ) {
    this.polyline(
      [
        [x1, y1],
        [x2, y2],
      ],
      options,
    )
  }

  polyline(
    points: [number, number][],
    options: { color: Rgb; width?: number; dash?: [number, number] },
  ) {
    if (points.length < 2) return
    this.ops.push('q')
    this.strokeColor(options.color)
    this.ops.push(`${n(options.width ?? 1)} w`, '1 J', '1 j')
    if (options.dash) this.ops.push(`[${options.dash.map(n).join(' ')}] 0 d`)
    this.ops.push(
      points
        .map(([px, py], index) => `${n(px)} ${n(this.y(py))} ${index === 0 ? 'm' : 'l'}`)
        .join(' '),
      'S',
      'Q',
    )
  }

  polygon(points: [number, number][], fill: Rgb) {
    if (points.length < 3) return
    this.ops.push('q')
    this.fillColor(fill)
    this.ops.push(
      points
        .map(([px, py], index) => `${n(px)} ${n(this.y(py))} ${index === 0 ? 'm' : 'l'}`)
        .join(' '),
      'h f',
      'Q',
    )
  }

  /**
   * Fill an SVG path (absolute M/L/H/V/C/Z only), scaled and placed with its
   * viewBox origin at (x, y).
   */
  svgPath(d: string, x: number, y: number, scale: number, fill: Rgb) {
    const tokens = d.match(/[MLHVCZ]|-?\d*\.?\d+(?:e-?\d+)?/gi) ?? []
    let i = 0
    let cx = 0
    let cy = 0
    const num = () => parseFloat(tokens[i++])
    const px = (value: number) => n(x + value * scale)
    const py = (value: number) => n(this.y(y + value * scale))
    const out: string[] = []
    let command = ''
    while (i < tokens.length) {
      if (/^[a-z]$/i.test(tokens[i])) command = tokens[i++]
      switch (command) {
        case 'M':
          cx = num(); cy = num()
          out.push(`${px(cx)} ${py(cy)} m`)
          command = 'L'
          break
        case 'L':
          cx = num(); cy = num()
          out.push(`${px(cx)} ${py(cy)} l`)
          break
        case 'H':
          cx = num()
          out.push(`${px(cx)} ${py(cy)} l`)
          break
        case 'V':
          cy = num()
          out.push(`${px(cx)} ${py(cy)} l`)
          break
        case 'C': {
          const c = [num(), num(), num(), num(), num(), num()]
          cx = c[4]; cy = c[5]
          out.push(`${px(c[0])} ${py(c[1])} ${px(c[2])} ${py(c[3])} ${px(c[4])} ${py(c[5])} c`)
          break
        }
        case 'Z':
        case 'z':
          out.push('h')
          command = ''
          break
        default:
          i++ // Unsupported command: skip rather than emit a broken path.
      }
    }
    this.ops.push('q')
    this.fillColor(fill)
    this.ops.push(out.join(' '), 'f', 'Q')
  }

  text(value: string, x: number, y: number, options: TextOptions = {}) {
    const {
      size = 10,
      font = 'regular',
      color = [0, 0, 0],
      align = 'left',
      maxWidth,
      tracking = 0,
    } = options
    const content = maxWidth ? this.fit(value, size, maxWidth, font) : value
    if (!content) return
    const width = this.measure(content, size, font, tracking)
    const left = align === 'right' ? x - width : align === 'center' ? x - width / 2 : x
    this.ops.push('BT')
    this.fillColor(color)
    // `y` is the text baseline in top-left coordinates.
    this.ops.push(
      `/${FONT_RESOURCES[font]} ${n(size)} Tf`,
      ...(tracking ? [`${n(tracking)} Tc`] : []),
      `${n(left)} ${n(this.y(y))} Td`,
      `${pdfString(content)} Tj`,
      ...(tracking ? ['0 Tc'] : []),
      'ET',
    )
  }

  /** Assemble the file. Content and font streams are deflated when possible. */
  async toBlob(): Promise<Blob> {
    const encoder = new TextEncoder()
    const chunks: Uint8Array[] = []
    const offsets: number[] = []
    let length = 0
    let nextId = 1
    const allocate = () => nextId++
    const push = (part: string | Uint8Array) => {
      const bytes = typeof part === 'string' ? encoder.encode(part) : part
      chunks.push(bytes)
      length += bytes.length
    }
    const object = (id: number, body: string) => {
      offsets[id] = length
      push(`${id} 0 obj\n${body}\nendobj\n`)
    }
    const stream = async (id: number, data: Uint8Array, extra = '') => {
      const deflated = await deflate(data)
      offsets[id] = length
      const bytes = deflated ?? data
      push(
        `${id} 0 obj\n<< /Length ${bytes.length}${deflated ? ' /Filter /FlateDecode' : ''}${extra} >>\nstream\n`,
      )
      push(bytes)
      push('\nendstream\nendobj\n')
    }

    const catalogId = allocate()
    const pagesId = allocate()
    const infoId = allocate()
    const fontIds = {} as Record<Font, number>
    for (const font of Object.keys(FONT_RESOURCES) as Font[]) fontIds[font] = allocate()
    const pageIds = this.pages.map(() => [allocate(), allocate()] as const)

    push('%PDF-1.6\n%\xE2\xE3\xCF\xD3\n')
    object(catalogId, `<< /Type /Catalog /Pages ${pagesId} 0 R >>`)
    object(
      pagesId,
      `<< /Type /Pages /Kids [${pageIds.map(([pageId]) => `${pageId} 0 R`).join(' ')}] /Count ${pageIds.length} >>`,
    )
    const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14)
    object(
      infoId,
      `<< /Title ${pdfTextString(this.title)} /Producer (Appwrite) /CreationDate (D:${stamp}Z) >>`,
    )

    // Fonts: embedded brand fonts, or the standard Helvetica equivalent.
    for (const font of Object.keys(FONT_RESOURCES) as Font[]) {
      const embedded = this.fonts[font]
      if (!embedded) {
        object(
          fontIds[font],
          `<< /Type /Font /Subtype /Type1 /BaseFont /${font === 'regular' ? 'Helvetica' : 'Helvetica-Bold'} /Encoding /WinAnsiEncoding >>`,
        )
        continue
      }
      const descriptorId = allocate()
      const fileId = allocate()
      // CFF-flavoured OpenType embeds as a Type1-subtype font (FontFile3);
      // TrueType outlines as a TrueType font (FontFile2).
      object(
        fontIds[font],
        `<< /Type /Font /Subtype /${embedded.cff ? 'Type1' : 'TrueType'} /BaseFont /${embedded.name} /FirstChar 32 /LastChar 255 /Widths [${embedded.widths.slice(32, 256).join(' ')}] /FontDescriptor ${descriptorId} 0 R /Encoding /WinAnsiEncoding >>`,
      )
      object(
        descriptorId,
        `<< /Type /FontDescriptor /FontName /${embedded.name} /Flags 32 /FontBBox [${embedded.bbox.join(' ')}] /ItalicAngle ${n(embedded.italicAngle)} /Ascent ${embedded.ascent} /Descent ${embedded.descent} /CapHeight ${embedded.capHeight} /StemV 80 ${embedded.cff ? '/FontFile3' : '/FontFile2'} ${fileId} 0 R >>`,
      )
      await stream(
        fileId,
        embedded.data,
        embedded.cff ? ' /Subtype /OpenType' : ` /Length1 ${embedded.data.length}`,
      )
    }

    const fontResources = (Object.keys(FONT_RESOURCES) as Font[])
      .map((font) => `/${FONT_RESOURCES[font]} ${fontIds[font]} 0 R`)
      .join(' ')
    for (let index = 0; index < this.pages.length; index++) {
      const [pageId, contentId] = pageIds[index]
      object(
        pageId,
        `<< /Type /Page /Parent ${pagesId} 0 R /MediaBox [0 0 ${n(this.width)} ${n(this.height)}] /Resources << /Font << ${fontResources} >> >> /Contents ${contentId} 0 R >>`,
      )
      await stream(contentId, encoder.encode(this.pages[index].join('\n')))
    }

    const xref = length
    push(`xref\n0 ${nextId}\n0000000000 65535 f \n`)
    for (let id = 1; id < nextId; id++) {
      push(`${String(offsets[id] ?? 0).padStart(10, '0')} 00000 n \n`)
    }
    push(
      `trailer\n<< /Size ${nextId} /Root ${catalogId} 0 R /Info ${infoId} 0 R >>\nstartxref\n${xref}\n%%EOF\n`,
    )

    return new Blob(chunks, { type: 'application/pdf' })
  }
}
