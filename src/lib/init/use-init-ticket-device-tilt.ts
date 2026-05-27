import { useCallback, useEffect, useRef, useState } from 'react'

const BETA_SENSITIVITY = 0.55
const GAMMA_SENSITIVITY = 0.65

type DeviceOrientationEventConstructor = typeof DeviceOrientationEvent & {
  requestPermission?: () => Promise<PermissionState>
}

function clamp(value: number, min: number, max: number) {
  return Math.min(max, Math.max(min, value))
}

export function isInitTicketDeviceTiltSupported(): boolean {
  return typeof window !== 'undefined' && 'DeviceOrientationEvent' in window
}

export function prefersFinePointer(): boolean {
  if (typeof window === 'undefined') return true
  return window.matchMedia('(pointer: fine)').matches
}

export function prefersInitTicketDeviceTilt(): boolean {
  if (typeof window === 'undefined') return false
  if (prefersFinePointer()) return false
  return (
    window.matchMedia('(pointer: coarse)').matches ||
    window.matchMedia('(hover: none)').matches
  )
}

export function useInitTicketDeviceTilt({
  enabled,
  maxTiltX,
  maxTiltY,
  onTiltChange,
}: {
  enabled: boolean
  maxTiltX: number
  maxTiltY: number
  onTiltChange: (tilt: { x: number; y: number }) => void
}) {
  const baselineRef = useRef<{ beta: number; gamma: number } | null>(null)
  const listeningRef = useRef(false)
  const [listening, setListening] = useState(false)
  const onTiltChangeRef = useRef(onTiltChange)

  useEffect(() => {
    onTiltChangeRef.current = onTiltChange
  }, [onTiltChange])

  const isSupported = isInitTicketDeviceTiltSupported()

  const stopListening = useCallback(() => {
    listeningRef.current = false
    baselineRef.current = null
    setListening(false)
    onTiltChangeRef.current({ x: 0, y: 0 })
  }, [])

  const startListening = useCallback(async (): Promise<boolean> => {
    if (!enabled || !isSupported || listeningRef.current) {
      return listeningRef.current
    }

    const Orientation = DeviceOrientationEvent as DeviceOrientationEventConstructor
    if (typeof Orientation.requestPermission === 'function') {
      try {
        const permission = await Orientation.requestPermission()
        if (permission !== 'granted') return false
      } catch {
        return false
      }
    }

    baselineRef.current = null
    listeningRef.current = true
    setListening(true)
    return true
  }, [enabled, isSupported])

  useEffect(() => {
    if (!enabled || !listening) return

    const handleOrientation = (event: DeviceOrientationEvent) => {
      const { beta, gamma } = event
      if (beta == null || gamma == null) return

      if (!baselineRef.current) {
        baselineRef.current = { beta, gamma }
        return
      }

      const deltaBeta = beta - baselineRef.current.beta
      const deltaGamma = gamma - baselineRef.current.gamma

      onTiltChangeRef.current({
        x: clamp(-deltaBeta * BETA_SENSITIVITY, -maxTiltX, maxTiltX),
        y: clamp(deltaGamma * GAMMA_SENSITIVITY, -maxTiltY, maxTiltY),
      })
    }

    window.addEventListener('deviceorientation', handleOrientation, { passive: true })
    return () => {
      window.removeEventListener('deviceorientation', handleOrientation)
    }
  }, [enabled, listening, maxTiltX, maxTiltY])

  useEffect(() => {
    if (!enabled) {
      stopListening()
    }
  }, [enabled, stopListening])

  return {
    isSupported,
    listening,
    startListening,
    stopListening,
  }
}
