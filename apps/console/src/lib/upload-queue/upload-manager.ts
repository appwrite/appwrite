/**
 * Background upload manager
 *
 * Handles file uploads in the background, persisting to IndexedDB
 * and continuing even if the tab is closed (when Service Worker is available).
 */

import { ID, AppwriteException } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import * as db from './indexeddb'
import { isIndexedDBMutationError } from './indexeddb'
import type {
  UploadItem,
  UploadProgress,
  UploadProgressCallback,
} from './types'

class UploadManager {
  private uploads = new Map<string, AbortController>()
  private progressCallbacks = new Map<string, Set<UploadProgressCallback>>()
  private processingQueue = false
  private readonly maxConcurrentUploads = 3
  /** In-memory queue when IndexedDB writes are unavailable (e.g. Firefox private browsing). */
  private ephemeralItems = new Map<string, UploadItem>()

  /**
   * Register a progress callback for an upload
   */
  onProgress(id: string, callback: UploadProgressCallback): () => void {
    if (!this.progressCallbacks.has(id)) {
      this.progressCallbacks.set(id, new Set())
    }
    this.progressCallbacks.get(id)!.add(callback)

    // Return unsubscribe function
    return () => {
      const callbacks = this.progressCallbacks.get(id)
      if (callbacks) {
        callbacks.delete(callback)
        if (callbacks.size === 0) {
          this.progressCallbacks.delete(id)
        }
      }
    }
  }

  /**
   * Emit progress update to all registered callbacks
   */
  private emitProgress(progress: UploadProgress): void {
    const callbacks = this.progressCallbacks.get(progress.id)
    if (callbacks) {
      callbacks.forEach((callback) => {
        try {
          callback(progress)
        } catch (error) {
          console.error('Error in upload progress callback:', error)
        }
      })
    }
  }

  private getEphemeralItems(
    status?: UploadItem['status'],
    projectId?: string,
    bucketId?: string,
  ): UploadItem[] {
    return [...this.ephemeralItems.values()].filter((item) => {
      if (status && item.status !== status) return false
      if (projectId && item.projectId !== projectId) return false
      if (bucketId && item.bucketId !== bucketId) return false
      return true
    })
  }

  private async updateUploadState(
    item: UploadItem,
    updates: Partial<UploadItem>,
  ): Promise<void> {
    const updated: UploadItem = {
      ...item,
      ...updates,
      updatedAt: Date.now(),
    }
    if (this.ephemeralItems.has(item.id)) {
      this.ephemeralItems.set(item.id, updated)
    }
    await db.saveUploadItem(updated)
  }

  /**
   * Convert ArrayBuffer back to File
   */
  private arrayBufferToFile(
    buffer: ArrayBuffer,
    fileName: string,
    mimeType: string,
  ): File {
    return new File([buffer], fileName, { type: mimeType })
  }

  /**
   * Add a file to the upload queue
   * Returns immediately without blocking on file reading
   */
  async queueUpload(
    projectId: string,
    bucketId: string,
    file: File,
    fileId?: string,
    permissions?: string[],
  ): Promise<string> {
    const uploadId = ID.unique()

    // Save File object directly to IndexedDB (non-blocking)
    // IndexedDB supports File/Blob objects, conversion to ArrayBuffer happens during processing
    const uploadItem: UploadItem = {
      id: uploadId,
      projectId,
      bucketId,
      fileId: fileId || ID.unique(),
      fileName: file.name,
      fileSize: file.size,
      fileType: file.type,
      fileData: file, // Store File object directly, convert to ArrayBuffer when processing
      permissions,
      status: 'pending',
      progress: 0,
      createdAt: Date.now(),
      updatedAt: Date.now(),
    }

    await db.saveUploadItem(uploadItem)
    if (!db.isUploadPersistenceAvailable()) {
      this.ephemeralItems.set(uploadId, uploadItem)
    }

    // Start processing queue if not already processing
    this.processQueue()

    return uploadId
  }

  /**
   * Process the upload queue
   */
  async processQueue(): Promise<void> {
    if (this.processingQueue) {
      return
    }

    this.processingQueue = true

    try {
      // Get pending uploads (IndexedDB + in-memory fallback)
      const pendingUploads = [
        ...(await db.getUploadItems('pending')),
        ...this.getEphemeralItems('pending'),
      ]

      if (pendingUploads.length === 0) {
        return
      }

      let currentIndex = 0

      const runNext = async (): Promise<void> => {
        while (currentIndex < pendingUploads.length) {
          const item = pendingUploads[currentIndex]
          currentIndex += 1

          if (this.uploads.has(item.id)) {
            continue // Already uploading
          }

          await this.processUpload(item)
        }
      }

      const workerCount = Math.min(
        this.maxConcurrentUploads,
        pendingUploads.length,
      )

      await Promise.all(Array.from({ length: workerCount }, () => runNext()))
    } finally {
      this.processingQueue = false

      // If new pending uploads were added or retries were queued, resume processing
      const remainingPending = [
        ...(await db.getUploadItems('pending')),
        ...this.getEphemeralItems('pending'),
      ]
      if (remainingPending.length > 0) {
        setTimeout(() => {
          this.processQueue()
        }, 0)
      }
    }
  }

