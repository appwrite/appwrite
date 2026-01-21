/**
 * Global hook to track all active uploads across all buckets
 * Used for window close warnings and global upload status
 */

import { useState, useEffect, useCallback } from 'react'
import { uploadManager } from '@/lib/upload-queue/upload-manager'
import type { UploadItem } from '@/lib/upload-queue/types'

export function useActiveUploads() {
  const [activeUploads, setActiveUploads] = useState<UploadItem[]>([])
  const [isLoading, setIsLoading] = useState(true)

  const loadActiveUploads = useCallback(async () => {
    try {
      const uploads = await uploadManager.getActiveUploads()
      setActiveUploads(uploads)
      setIsLoading(false)
    } catch (error) {
      console.error('Failed to load active uploads:', error)
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let mounted = true

    // Initial load
    loadActiveUploads()

    // Set up progress listeners for all active uploads
    const unsubscribes: (() => void)[] = []

    const setupListeners = async () => {
      const uploads = await uploadManager.getActiveUploads()
      if (!mounted) return

      uploads.forEach((item) => {
        const unsubscribe = uploadManager.onProgress(item.id, (progress) => {
          if (!mounted) return

          // Reload active uploads when status changes
          loadActiveUploads()
        })
        unsubscribes.push(unsubscribe)
      })
    }

    setupListeners()

    // Poll for new uploads periodically
    const interval = setInterval(() => {
      if (mounted) {
        loadActiveUploads()
      }
    }, 2000)

    return () => {
      mounted = false
      clearInterval(interval)
      unsubscribes.forEach((unsubscribe) => unsubscribe())
    }
  }, [loadActiveUploads])

  return {
    activeUploads,
    hasActiveUploads: activeUploads.length > 0,
    isLoading,
  }
}
