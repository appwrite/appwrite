/**
 * Minimal `.tar.gz` writer with no dependencies.
 *
 * Tar is the POSIX ustar format (512-byte header + content padded to 512,
 * terminated by two zero blocks). Gzip uses the browser's native
 * `CompressionStream`, the same API the Functions editor already relies on.
 */

export type ArchiveEntry = { path: string; content: string | Uint8Array }

const BLOCK = 512
const encoder = new TextEncoder()

function writeString(
  header: Uint8Array,
  offset: number,
  length: number,
  value: string,
) {
  const bytes = encoder.encode(value)
  header.set(bytes.subarray(0, length), offset)
}

/** Octal, zero-padded, NUL-terminated, as ustar numeric fields expect. */
function writeOctal(
  header: Uint8Array,
  offset: number,
  length: number,
  value: number,
) {
  writeString(
    header,
    offset,
    length,
    value.toString(8).padStart(length - 1, '0') + '\0',
  )
}

/** Split a path into ustar prefix (≤155 bytes) + name (≤100 bytes), if possible. */
function splitUstarPath(path: string): { name: string; prefix: string } | null {
  if (encoder.encode(path).length <= 100) return { name: path, prefix: '' }
  for (let cut = path.indexOf('/'); cut > 0; cut = path.indexOf('/', cut + 1)) {
    const prefix = path.slice(0, cut)
    const name = path.slice(cut + 1)
    if (encoder.encode(prefix).length <= 155 && encoder.encode(name).length <= 100) {
      return { name, prefix }
    }
  }
  return null
}

function tarHeader(
  path: string,
  size: number,
  mtime: number,
  typeflag: '0' | 'x' = '0',
): Uint8Array {
  const header = new Uint8Array(BLOCK)
  // Paths that don't fit are carried by a preceding PAX header (see buildTar);
  // the ustar field then just holds a truncated fallback name.
  const split = splitUstarPath(path) ?? { name: path.slice(-100), prefix: '' }
  writeString(header, 0, 100, split.name)
  writeOctal(header, 100, 8, 0o644) // mode
  writeOctal(header, 108, 8, 0) // uid
  writeOctal(header, 116, 8, 0) // gid
  writeOctal(header, 124, 12, size)
  writeOctal(header, 136, 12, mtime)
  // Checksum is computed with its own field filled with spaces.
  header.fill(0x20, 148, 156)
  header[156] = typeflag.charCodeAt(0) // '0' regular file, 'x' PAX header
  const prefix = split.prefix
  writeString(header, 257, 6, 'ustar\0')
  writeString(header, 263, 2, '00')
  writeString(header, 345, 155, prefix)

  let checksum = 0
  for (const byte of header) checksum += byte
  writeString(header, 148, 8, checksum.toString(8).padStart(6, '0') + '\0 ')
  return header
}

/**
 * PAX record `"<len> path=<value>\n"`, where <len> counts the whole record,
 * including its own digits.
 */
function paxPathRecord(path: string): Uint8Array {
  const body = ` path=${path}\n`
  const bodyLength = encoder.encode(body).length
  let length = bodyLength + String(bodyLength).length
  if (String(length).length !== String(bodyLength).length) {
    length = bodyLength + String(length).length
  }
  return encoder.encode(`${length}${body}`)
}

function pushPadded(parts: Uint8Array[], data: Uint8Array) {
  parts.push(data)
  const padding = (BLOCK - (data.length % BLOCK)) % BLOCK
  if (padding) parts.push(new Uint8Array(padding))
}

export function buildTar(entries: readonly ArchiveEntry[]): Uint8Array {
  const mtime = Math.floor(Date.now() / 1000)
  const parts: Uint8Array[] = []
  for (const entry of entries) {
    const data =
      typeof entry.content === 'string'
        ? encoder.encode(entry.content)
        : entry.content
    // Long paths: a PAX extended header carries the full path.
    if (!splitUstarPath(entry.path)) {
      const record = paxPathRecord(entry.path)
      parts.push(tarHeader('PaxHeader/entry', record.length, mtime, 'x'))
      pushPadded(parts, record)
    }
    parts.push(tarHeader(entry.path, data.length, mtime))
    pushPadded(parts, data)
  }
  parts.push(new Uint8Array(BLOCK * 2))

  const total = parts.reduce((sum, part) => sum + part.length, 0)
  const tar = new Uint8Array(total)
  let offset = 0
  for (const part of parts) {
    tar.set(part, offset)
    offset += part.length
  }
  return tar
}

export function isGzipSupported(): boolean {
  return typeof CompressionStream !== 'undefined'
}

export async function gzip(bytes: Uint8Array): Promise<Blob> {
  const stream = new Blob([bytes])
    .stream()
    .pipeThrough(new CompressionStream('gzip'))
  return await new Response(stream).blob()
}

export async function buildTarGz(
  entries: readonly ArchiveEntry[],
): Promise<Blob> {
  const blob = await gzip(buildTar(entries))
  return new Blob([blob], { type: 'application/gzip' })
}

export function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  // Give the browser a tick to start the download before revoking.
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
