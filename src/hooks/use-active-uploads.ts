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
    let interval: NodeJS.Timeout | null = null

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

    // Only poll when there are active uploads
    // Use a longer interval (5 seconds) to reduce CPU usage
    const pollIfNeeded = async () => {
      if (!mounted) return
      
      const uploads = await uploadManager.getActiveUploads()
      if (!mounted) return
      
      const hasActive = uploads.some(
        (u) => u.status === 'pending' || u.status === 'uploading'
      )
      
      if (hasActive && !interval) {
        // Start polling
        interval = setInterval(() => {
          if (mounted) {
            loadActiveUploads()
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
  }, [loadActiveUploads])

  return {
    activeUploads,
    hasActiveUploads: activeUploads.length > 0,
    isLoading,
  }
}
