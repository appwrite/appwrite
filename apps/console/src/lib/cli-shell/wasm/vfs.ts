/**
 * In-memory filesystem for the browser build of the Appwrite CLI.
 *
 * Replaces almostnode's VFS, which existed to run a JavaScript CLI under a Node
 * emulator. The Go CLI needs far less: it reads `~/.appwrite/prefs.json` and
 * `appwrite.config.json`, writes them back, and lists a directory or two. That
 * is a few hundred lines rather than a Node runtime.
 *
 * Two consumers with different shapes:
 *
 *   - This module's sync API (`readFileSync`, `statSync`, ...), which is what
 *     the console-session and bootstrap code already calls.
 *   - The callback API in fs-bridge.ts, which is what Go's wasm_exec.js expects.
 *
 * Both sit on the same node table, so a file the console writes is the file the
 * CLI reads.
 */

export type VfsFileEntry = {
  path: string
  type: 'file'
  /** Base64 so a snapshot survives structuredClone and JSON alike. */
  contents: string
}

export type VfsDirectoryEntry = {
  path: string
  type: 'directory'
}

export type VfsEntry = VfsFileEntry | VfsDirectoryEntry

export type VfsSnapshot = {
  files: VfsEntry[]
}

export type VfsStats = {
  isFile(): boolean
  isDirectory(): boolean
  size: number
  mtimeMs: number
  mode: number
}

type FileNode = {
  type: 'file'
  data: Uint8Array
  mtimeMs: number
}

type DirectoryNode = {
  type: 'directory'
  mtimeMs: number
}

type Node = FileNode | DirectoryNode

/** Error carrying the `code` Go's syscall layer maps to an errno. */
export class VfsError extends Error {
  constructor(
    public readonly code: string,
    message: string,
  ) {
    super(message)
    this.name = 'VfsError'
  }
}

const encoder = new TextEncoder()
const decoder = new TextDecoder()

export function normalizePath(input: string): string {
  const absolute = input.startsWith('/') ? input : `/${input}`
  const segments: string[] = []

  for (const segment of absolute.split('/')) {
    if (!segment || segment === '.') continue
    if (segment === '..') {
      segments.pop()
      continue
    }
    segments.push(segment)
  }

  return `/${segments.join('/')}`
}

export function dirname(path: string): string {
  const normalized = normalizePath(path)
  const index = normalized.lastIndexOf('/')
  return index <= 0 ? '/' : normalized.slice(0, index)
}

function toBase64(bytes: Uint8Array): string {
  let binary = ''
  for (let index = 0; index < bytes.length; index++) {
    binary += String.fromCharCode(bytes[index]!)
  }
  return btoa(binary)
}

function fromBase64(value: string): Uint8Array {
  const binary = atob(value)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index++) {
    bytes[index] = binary.charCodeAt(index)
  }
  return bytes
}

export class Vfs {
  private readonly nodes = new Map<string, Node>()

  constructor() {
    this.nodes.set('/', { type: 'directory', mtimeMs: Date.now() })
  }

  private node(path: string): Node | undefined {
    return this.nodes.get(normalizePath(path))
  }

  private requireNode(path: string): Node {
    const found = this.node(path)
    if (!found) {
      throw new VfsError('ENOENT', `no such file or directory: ${path}`)
    }
    return found
  }

  existsSync(path: string): boolean {
    return this.nodes.has(normalizePath(path))
  }

  statSync(path: string): VfsStats {
    const node = this.requireNode(path)
    const isDirectory = node.type === 'directory'

    return {
      isFile: () => !isDirectory,
      isDirectory: () => isDirectory,
      size: isDirectory ? 0 : node.data.length,
      mtimeMs: node.mtimeMs,
      // Go's os.FileMode wants the directory bit and something sane for perms.
      mode: isDirectory ? 0o040755 : 0o100644,
    }
  }

  mkdirSync(path: string, options?: { recursive?: boolean }): void {
    const normalized = normalizePath(path)
    if (normalized === '/') return

    const existing = this.nodes.get(normalized)
    if (existing) {
      if (existing.type === 'directory') {
        if (options?.recursive) return
        throw new VfsError('EEXIST', `file already exists: ${path}`)
      }
      throw new VfsError('ENOTDIR', `not a directory: ${path}`)
    }

    const parent = dirname(normalized)
    if (!this.nodes.has(parent)) {
      if (!options?.recursive) {
        throw new VfsError('ENOENT', `no such file or directory: ${parent}`)
      }
      this.mkdirSync(parent, options)
    }

    this.nodes.set(normalized, { type: 'directory', mtimeMs: Date.now() })
  }

