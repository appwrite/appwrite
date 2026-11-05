/**
 * The `fs`, `process` and `path` globals Go's wasm_exec.js expects, backed by
 * our own Vfs.
 *
 * Go's js/wasm syscall layer talks to a Node-shaped callback API
 * (`open(path, flags, mode, cb)`, `read(fd, buffer, offset, length, position, cb)`,
 * ...). wasm_exec.js installs stubs that answer ENOSYS to all of it, and only
 * installs them when the globals are absent -- so defining ours first is the
 * documented way to give the CLI a real filesystem.
 *
 * These are installed for the duration of one run and removed afterwards, in the
 * same spirit as amd-globals.ts: a page-wide `globalThis.process` is a trap for
 * any bundled library that feature-detects Node, and this one exists to serve a
 * single wasm instance.
 */

import { dirname, normalizePath, Vfs, VfsError } from './vfs'

/**
 * Flag bits handed to `open`.
 *
 * Go reads these back off `fs.constants` and composes them itself
 * (syscall/fs_js.go), so the only requirement is that both sides agree. These
 * are the POSIX values, which keeps them recognisable in a debugger.
 */
const O_WRONLY = 0o1
const O_RDWR = 0o2
const O_CREAT = 0o100
const O_EXCL = 0o200
const O_TRUNC = 0o1000
const O_APPEND = 0o2000
const O_DIRECTORY = 0o200000

export type StdioWriter = (chunk: string) => void

type OpenFile = {
  path: string
  /** Byte offset for positionless reads and writes. */
  cursor: number
  append: boolean
}

type Callback = (error: unknown, ...values: unknown[]) => void

const decoder = new TextDecoder()

/** Node-shaped error: Go maps `code` to an errno and anything else to EINVAL. */
function fsError(code: string, message: string): Error & { code: string } {
  const error = new Error(message) as Error & { code: string }
  error.code = code
  return error
}

function toFsError(error: unknown): Error & { code: string } {
  if (error instanceof VfsError) {
    return fsError(error.code, error.message)
  }
  return fsError('EIO', error instanceof Error ? error.message : String(error))
}

/**
 * Stat object with every field Go reads.
 *
 * All of them, even the meaningless ones: Go calls `Value.Int()` on each in
 * turn, and that panics on undefined rather than defaulting to zero. A missing
 * `blksize` is an unrecoverable wasm trap, not a wrong number.
 */
function statObject(vfs: Vfs, path: string) {
  const stats = vfs.statSync(path)

  return {
    dev: 0,
    ino: 0,
    mode: stats.mode,
    nlink: 1,
    uid: 0,
    gid: 0,
    rdev: 0,
    size: stats.size,
    blksize: 4096,
    blocks: Math.ceil(stats.size / 512),
    atimeMs: stats.mtimeMs,
    mtimeMs: stats.mtimeMs,
    ctimeMs: stats.mtimeMs,
    birthtimeMs: stats.mtimeMs,
    isDirectory: () => stats.isDirectory(),
    isFile: () => stats.isFile(),
    isSymbolicLink: () => false,
  }
}

export type FsBridgeOptions = {
  vfs: Vfs
  cwd: string
  onStdout: StdioWriter
  onStderr: StdioWriter
}

export type FsBridge = {
  fs: Record<string, unknown>
  process: Record<string, unknown>
  path: Record<string, unknown>
}