  /**
   * Process a single upload with retry logic
   */
  private async processUpload(item: UploadItem): Promise<void> {
    const abortController = new AbortController()
    this.uploads.set(item.id, abortController)

    const maxRetries = item.maxRetries ?? 3
    const retryCount = item.retryCount ?? 0

    try {
      // Update status to uploading
      await this.updateUploadState(item, {
        status: 'uploading',
        startedAt: Date.now(),
        progress: 0,
        retryCount,
      })

      this.emitProgress({
        id: item.id,
        status: 'uploading',
        progress: 0,
      })

      // Convert fileData to File if needed
      // fileData might be a File object (from queueUpload), Blob (from IndexedDB), or ArrayBuffer
      let file: File
      if (item.fileData instanceof File) {
        // Already a File object, use it directly
        file = item.fileData
      } else if (item.fileData instanceof Blob) {
        // Convert Blob to File (IndexedDB may return Blob instead of File)
        file = new File([item.fileData], item.fileName, { type: item.fileType })
      } else if (item.fileData instanceof ArrayBuffer) {
        // Convert ArrayBuffer back to File
        file = this.arrayBufferToFile(
          item.fileData,
          item.fileName,
          item.fileType,
        )
      } else {
        // Fallback: should not happen, but handle gracefully
        throw new Error('Invalid fileData type in upload item')
      }

      // Perform the upload using Appwrite SDK
      const projectSdk = sdk.forProject(item.projectId)

      // Track if upload was aborted
      let wasAborted = false
      abortController.signal.addEventListener('abort', () => {
        wasAborted = true
      })

      // Use SDK's createFile method which handles chunking automatically
      const uploadPromise = projectSdk.storage.createFile({
        bucketId: item.bucketId,
        fileId: item.fileId!,
        file: file,
        permissions: item.permissions,
        onProgress: (progress) => {
          // Check if aborted during progress
          if (wasAborted || abortController.signal.aborted) {
            return
          }

          // SDK provides progress with chunksUploaded and chunksTotal
          const uploadProgress =
            progress.chunksTotal > 0
              ? Math.round(
                  (progress.chunksUploaded / progress.chunksTotal) * 100,
                )
              : 0

          void this.updateUploadState(item, { progress: uploadProgress }).catch(
            () => {},
          )
          this.emitProgress({
            id: item.id,
            status: 'uploading',
            progress: uploadProgress,
          })
        },
      })

      // Wait for upload, but check for abort
      await Promise.race([
        uploadPromise,
        new Promise((_, reject) => {
          abortController.signal.addEventListener('abort', () => {
            reject(new DOMException('Upload aborted', 'AbortError'))
          })
        }),
      ])

      // Update status to completed
      await this.updateUploadState(item, {
        status: 'completed',
        progress: 100,
        completedAt: Date.now(),
      })

      this.emitProgress({
        id: item.id,
        status: 'completed',
        progress: 100,
      })

      // Keep completed item in DB so UI can show "View file" link (clean up after 5 min)
      setTimeout(
        () => {
          void db.deleteUploadItem(item.id).catch(() => {})
          this.ephemeralItems.delete(item.id)
        },
        5 * 60 * 1000,
      )
    } catch (error: unknown) {
      if (error.name === 'AbortError' || error.message?.includes('aborted')) {
        // Upload was cancelled
        await this.updateUploadState(item, {
          status: 'cancelled',
          completedAt: Date.now(),
        })

        this.emitProgress({
          id: item.id,
          status: 'cancelled',
          progress: item.progress,
        })
      } else {
        // Check if we should retry
        const isRetryableError = this.isRetryableError(error)
        const shouldRetry = isRetryableError && retryCount < maxRetries

        if (shouldRetry) {
          // Retry with exponential backoff
          const delay = Math.min(1000 * Math.pow(2, retryCount), 30000) // Max 30 seconds

          await this.updateUploadState(item, {
            status: 'pending',
            retryCount: retryCount + 1,
            error: `Retrying... (${retryCount + 1}/${maxRetries})`,
            progress: 0, // Reset progress for retry
          })

          this.emitProgress({
            id: item.id,
            status: 'pending',
            progress: 0,
            error: `Retrying... (${retryCount + 1}/${maxRetries})`,
          })

          // Wait before retrying
          await new Promise((resolve) => setTimeout(resolve, delay))

          // Remove from active uploads and re-queue
          this.uploads.delete(item.id)
          this.processQueue()
          return
        } else {
          // Upload failed permanently
          // Extract error message from AppwriteException or generic error
          let errorMessage = 'Upload failed'
          if (error instanceof AppwriteException) {
            errorMessage =
              error.message ||
              `Upload failed with status ${error.code || 'unknown'}`
          } else if (error.message) {
            errorMessage = error.message
          }

          await this.updateUploadState(item, {
            status: 'failed',
            error: errorMessage,
            completedAt: Date.now(),
          })

          this.emitProgress({
            id: item.id,
            status: 'failed',
            progress: item.progress,
            error: errorMessage,
          })
        }
      }
    } finally {
      this.uploads.delete(item.id)
    }
  }

