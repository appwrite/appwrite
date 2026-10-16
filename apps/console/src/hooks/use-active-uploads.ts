/**
 * Global hook to track all active uploads across all buckets
 * Used for window close warnings and global upload status
 */

import { useState, useEffect, useCallback, useRef } from 'react'
import { uploadManager } from '@/lib/upload-queue/upload-manager'
import type { UploadItem } from '@/lib/upload-queue/types'

const POLL_INTERVAL_MS = 600

export function useActiveUploads() {
  const [activeUploads, setActiveUploads] = useState<UploadItem[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const listenersRef = useRef<Map<string, () => void>>(new Map())

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

  // Poll frequently so we discover new uploads quickly (e.g. right after user queues one)
  useEffect(() => {
    loadActiveUploads()
    const interval = setInterval(loadActiveUploads, POLL_INTERVAL_MS)
    return () => clearInterval(interval)
  }, [loadActiveUploads])

  // Register progress listeners for each active upload so we get smooth updates and completion events
  useEffect(() => {
    const listeners = listenersRef.current
    const currentIds = new Set(activeUploads.map((u) => u.id))

    // Remove listeners for uploads no longer in the list (e.g. completed)
    listeners.forEach((unsub, id) => {
      if (!currentIds.has(id)) {
        unsub()
        listeners.delete(id)
      }
    })

    // Add listeners for new uploads
    activeUploads.forEach((item) => {
      if (!listeners.has(item.id)) {
        const unsubscribe = uploadManager.onProgress(item.id, () => {
          loadActiveUploads()
        })
        listeners.set(item.id, unsubscribe)
      }
    })

    return () => {
      listeners.forEach((unsub) => unsub())
      listeners.clear()
    }
  }, [activeUploads, loadActiveUploads])

  return {
    activeUploads,
    hasActiveUploads: activeUploads.length > 0,
    isLoading,
  }
}
