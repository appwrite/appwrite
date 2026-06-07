import type { VFSSnapshot } from 'almostnode'
import type { CliShellContainer } from './types'
import { CLI_VFS_CACHE_ROOT } from './constants'

const DB_NAME = 'console-cli-shell'
const STORE_NAME = 'vfs-cache'

type CachedCliModules = {
  version: string
  snapshot: VFSSnapshot
  savedAt: number
}

function base64ToUint8(base64: string): Uint8Array {
  const binary = atob(base64)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index++) {
    bytes[index] = binary.charCodeAt(index)
  }
  return bytes
}

function openCacheDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    if (typeof indexedDB === 'undefined') {
      reject(new Error('IndexedDB is unavailable'))
      return
    }

    const request = indexedDB.open(DB_NAME, 1)
    request.onupgradeneeded = () => {
      request.result.createObjectStore(STORE_NAME)
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('IndexedDB open failed'))
  })
}

function cacheKey(version: string): string {
  return `cli-modules:${version}`
}

export function extractSnapshotPrefix(
  vfs: { toSnapshot(): VFSSnapshot },
  prefix: string,
): VFSSnapshot {
  const normalized =
    prefix.endsWith('/') && prefix.length > 1 ? prefix.slice(0, -1) : prefix

  const snapshot = vfs.toSnapshot()
  return {
    files: snapshot.files.filter(
      (entry) =>
        entry.path === normalized || entry.path.startsWith(`${normalized}/`),
    ),
  }
}

export function applySnapshotToVfs(
  vfs: CliShellContainer['vfs'],
  snapshot: VFSSnapshot,
): void {
  const sortedEntries = snapshot.files
    .map((entry, index) => ({
      entry,
      depth: entry.path.split('/').length,
      index,
    }))
    .sort((left, right) => left.depth - right.depth || left.index - right.index)
    .map(({ entry }) => entry)

  for (const entry of sortedEntries) {
    if (entry.path === '/') continue

    if (entry.type === 'directory') {
      vfs.mkdirSync(entry.path, { recursive: true })
      continue
    }

    const parentPath =
      entry.path.substring(0, entry.path.lastIndexOf('/')) || '/'
    if (parentPath !== '/' && !vfs.existsSync(parentPath)) {
      vfs.mkdirSync(parentPath, { recursive: true })
    }

    if (entry.content) {
      vfs.writeFileSync(entry.path, base64ToUint8(entry.content))
    } else {
      vfs.writeFileSync(entry.path, '')
    }
  }
}

export async function readCliModulesCache(
  version: string,
): Promise<VFSSnapshot | null> {
  if (typeof window === 'undefined') return null

  try {
    const db = await openCacheDb()
    const cached = await new Promise<CachedCliModules | null>((resolve, reject) => {
      const transaction = db.transaction(STORE_NAME, 'readonly')
      const store = transaction.objectStore(STORE_NAME)
      const request = store.get(cacheKey(version))
      request.onsuccess = () => {
        resolve((request.result as CachedCliModules | undefined) ?? null)
      }
      request.onerror = () => reject(request.error ?? new Error('IndexedDB read failed'))
    })
    db.close()

    if (!cached || cached.version !== version || cached.snapshot.files.length === 0) {
      return null
    }

    return cached.snapshot
  } catch {
    return null
  }
}

export async function writeCliModulesCache(
  version: string,
  snapshot: VFSSnapshot,
): Promise<void> {
  if (typeof window === 'undefined' || snapshot.files.length === 0) return

  const db = await openCacheDb()
  try {
    await new Promise<void>((resolve, reject) => {
      const transaction = db.transaction(STORE_NAME, 'readwrite')
      const store = transaction.objectStore(STORE_NAME)
      const payload: CachedCliModules = {
        version,
        snapshot,
        savedAt: Date.now(),
      }
      const request = store.put(payload, cacheKey(version))
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error ?? new Error('IndexedDB write failed'))
    })
  } finally {
    db.close()
  }
}

export async function restoreCliModulesFromCache(
  vfs: CliShellContainer['vfs'],
  version: string,
): Promise<boolean> {
  const snapshot = await readCliModulesCache(version)
  if (!snapshot) return false

  applySnapshotToVfs(vfs, snapshot)
  return true
}

export async function persistCliModulesCache(
  vfs: CliShellContainer['vfs'],
  version: string,
): Promise<void> {
  const snapshot = extractSnapshotPrefix(vfs, CLI_VFS_CACHE_ROOT)
  await writeCliModulesCache(version, snapshot)
}

export async function clearCliModulesCache(version: string): Promise<void> {
  if (typeof window === 'undefined') return

  try {
    const db = await openCacheDb()
    await new Promise<void>((resolve, reject) => {
      const transaction = db.transaction(STORE_NAME, 'readwrite')
      const store = transaction.objectStore(STORE_NAME)
      const request = store.delete(cacheKey(version))
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error ?? new Error('IndexedDB delete failed'))
    })
    db.close()
  } catch {
    /* ignore cache clear failures */
  }
}

export type CliModulesCacheMeta = {
  version: string
  savedAt: number
  fileCount: number
}

export async function readCliModulesCacheMeta(
  version: string,
): Promise<CliModulesCacheMeta | null> {
  if (typeof window === 'undefined') return null

  try {
    const db = await openCacheDb()
    const cached = await new Promise<CachedCliModules | null>((resolve, reject) => {
      const transaction = db.transaction(STORE_NAME, 'readonly')
      const store = transaction.objectStore(STORE_NAME)
      const request = store.get(cacheKey(version))
      request.onsuccess = () => {
        resolve((request.result as CachedCliModules | undefined) ?? null)
      }
      request.onerror = () => reject(request.error ?? new Error('IndexedDB read failed'))
    })
    db.close()

    if (!cached || cached.version !== version || cached.snapshot.files.length === 0) {
      return null
    }

    return {
      version: cached.version,
      savedAt: cached.savedAt,
      fileCount: cached.snapshot.files.length,
    }
  } catch {
    return null
  }
}

export async function clearAllCliModulesCaches(): Promise<void> {
  if (typeof window === 'undefined') return

  try {
    const db = await openCacheDb()
    await new Promise<void>((resolve, reject) => {
      const transaction = db.transaction(STORE_NAME, 'readwrite')
      const store = transaction.objectStore(STORE_NAME)
      const request = store.clear()
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error ?? new Error('IndexedDB clear failed'))
    })
    db.close()
  } catch {
    /* ignore cache clear failures */
  }
}