  readFileSync(path: string, encoding: 'utf8'): string
  readFileSync(path: string): Uint8Array
  readFileSync(path: string, encoding?: 'utf8'): string | Uint8Array {
    const node = this.requireNode(path)
    if (node.type === 'directory') {
      throw new VfsError('EISDIR', `is a directory: ${path}`)
    }

    return encoding === 'utf8' ? decoder.decode(node.data) : node.data
  }

  writeFileSync(path: string, data: string | Uint8Array): void {
    const normalized = normalizePath(path)
    const parent = dirname(normalized)

    if (!this.nodes.has(parent)) {
      throw new VfsError('ENOENT', `no such file or directory: ${parent}`)
    }

    const existing = this.nodes.get(normalized)
    if (existing?.type === 'directory') {
      throw new VfsError('EISDIR', `is a directory: ${path}`)
    }

    this.nodes.set(normalized, {
      type: 'file',
      data: typeof data === 'string' ? encoder.encode(data) : data,
      mtimeMs: Date.now(),
    })
  }

  readdirSync(path: string): string[] {
    const node = this.requireNode(path)
    if (node.type !== 'directory') {
      throw new VfsError('ENOTDIR', `not a directory: ${path}`)
    }

    const normalized = normalizePath(path)
    const prefix = normalized === '/' ? '/' : `${normalized}/`
    const names: string[] = []

    for (const candidate of this.nodes.keys()) {
      if (candidate === normalized || !candidate.startsWith(prefix)) continue
      const remainder = candidate.slice(prefix.length)
      if (remainder.includes('/')) continue
      names.push(remainder)
    }

    return names.sort()
  }

  unlinkSync(path: string): void {
    const node = this.requireNode(path)
    if (node.type === 'directory') {
      throw new VfsError('EISDIR', `is a directory: ${path}`)
    }
    this.nodes.delete(normalizePath(path))
  }

  rmdirSync(path: string): void {
    const node = this.requireNode(path)
    if (node.type !== 'directory') {
      throw new VfsError('ENOTDIR', `not a directory: ${path}`)
    }
    if (this.readdirSync(path).length > 0) {
      throw new VfsError('ENOTEMPTY', `directory not empty: ${path}`)
    }
    this.nodes.delete(normalizePath(path))
  }

  renameSync(from: string, to: string): void {
    const source = normalizePath(from)
    const target = normalizePath(to)
    const node = this.requireNode(source)

    if (node.type === 'directory') {
      // Rename is used by the CLI's atomic config write, which only ever moves
      // files. A directory rename would have to walk children; refuse instead
      // of half-doing it.
      throw new VfsError('EISDIR', `cannot rename a directory: ${from}`)
    }

    if (!this.nodes.has(dirname(target))) {
      throw new VfsError(
        'ENOENT',
        `no such file or directory: ${dirname(target)}`,
      )
    }

    this.nodes.delete(source)
    this.nodes.set(target, node)
  }

  truncateSync(path: string, length: number): void {
    const node = this.requireNode(path)
    if (node.type === 'directory') {
      throw new VfsError('EISDIR', `is a directory: ${path}`)
    }

    const resized = new Uint8Array(length)
    resized.set(node.data.subarray(0, Math.min(length, node.data.length)))
    node.data = resized
    node.mtimeMs = Date.now()
  }

  /** Replace a file's bytes in place, keeping the node identity for open handles. */
  setFileData(path: string, data: Uint8Array): void {
    const node = this.requireNode(path)
    if (node.type === 'directory') {
      throw new VfsError('EISDIR', `is a directory: ${path}`)
    }
    node.data = data
    node.mtimeMs = Date.now()
  }

  toSnapshot(): VfsSnapshot {
    const files: VfsEntry[] = []

    for (const [path, node] of this.nodes) {
      if (node.type === 'directory') {
        files.push({ path, type: 'directory' })
        continue
      }
      files.push({ path, type: 'file', contents: toBase64(node.data) })
    }

    return { files }
  }

  /** Snapshot only the subtree at `prefix`, for persisting one directory. */
  toSnapshotOf(prefix: string): VfsSnapshot {
    const normalized = normalizePath(prefix)
    const scope = normalized === '/' ? '/' : `${normalized}/`

    return {
      files: this.toSnapshot().files.filter(
        (entry) => entry.path === normalized || entry.path.startsWith(scope),
      ),
    }
  }

  applySnapshot(snapshot: VfsSnapshot): void {
    // Shallowest first, so a file never lands before its parent directory.
    const ordered = [...snapshot.files].sort(
      (left, right) =>
        left.path.split('/').length - right.path.split('/').length,
    )

    for (const entry of ordered) {
      if (entry.path === '/') continue

      if (entry.type === 'directory') {
        this.mkdirSync(entry.path, { recursive: true })
        continue
      }

      this.mkdirSync(dirname(entry.path), { recursive: true })
      this.writeFileSync(entry.path, fromBase64(entry.contents))
    }
  }
}
