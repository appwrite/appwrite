/**
 * Global upload progress component
 * Shows uploads from all buckets and persists across page navigations
 */

import { useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useActiveUploads } from '@/hooks/use-active-uploads'
import { UploadProgress } from './UploadProgress'
import { uploadManager } from '@/lib/upload-queue/upload-manager'
import type {
  UploadProgress as UploadProgressType,
  UploadItem,
} from '@/lib/upload-queue/types'

interface GlobalUploadProgressProps {
  /** When true, renders without fixed positioning for use inside a shared progress panel */
  embedded?: boolean
}

export function GlobalUploadProgress({
  embedded,
}: GlobalUploadProgressProps = {}) {
  const { activeUploads } = useActiveUploads()
  const queryClient = useQueryClient()
  const invalidatedUploadsRef = useRef<Set<string>>(new Set())
  const uploadItemsRef = useRef<Map<string, UploadItem>>(new Map())
  const previousStatusRef = useRef<Map<string, string>>(new Map())

  // Store upload items when they become active and detect status changes
  useEffect(() => {
    activeUploads.forEach((upload) => {
      uploadItemsRef.current.set(upload.id, upload)

      // Check if upload just completed
      const previousStatus = previousStatusRef.current.get(upload.id)
      if (
        upload.status === 'completed' &&
        previousStatus !== 'completed' &&
        !invalidatedUploadsRef.current.has(upload.id)
      ) {
        // Refetch (not just invalidate) so the list updates without page reload (list uses refetchOnMount: false)
        void queryClient.refetchQueries({
          queryKey: [
            'files',
            'project',
            upload.projectId,
            'bucket',
            upload.bucketId,
          ],
        })
        invalidatedUploadsRef.current.add(upload.id)

        setTimeout(() => {
          invalidatedUploadsRef.current.delete(upload.id)
          uploadItemsRef.current.delete(upload.id)
          previousStatusRef.current.delete(upload.id)
        }, 10000)
      }

      previousStatusRef.current.set(upload.id, upload.status)
    })
  }, [activeUploads, queryClient])

  // Set up progress listeners to catch completion events
  useEffect(() => {
    const unsubscribes: (() => void)[] = []

    const setupListeners = async () => {
      // Get all active uploads and listen to their progress
      const uploads = await uploadManager.getActiveUploads()

      uploads.forEach((upload) => {
        // Store the upload item for later use
        uploadItemsRef.current.set(upload.id, upload)

        const unsubscribe = uploadManager.onProgress(
          upload.id,
          (progress: UploadProgressType) => {
            // When upload completes, refetch files list for that bucket so the UI updates (list uses refetchOnMount: false)
            if (
              progress.status === 'completed' &&
              !invalidatedUploadsRef.current.has(progress.id)
            ) {
              const item = uploadItemsRef.current.get(progress.id)
              if (item) {
                void queryClient.refetchQueries({
                  queryKey: [
                    'files',
                    'project',
                    item.projectId,
                    'bucket',
                    item.bucketId,
                  ],
                })
                invalidatedUploadsRef.current.add(progress.id)

                // Clean up after a delay (uploads are removed from DB after 5 seconds)
                setTimeout(() => {
                  invalidatedUploadsRef.current.delete(progress.id)
                  uploadItemsRef.current.delete(progress.id)
                }, 10000)
              }
            }
          },
        )
        unsubscribes.push(unsubscribe)
      })
    }

    setupListeners()

    return () => {
      unsubscribes.forEach((unsubscribe) => unsubscribe())
    }
  }, [activeUploads.length, queryClient])

  const handleCancel = async (uploadId: string) => {
    await uploadManager.cancelUpload(uploadId)
  }

  const handleDismiss = async (uploadId: string) => {
    await uploadManager.removeUploadItem(uploadId)
  }

  return (
    <UploadProgress
      uploads={activeUploads}
      onCancel={handleCancel}
      onDismiss={handleDismiss}
      embedded={embedded}
      className={embedded ? undefined : 'bottom-4 end-4'}
    />
  )
}