  /**
   * Check if an error is retryable (network errors, connection reset, etc.)
   */
  private isRetryableError(error: unknown): boolean {
    const errorMessage = error.message?.toLowerCase() || ''
    const errorName = error.name?.toLowerCase() || ''

    // Network errors that should be retried
    const retryablePatterns = [
      'network error',
      'connection reset',
      'connection closed',
      'timeout',
      'failed to fetch',
      'network request failed',
      'aborted',
      'econnreset',
      'etimedout',
    ]

    // HTTP status codes that should be retried
    const retryableStatusCodes = [408, 429, 500, 502, 503, 504]

    // Check AppwriteException status code
    if (error instanceof AppwriteException) {
      if (error.code && retryableStatusCodes.includes(error.code)) {
        return true
      }
    }

    // Check error message
    if (retryablePatterns.some((pattern) => errorMessage.includes(pattern))) {
      return true
    }

    // Check HTTP status code (if available in error)
    if (error.status && retryableStatusCodes.includes(error.status)) {
      return true
    }

    // Check if it's a network-related error name
    if (errorName.includes('network') || errorName.includes('timeout')) {
      return true
    }

    return false
  }

  /**
   * Cancel an upload
   */
  async cancelUpload(id: string): Promise<void> {
    const abortController = this.uploads.get(id)
    if (abortController) {
      abortController.abort()
    } else {
      // If not currently uploading, just update status
      const item =
        this.ephemeralItems.get(id) ?? (await db.getUploadItem(id))
      if (item && item.status === 'pending') {
        await this.updateUploadState(item, {
          status: 'cancelled',
          completedAt: Date.now(),
        })
      }
    }
  }

  /**
   * Get upload status
   */
  async getUploadStatus(id: string): Promise<UploadItem | null> {
    return this.ephemeralItems.get(id) ?? (await db.getUploadItem(id))
  }

  /**
   * Get all uploads for a bucket
   */
  async getBucketUploads(
    projectId: string,
    bucketId: string,
  ): Promise<UploadItem[]> {
    const persisted = await db.getUploadItems(undefined, projectId, bucketId)
    const ephemeral = this.getEphemeralItems(undefined, projectId, bucketId)
    return [...persisted, ...ephemeral]
  }

  /**
   * Get uploads for display: active (pending/uploading) plus recently completed (so UI can show "View file" link)
   */
  async getActiveUploads(): Promise<UploadItem[]> {
    const [pending, uploading, completed] = await Promise.all([
      db.getUploadItems('pending'),
      db.getUploadItems('uploading'),
      db.getUploadItems('completed'),
    ])
    return [
      ...pending,
      ...uploading,
      ...completed,
      ...this.getEphemeralItems('pending'),
      ...this.getEphemeralItems('uploading'),
      ...this.getEphemeralItems('completed'),
    ]
  }

  /**
   * Resume processing queue (called on page load)
   */
  async resumeQueue(): Promise<void> {
    // Resume any pending or uploading items
    const uploading = await db.getUploadItems('uploading')

    // Reset uploading items back to pending (they were interrupted)
    // Don't reset retry count - preserve retry attempts
    for (const item of uploading) {
      await this.updateUploadState(item, {
        status: 'pending',
        progress: 0,
        // Keep retryCount as is - don't reset it
      })
    }

    // Process the queue
    await this.processQueue()
  }

  /**
   * Remove a completed (or any) upload from the queue (e.g. when user dismisses the progress item)
   */
  async removeUploadItem(id: string): Promise<void> {
    this.ephemeralItems.delete(id)
    await db.deleteUploadItem(id)
  }

  /**
   * Clear old completed/failed uploads
   */
  async clearOldUploads(
    olderThanMs: number = 24 * 60 * 60 * 1000,
  ): Promise<void> {
    await db.clearOldUploads(olderThanMs)
  }
}

// Singleton instance
export const uploadManager = new UploadManager()

// Resume queue on page load
if (typeof window !== 'undefined') {
  void uploadManager.resumeQueue().catch((error) => {
    if (!isIndexedDBMutationError(error)) {
      console.warn('Failed to resume upload queue:', error)
    }
  })

  // Clear old uploads on load (once per day)
  try {
    const lastClear = localStorage.getItem('upload-queue-last-clear')
    const now = Date.now()
    if (!lastClear || now - parseInt(lastClear, 10) > 24 * 60 * 60 * 1000) {
      void uploadManager.clearOldUploads().catch((error) => {
        if (!isIndexedDBMutationError(error)) {
          console.warn('Failed to clear old uploads:', error)
        }
      })
      try {
        localStorage.setItem('upload-queue-last-clear', now.toString())
      } catch {
        // localStorage may be unavailable in private browsing
      }
    }
  } catch {
    // ignore storage access errors
  }
}
