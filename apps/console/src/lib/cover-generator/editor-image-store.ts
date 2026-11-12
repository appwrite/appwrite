/**
 * IndexedDB persistence for cover generator uploaded images.
 * Blobs are stored as-is (no base64 in localStorage).
 */

import { isIndexedDBMutationError } from '@/lib/upload-queue/indexeddb'

const DB_NAME = 'console-cover-generator'
const DB_VERSION = 1
const STORE_NAME = 'images'

type StoredCoverImage = {
  key: string
  blob: Blob
  updatedAt: number
}

let dbPromise: Promise<IDBDatabase> | null = null
let persistenceDisabled = false

function disablePersistence(error: unknown): void {
  if (!isIndexedDBMutationError(error)) return
  persistenceDisabled = true
  dbPromise = null
}

async function runWrite<T>(operation: () => Promise<T>): Promise<T | undefined> {
  if (persistenceDisabled) return undefined
  try {
    return await operation()
  } catch (error) {
    if (isIndexedDBMutationError(error)) {
      disablePersistence(error)
      return undefined
    }
    throw error
  }
}

function openDatabase(): Promise<IDBDatabase> {
  if (dbPromise) return dbPromise

  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onerror = () => reject(request.error)
    request.onsuccess = () => resolve(request.result)

    request.onupgradeneeded = (event) => {
      const db = (event.target as IDBOpenDBRequest).result
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'key' })
      }
    }
  })

  return dbPromise
}

async function getStore(
  mode: IDBTransactionMode = 'readonly',
): Promise<IDBObjectStore> {
  const db = await openDatabase()
  return db.transaction([STORE_NAME], mode).objectStore(STORE_NAME)
}

export async function saveCoverImageField(
  key: string,
  blob: Blob,
): Promise<void> {
  await runWrite(async () => {
    const store = await getStore('readwrite')
    const record: StoredCoverImage = { key, blob, updatedAt: Date.now() }
    await new Promise<void>((resolve, reject) => {
      const request = store.put(record)
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error)
    })
  })
}

export async function getCoverImageField(key: string): Promise<Blob | null> {
  if (persistenceDisabled) return null
  try {
    const store = await getStore()
    const record = await new Promise<StoredCoverImage | undefined>(
      (resolve, reject) => {
        const request = store.get(key)
        request.onsuccess = () => resolve(request.result as StoredCoverImage | undefined)
        request.onerror = () => reject(request.error)
      },
    )
    return record?.blob ?? null
  } catch (error) {
    if (isIndexedDBMutationError(error)) {
      disablePersistence(error)
      return null
    }
    throw error
  }
}

export async function getAllCoverImageFields(): Promise<Map<string, Blob>> {
  if (persistenceDisabled) return new Map()
  try {
    const store = await getStore()
    return await new Promise((resolve, reject) => {
      const result = new Map<string, Blob>()
      const request = store.openCursor()

      request.onsuccess = (event) => {
        const cursor = (event.target as IDBRequest<IDBCursorWithValue>).result
        if (!cursor) {
          resolve(result)
          return
        }
        const record = cursor.value as StoredCoverImage
        result.set(record.key, record.blob)
        cursor.continue()
      }

      request.onerror = () => reject(request.error)
    })
  } catch (error) {
    if (isIndexedDBMutationError(error)) {
      disablePersistence(error)
      return new Map()
    }
    throw error
  }
}

export async function deleteCoverImageField(key: string): Promise<void> {
  await runWrite(async () => {
    const store = await getStore('readwrite')
    await new Promise<void>((resolve, reject) => {
      const request = store.delete(key)
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error)
    })
  })
}

export async function clearCoverImageFields(): Promise<void> {
  await runWrite(async () => {
    const store = await getStore('readwrite')
    await new Promise<void>((resolve, reject) => {
      const request = store.clear()
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error)
    })
  })
}

export function isCoverImagePersistenceAvailable(): boolean {
  return !persistenceDisabled
}
