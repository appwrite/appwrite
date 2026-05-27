import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

type InitTicketVideoRecordingContextValue = {
  isCapturing: boolean
  setIsCapturing: (capturing: boolean) => void
}

const InitTicketVideoRecordingContext =
  createContext<InitTicketVideoRecordingContextValue | null>(null)

export function InitTicketVideoRecordingProvider({
  children,
}: {
  children: ReactNode
}) {
  const [isCapturing, setIsCapturing] = useState(false)

  useEffect(() => {
    if (isCapturing) {
      document.documentElement.dataset.initTicketVideoCapturing = ''
    } else {
      delete document.documentElement.dataset.initTicketVideoCapturing
    }
    return () => {
      delete document.documentElement.dataset.initTicketVideoCapturing
    }
  }, [isCapturing])

  const value = useMemo(
    () => ({ isCapturing, setIsCapturing }),
    [isCapturing],
  )

  return (
    <InitTicketVideoRecordingContext.Provider value={value}>
      {children}
    </InitTicketVideoRecordingContext.Provider>
  )
}

export function useInitTicketVideoRecording() {
  const context = useContext(InitTicketVideoRecordingContext)
  if (!context) {
    return {
      isCapturing: false,
      setIsCapturing: () => undefined,
    }
  }
  return context
}
