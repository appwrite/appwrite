/**
 * IndexedDB wrapper for upload queue persistence
 *
 * Stores upload queue in IndexedDB so uploads can survive page reloads and tab closes.
 */

import type { UploadItem, UploadStatus } from './types'

const DB_NAME = 'appwrite-upload-queue'
const DB_VERSION = 1
const STORE_NAME = 'uploads'

let dbPromise: Promise<IDBDatabase> | null = null
let persistenceDisabled = false

/** Firefox private browsing / blocked storage can open IndexedDB read-only. */
export function isIndexedDBMutationError(error: unknown): boolean {
  if (error instanceof DOMException) {
    if (error.name === 'InvalidStateError' || error.code === 11) return true
  }
  if (error instanceof Error) {
    return /did not allow mutations/i.test(error.message)
  }
  return false
}

export function isUploadPersistenceAvailable(): boolean {
  return !persistenceDisabled
}

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
  if (dbPromise) {
    return dbPromise
  }

  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onerror = () => reject(request.error)
    request.onsuccess = () => resolve(request.result)

    request.onupgradeneeded = (event) => {
      const db = (event.target as IDBOpenDBRequest).result

      if (!db.objectStoreNames.contains(STORE_NAME)) {
        const store = db.createObjectStore(STORE_NAME, { keyPath: 'id' })
        store.createIndex('status', 'status', { unique: false })
        store.createIndex('projectId', 'projectId', { unique: false })
        store.createIndex('bucketId', 'bucketId', { unique: false })
        store.createIndex('createdAt', 'createdAt', { unique: false })
      }
    }
  })

  return dbPromise
}

async function getStore(
  mode: IDBTransactionMode = 'readonly',
): Promise<IDBObjectStore> {
  const db = await openDatabase()
  const transaction = db.transaction([STORE_NAME], mode)
  return transaction.objectStore(STORE_NAME)
}

/**
 * Add or update an upload item in the queue
 */
export async function saveUploadItem(item: UploadItem): Promise<void> {
  await runWrite(async () => {
    const store = await getStore('readwrite')
    await new Promise<void>((resolve, reject) => {
      const request = store.put(item)
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error)
    })
  })
}

/**
 * Get an upload item by ID
 */
export async function getUploadItem(id: string): Promise<UploadItem | null> {
  if (persistenceDisabled) return null
  try {
    const store = await getStore()
    return await new Promise((resolve, reject) => {
      const request = store.get(id)
      request.onsuccess = () => resolve(request.result || null)
      request.onerror = () => reject(request.error)
    })
  } catch (error) {
    if (isIndexedDBMutationError(error)) {
      disablePersistence(error)
      return null
    }
    throw error
  }
}

/**
 * Get all upload items, optionally filtered by status
 */
export async function getUploadItems(
  status?: UploadStatus,
  projectId?: string,
  bucketId?: string,
): Promise<UploadItem[]> {
  if (persistenceDisabled) return []
  try {
    const store = await getStore()
    return await new Promise((resolve, reject) => {
    const items: UploadItem[] = []
    let request: IDBRequest

    if (status) {
      const index = store.index('status')
      request = index.openCursor(IDBKeyRange.only(status))
    } else if (projectId && bucketId) {
      const index = store.index('projectId')
      request = index.openCursor(IDBKeyRange.only(projectId))
    } else {
      request = store.openCursor()
    }

    request.onsuccess = (event) => {
      const cursor = (event.target as IDBRequest<IDBCursorWithValue>).result
      if (cursor) {
        const item = cursor.value as UploadItem
        if (!bucketId || item.bucketId === bucketId) {
          items.push(item)
        }
        cursor.continue()
      } else {
        resolve(items)
      }
    }

    request.onerror = () => reject(request.error)
    })
  } catch (error) {
    if (isIndexedDBMutationError(error)) {
      disablePersistence(error)
      return []
    }
    throw error
  }
}

/**
 * Delete an upload item from the queue
 */
export async function deleteUploadItem(id: string): Promise<void> {
  await runWrite(async () => {
    const store = await getStore('readwrite')
    await new Promise<void>((resolve, reject) => {
      const request = store.delete(id)
      request.onsuccess = () => resolve()
      request.onerror = () => reject(request.error)
    })
  })
}

/**
 * Update upload item status and progress
 */
export async function updateUploadItem(
  id: string,
  updates: Partial<UploadItem>,
): Promise<void> {
  const item = await getUploadItem(id)
  if (!item) {
    throw new Error(`Upload item ${id} not found`)
  }

  const updated: UploadItem = {
    ...item,
    ...updates,
    updatedAt: Date.now(),
  }

  await saveUploadItem(updated)
}

/**
 * Clear completed and failed uploads older than specified time
 */
export async function clearOldUploads(
  olderThanMs: number = 24 * 60 * 60 * 1000,
): Promise<void> {
  await runWrite(async () => {
    const store = await getStore('readwrite')
    const index = store.index('status')

    await new Promise<void>((resolve, reject) => {
    const cutoffTime = Date.now() - olderThanMs
    const statuses: UploadStatus[] = ['completed', 'failed', 'cancelled']
    let completed = 0
    const total = statuses.length

    if (total === 0) {
      resolve()
      return
    }

    statuses.forEach((status) => {
      const request = index.openCursor(IDBKeyRange.only(status))

      request.onsuccess = (event) => {
        const cursor = (event.target as IDBRequest<IDBCursorWithValue>).result
        if (cursor) {
          const item = cursor.value as UploadItem
          if (item.completedAt && item.completedAt < cutoffTime) {
            cursor.delete()
          }
          cursor.continue()
        } else {
          completed++
          if (completed === total) {
            resolve()
          }
        }
      }

      request.onerror = () => {
        completed++
        if (completed === total) {
          reject(request.error)
        }
      }
    })
    })
  })
}
