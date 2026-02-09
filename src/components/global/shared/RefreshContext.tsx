import { createContext, useContext, useState, useCallback, useRef } from 'react'
import { toast } from 'sonner'

const MIN_ANIMATION_DURATION = 500

interface RefreshContextValue {
  isRefreshing: boolean
  registerRefreshHandler: (handler: () => Promise<void>, label?: string) => void
  unregisterRefreshHandler: () => void
  triggerRefresh: () => Promise<void>
  hasRefreshHandler: boolean
}

const RefreshContext = createContext<RefreshContextValue | null>(null)

export function RefreshProvider({ children }: { children: React.ReactNode }) {
  const [isRefreshing, setIsRefreshing] = useState(false)
  const refreshHandlerRef = useRef<(() => Promise<void>) | null>(null)
  const refreshLabelRef = useRef<string>('data')
  const refreshStartTimeRef = useRef<number | null>(null)
  const [hasRefreshHandler, setHasRefreshHandler] = useState(false)

  const registerRefreshHandler = useCallback(
    (handler: () => Promise<void>, label = 'data') => {
      refreshHandlerRef.current = handler
      refreshLabelRef.current = label
      setHasRefreshHandler(true)
    },
    [],
  )

  const unregisterRefreshHandler = useCallback(() => {
    refreshHandlerRef.current = null
    refreshLabelRef.current = 'data'
    setHasRefreshHandler(false)
  }, [])

  const triggerRefresh = useCallback(async () => {
    if (!refreshHandlerRef.current) return

    refreshStartTimeRef.current = Date.now()
    setIsRefreshing(true)
    try {
      await refreshHandlerRef.current()
      // Ensure minimum animation duration
      const elapsed = Date.now() - (refreshStartTimeRef.current || 0)
      const remaining = Math.max(0, MIN_ANIMATION_DURATION - elapsed)
      await new Promise((resolve) => setTimeout(resolve, remaining))
      toast.success(`${refreshLabelRef.current} refreshed successfully`)
    } catch {
      toast.error(`Failed to refresh ${refreshLabelRef.current.toLowerCase()}`)
    } finally {
      setIsRefreshing(false)
      refreshStartTimeRef.current = null
    }
  }, [])

  return (
    <RefreshContext.Provider
      value={{
        isRefreshing,
        registerRefreshHandler,
        unregisterRefreshHandler,
        triggerRefresh,
        hasRefreshHandler,
      }}
    >
      {children}
    </RefreshContext.Provider>
  )
}

export function useRefresh() {
  const context = useContext(RefreshContext)
  if (!context) {
    throw new Error('useRefresh must be used within a RefreshProvider')
  }
  return context
}

export function useRefreshOptional() {
  return useContext(RefreshContext)
}
