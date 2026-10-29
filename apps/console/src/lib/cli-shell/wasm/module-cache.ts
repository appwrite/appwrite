/**
 * IndexedDB cache for the CLI wasm bytes.
 *
 * Only the module is cached. The CLI's own state -- prefs.json, the project
 * config -- is rewritten from the live console session on every bootstrap, so
 * persisting it would only create a second source of truth that can go stale
 * against the signed-in user.
 *
 * Every failure path here is non-fatal: a browser in private mode, with a full
 * quota, or with storage disabled should download the module again, not lose
 * the terminal.
 */

const DB_NAME = 'console-cli-wasm'
const STORE_NAME = 'module'
const DB_VERSION = 1

function openDatabase(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    if (typeof indexedDB === 'undefined') {
      reject(new Error('IndexedDB is unavailable'))
      return
    }

    const request = indexedDB.open(DB_NAME, DB_VERSION)
    request.onupgradeneeded = () => {
      const database = request.result
      if (!database.objectStoreNames.contains(STORE_NAME)) {
        database.createObjectStore(STORE_NAME)
      }
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () =>
      reject(request.error ?? new Error('IndexedDB open failed'))
  })
}

function cacheKey(url: string): string {
  return `wasm:${url}`
}

export async function readCachedModuleBytes(
  url: string,
): Promise<Uint8Array | null> {
  try {
    const database = await openDatabase()

    return await new Promise<Uint8Array | null>((resolve) => {
      const request = database
        .transaction(STORE_NAME, 'readonly')
        .objectStore(STORE_NAME)
        .get(cacheKey(url))

      request.onsuccess = () => {
        const value = request.result
        resolve(value instanceof Uint8Array ? value : null)
      }
      request.onerror = () => resolve(null)
      // Not in a `finally`: the transaction needs the connection until it
      // settles, and closing here would abort the read.
      request.transaction?.addEventListener('complete', () => database.close())
    })
  } catch {
    return null
  }
}

export async function writeCachedModuleBytes(
  url: string,
  bytes: Uint8Array,
): Promise<void> {
  const database = await openDatabase()

  try {
    await new Promise<void>((resolve, reject) => {
      const transaction = database.transaction(STORE_NAME, 'readwrite')
      const store = transaction.objectStore(STORE_NAME)

      // One artifact at a time. Keeping the previous one would double a ~22 MB
      // footprint for something nothing will ask for again.
      const keys = store.getAllKeys()
      keys.onsuccess = () => {
        for (const key of keys.result) {
          if (key !== cacheKey(url)) {
            store.delete(key)
          }
        }
        store.put(bytes, cacheKey(url))
      }

      transaction.oncomplete = () => resolve()
      transaction.onerror = () =>
        reject(transaction.error ?? new Error('IndexedDB write failed'))
      transaction.onabort = () =>
        reject(transaction.error ?? new Error('IndexedDB write aborted'))
    })
  } finally {
    database.close()
  }
}

/** Drop every cached artifact, whatever version it was stored under. */
export async function clearCachedModules(): Promise<void> {
  try {
    const database = await openDatabase()

    await new Promise<void>((resolve) => {
      const transaction = database.transaction(STORE_NAME, 'readwrite')
      transaction.objectStore(STORE_NAME).clear()
      transaction.oncomplete = () => resolve()
      transaction.onerror = () => resolve()
      transaction.onabort = () => resolve()
    })

    database.close()
  } catch {
    /* nothing cached, or no storage to clear */
  }
}