export function createFsBridge(options: FsBridgeOptions): FsBridge {
  const { vfs } = options
  let cwd = normalizePath(options.cwd)

  const open = new Map<number, OpenFile>()
  // 0, 1 and 2 are spoken for; Go never opens them, it only writes to 1 and 2.
  let nextDescriptor = 3

  const writeStdio = (fd: number, buffer: Uint8Array): number => {
    const text = decoder.decode(buffer)
    if (fd === 2) {
      options.onStderr(text)
    } else {
      options.onStdout(text)
    }
    return buffer.length
  }

  const writeSync = (fd: number, buffer: Uint8Array): number => {
    if (fd === 1 || fd === 2) {
      return writeStdio(fd, buffer)
    }

    const handle = open.get(fd)
    if (!handle) {
      throw fsError('EBADF', `bad file descriptor: ${fd}`)
    }

    const existing = vfs.readFileSync(handle.path)
    const at = handle.append ? existing.length : handle.cursor
    const end = Math.max(existing.length, at + buffer.length)
    const merged = new Uint8Array(end)
    merged.set(existing)
    merged.set(buffer, at)

    vfs.setFileData(handle.path, merged)
    handle.cursor = at + buffer.length

    return buffer.length
  }

  const fs: Record<string, unknown> = {
    constants: {
      O_WRONLY,
      O_RDWR,
      O_CREAT,
      O_TRUNC,
      O_APPEND,
      O_EXCL,
      O_DIRECTORY,
    },

    // Called directly by wasm_exec.js for runtime output (panics, println).
    writeSync,

    write(
      fd: number,
      buffer: Uint8Array,
      offset: number,
      length: number,
      position: number | null,
      callback: Callback,
    ) {
      try {
        const slice = buffer.subarray(offset, offset + length)

        if (position !== null && fd > 2) {
          const handle = open.get(fd)
          if (!handle) throw fsError('EBADF', `bad file descriptor: ${fd}`)
          handle.cursor = position
        }

        callback(null, writeSync(fd, slice))
      } catch (error) {
        callback(toFsError(error))
      }
    },

    open(path: string, flags: number, _mode: number, callback: Callback) {
      try {
        const resolved = resolve(path)
        const exists = vfs.existsSync(resolved)

        if (!exists) {
          if (!(flags & O_CREAT)) {
            throw fsError('ENOENT', `no such file or directory: ${path}`)
          }
          if (!vfs.existsSync(dirname(resolved))) {
            throw fsError(
              'ENOENT',
              `no such file or directory: ${dirname(resolved)}`,
            )
          }
          vfs.writeFileSync(resolved, new Uint8Array(0))
        } else if (flags & O_EXCL && flags & O_CREAT) {
          throw fsError('EEXIST', `file already exists: ${path}`)
        }

        const directory = vfs.statSync(resolved).isDirectory()
        if (!directory && flags & O_TRUNC) {
          vfs.setFileData(resolved, new Uint8Array(0))
        }

        const descriptor = nextDescriptor++
        open.set(descriptor, {
          path: resolved,
          cursor: 0,
          append: Boolean(flags & O_APPEND),
        })

        callback(null, descriptor)
      } catch (error) {
        callback(toFsError(error))
      }
    },

    close(fd: number, callback: Callback) {
      open.delete(fd)
      callback(null)
    },

    read(
      fd: number,
      buffer: Uint8Array,
      offset: number,
      length: number,
      position: number | null,
      callback: Callback,
    ) {
      try {
        const handle = open.get(fd)
        if (!handle) throw fsError('EBADF', `bad file descriptor: ${fd}`)

        const data = vfs.readFileSync(handle.path)
        const at = position ?? handle.cursor
        const slice = data.subarray(at, Math.min(at + length, data.length))
        buffer.set(slice, offset)

        if (position === null) {
          handle.cursor = at + slice.length
        }

        callback(null, slice.length)
      } catch (error) {
        callback(toFsError(error))
      }
    },

    fstat(fd: number, callback: Callback) {
      try {
        const handle = open.get(fd)
        if (!handle) throw fsError('EBADF', `bad file descriptor: ${fd}`)
        callback(null, statObject(vfs, handle.path))
      } catch (error) {
        callback(toFsError(error))
      }
    },

    stat(path: string, callback: Callback) {
      try {
        callback(null, statObject(vfs, resolve(path)))
      } catch (error) {
        callback(toFsError(error))
      }
    },

    // No symlinks in this filesystem, so lstat is stat.
    lstat(path: string, callback: Callback) {
      ;(fs.stat as (path: string, callback: Callback) => void)(path, callback)
    },

    mkdir(path: string, _perm: number, callback: Callback) {
      try {
        vfs.mkdirSync(resolve(path))
        callback(null)
      } catch (error) {
        callback(toFsError(error))
      }
    },

    readdir(path: string, callback: Callback) {
      try {
        callback(null, vfs.readdirSync(resolve(path)))
      } catch (error) {
        callback(toFsError(error))
      }
    },

    unlink(path: string, callback: Callback) {
      try {
        vfs.unlinkSync(resolve(path))
        callback(null)
      } catch (error) {
        callback(toFsError(error))
      }
    },

    rmdir(path: string, callback: Callback) {
      try {
        vfs.rmdirSync(resolve(path))
        callback(null)
      } catch (error) {
        callback(toFsError(error))
      }
    },

    rename(from: string, to: string, callback: Callback) {
      try {
        vfs.renameSync(resolve(from), resolve(to))
        callback(null)
      } catch (error) {
        callback(toFsError(error))
      }
    },

    truncate(path: string, length: number, callback: Callback) {
      try {
        vfs.truncateSync(resolve(path), length)
        callback(null)
      } catch (error) {
        callback(toFsError(error))
      }
    },

    ftruncate(fd: number, length: number, callback: Callback) {
      try {
        const handle = open.get(fd)
        if (!handle) throw fsError('EBADF', `bad file descriptor: ${fd}`)
        vfs.truncateSync(handle.path, length)
        callback(null)
      } catch (error) {
        callback(toFsError(error))
      }
    },

    fsync(_fd: number, callback: Callback) {
      callback(null)
    },

    // Permissions, ownership and timestamps have no meaning here, but the CLI
    // does chmod its preferences file to 0600. Answering ENOSYS would turn a
    // successful write into a failed one, so these succeed and do nothing.
    chmod(_path: string, _mode: number, callback: Callback) {
      callback(null)
    },
    fchmod(_fd: number, _mode: number, callback: Callback) {
      callback(null)
    },
    chown(_path: string, _uid: number, _gid: number, callback: Callback) {
      callback(null)
    },
    fchown(_fd: number, _uid: number, _gid: number, callback: Callback) {
      callback(null)
    },
    lchown(_path: string, _uid: number, _gid: number, callback: Callback) {
      callback(null)
    },
    utimes(_path: string, _atime: number, _mtime: number, callback: Callback) {
      callback(null)
    },

    // Genuinely unsupported, and saying so is the honest answer -- the CLI does
    // not follow links and never creates them.
    link(_path: string, _link: string, callback: Callback) {
      callback(fsError('ENOSYS', 'links are not supported'))
    },
    symlink(_path: string, _link: string, callback: Callback) {
      callback(fsError('ENOSYS', 'symlinks are not supported'))
    },
    readlink(_path: string, callback: Callback) {
      callback(fsError('EINVAL', 'not a symlink'))
    },
  }

  function resolve(path: string): string {
    return path.startsWith('/')
      ? normalizePath(path)
      : normalizePath(`${cwd}/${path}`)
  }

  const processGlobal: Record<string, unknown> = {
    // argv0 decides whether Go uses fetch or raw sockets for net/http
    // (roundtrip_js.go disables fetch when it starts with "node"). In a browser
    // there is no argv0 at all, and fetch is the only transport there is.
    argv0: 'browser',
    pid: 1,
    ppid: 0,
    getuid: () => -1,
    getgid: () => -1,
    geteuid: () => -1,
    getegid: () => -1,
    getgroups: () => [],
    umask: () => 0o022,
    cwd: () => cwd,
    chdir: (path: string) => {
      const target = resolve(path)
      if (!vfs.existsSync(target) || !vfs.statSync(target).isDirectory()) {
        throw fsError('ENOENT', `no such directory: ${path}`)
      }
      cwd = target
    },
  }

  const pathGlobal: Record<string, unknown> = {
    resolve: (...segments: string[]) => {
      let result = cwd
      for (const segment of segments) {
        result = segment.startsWith('/') ? segment : `${result}/${segment}`
      }
      return normalizePath(result)
    },
  }

  return { fs, process: processGlobal, path: pathGlobal }
}
