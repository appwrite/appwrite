/**
 * Component that warns users when they try to close the window during active uploads
 */

import { useEffect } from 'react'
import { useActiveUploads } from '@/hooks/use-active-uploads'

export function UploadWarning() {
  const { hasActiveUploads } = useActiveUploads()

  useEffect(() => {
    if (!hasActiveUploads) {
      return
    }

    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      // Modern browsers ignore custom messages, but we still need to set returnValue
      e.preventDefault()
      e.returnValue = 'You have file uploads in progress. Are you sure you want to leave?'
      return e.returnValue
    }

    window.addEventListener('beforeunload', handleBeforeUnload)

    return () => {
      window.removeEventListener('beforeunload', handleBeforeUnload)
    }
  }, [hasActiveUploads])

  // This component doesn't render anything
  return null
}

