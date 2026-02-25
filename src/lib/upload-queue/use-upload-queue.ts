/**
 * React hook for managing upload queue
 */

import { useState, useEffect, useCallback } from 'react'
import { uploadManager } from './upload-manager'
import type { UploadItem } from './types'

const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'] as const

export interface UseUploadQueueOptions {
  /** Called when an upload reaches a terminal state (completed, failed, cancelled) */
  onUploadComplete?: (projectId: string, bucketId: string) => void
}

export function useUploadQueue(
  projectId?: string,
  bucketId?: string,
  options?: UseUploadQueueOptions,
) {
  const { onUploadComplete } = options ?? {}
  const [uploads, setUploads] = useState<UploadItem[]>([])
  const [isLoading, setIsLoading] = useState(true)

  // Load uploads for this bucket
  useEffect(() => {
    if (!projectId || !bucketId) {
      setUploads([])
      setIsLoading(false)
      return
    }

    let mounted = true

    async function loadUploads() {
      try {
        const items = await uploadManager.getBucketUploads(projectId, bucketId)
        if (mounted) {
          setUploads(items)
          setIsLoading(false)
        }
      } catch (error) {
        console.error('Failed to load uploads:', error)
        if (mounted) {
          setIsLoading(false)
        }
      }
    }

    loadUploads()

    // Set up progress listeners for all uploads
    const unsubscribes: (() => void)[] = []

    const setupListeners = async () => {
      const items = await uploadManager.getBucketUploads(projectId, bucketId)
      items.forEach((item) => {
        const unsubscribe = uploadManager.onProgress(item.id, (progress) => {
          if (mounted) {
            setUploads((prev) =>
              prev.map((u) =>
                u.id === progress.id
                  ? {
                      ...u,
                      status: progress.status,
                      progress: progress.progress,
                      error: progress.error,
                    }
                  : u,
              ),
            )
          }
        })
        unsubscribes.push(unsubscribe)
      })
    }

    setupListeners()

    // Only poll when there are active uploads
    // Use a longer interval (5 seconds) to reduce CPU usage
    let interval: NodeJS.Timeout | null = null

    const pollIfNeeded = async () => {
      if (!mounted) return

      const items = await uploadManager.getBucketUploads(projectId, bucketId)
      if (!mounted) return

      const hasActive = items.some(
        (u) => u.status === 'pending' || u.status === 'uploading',
      )

      if (hasActive && !interval) {
        // Start polling
        interval = setInterval(() => {
          if (mounted) {
            loadUploads()
          }
        }, 5000) // 5 seconds instead of 2
      } else if (!hasActive && interval) {
        // Stop polling when no active uploads
        clearInterval(interval)
        interval = null
      }
    }

    // Check periodically if we need to start/stop polling
    const checkInterval = setInterval(() => {
      pollIfNeeded()
    }, 10000) // Check every 10 seconds

    // Initial check
    pollIfNeeded()

    return () => {
      mounted = false
      if (interval) {
        clearInterval(interval)
      }
      clearInterval(checkInterval)
      unsubscribes.forEach((unsubscribe) => unsubscribe())
    }
  }, [projectId, bucketId])

  const queueUpload = useCallback(
    async (
      file: File,
      fileId?: string,
      permissions?: string[],
    ): Promise<string> => {
      if (!projectId || !bucketId) {
        throw new Error('Project ID and Bucket ID are required')
      }

      const uploadId = await uploadManager.queueUpload(
        projectId,
        bucketId,
        file,
        fileId,
        permissions,
      )

      // Set up progress listener (keep until terminal state so we receive completion)
      const unsubscribe = uploadManager.onProgress(uploadId, (progress) => {
        setUploads((prev) => {
          const existing = prev.find((u) => u.id === uploadId)
          if (existing) {
            return prev.map((u) =>
              u.id === uploadId
                ? {
                    ...u,
                    status: progress.status,
                    progress: progress.progress,
                    error: progress.error,
                  }
                : u,
            )
          } else {
            // Add new upload to list
            return [
              ...prev,
              {
                id: uploadId,
                projectId,
                bucketId,
                fileId: fileId || '',
                fileName: file.name,
                fileSize: file.size,
                fileType: file.type,
                fileData: new ArrayBuffer(0), // Not needed in UI
                permissions,
                status: progress.status,
                progress: progress.progress,
                error: progress.error,
                createdAt: Date.now(),
                updatedAt: Date.now(),
              },
            ]
          }
        })
        if (
          TERMINAL_STATUSES.includes(
            progress.status as (typeof TERMINAL_STATUSES)[number],
          )
        ) {
          onUploadComplete?.(projectId, bucketId)
          setTimeout(() => unsubscribe(), 0)
        }
      })

      // Reload uploads to get the full item (don't unsubscribe - we need completion events)
      setTimeout(async () => {
        const item = await uploadManager.getUploadStatus(uploadId)
        if (item) {
          setUploads((prev) => {
            const existing = prev.find((u) => u.id === uploadId)
            if (!existing) {
              return [...prev, item]
            }
            return prev
          })
        }
      }, 100)

      return uploadId
    },
    [projectId, bucketId, onUploadComplete],
  )

  const cancelUpload = useCallback(async (uploadId: string) => {
    await uploadManager.cancelUpload(uploadId)
    setUploads((prev) => prev.filter((u) => u.id !== uploadId))
  }, [])

  const activeUploads = uploads.filter(
    (u) => u.status === 'pending' || u.status === 'uploading',
  )

  return {
    uploads,
    activeUploads,
    isLoading,
    queueUpload,
    cancelUpload,
  }
}
