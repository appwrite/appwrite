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
  if (typeof window === 'undefined') return false
  return 'DeviceOrientationEvent' in window || 'DeviceMotionEvent' in window
}

export function prefersFinePointer(): boolean {
  if (typeof window === 'undefined') return true
  return window.matchMedia('(pointer: fine)').matches
}

export function isIOSDevice(): boolean {
  if (typeof navigator === 'undefined') return false
  return (
    /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  )
}

export function prefersInitTicketDeviceTilt(): boolean {
  if (typeof window === 'undefined') return false
  if (isIOSDevice()) return true
  if (prefersFinePointer()) return false
  return (
    window.matchMedia('(pointer: coarse)').matches ||
    window.matchMedia('(hover: none)').matches
  )
}

export function requestInitTicketDeviceOrientationAccessFromGesture(): Promise<boolean> {
  if (!isInitTicketDeviceTiltSupported()) return Promise.resolve(false)

  const Orientation = DeviceOrientationEvent as DeviceOrientationEventConstructor
  if (typeof Orientation.requestPermission !== 'function') {
    return Promise.resolve(true)
  }

  try {
    return Orientation.requestPermission().then((state) => state === 'granted')
  } catch {
    return Promise.resolve(false)
  }
}

export async function requestInitTicketDeviceOrientationAccess(): Promise<boolean> {
  return requestInitTicketDeviceOrientationAccessFromGesture()
}

function tiltFromGravity(x: number, y: number, z: number) {
  const beta = (Math.atan2(y, Math.hypot(x, z)) * 180) / Math.PI
  const gamma = (Math.atan2(x, z) * 180) / Math.PI
  return { beta, gamma }
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
  const orientationActiveRef = useRef(false)
  const listeningRef = useRef(false)
  const [listening, setListening] = useState(false)
  const onTiltChangeRef = useRef(onTiltChange)

  useEffect(() => {
    onTiltChangeRef.current = onTiltChange
  }, [onTiltChange])

  const isSupported = isInitTicketDeviceTiltSupported()

  const applyTiltFromAngles = useCallback(
    (beta: number, gamma: number) => {
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
    },
    [maxTiltX, maxTiltY],
  )

  const stopListening = useCallback(() => {
    listeningRef.current = false
    baselineRef.current = null
    orientationActiveRef.current = false
    setListening(false)
    onTiltChangeRef.current({ x: 0, y: 0 })
  }, [])

  const startListening = useCallback(async (options?: {
    /** Set when iOS permission was already requested in the same user gesture. */
    skipPermission?: boolean
  }): Promise<boolean> => {
    if (!enabled || !isSupported || listeningRef.current) {
      return listeningRef.current
    }

    if (!options?.skipPermission) {
      const granted = await requestInitTicketDeviceOrientationAccess()
      if (!granted) return false
    }

    baselineRef.current = null
    orientationActiveRef.current = false
    listeningRef.current = true
    setListening(true)
    return true
  }, [enabled, isSupported])

  useEffect(() => {
    if (!enabled || !listening) return

    const handleOrientation = (event: DeviceOrientationEvent) => {
      const { beta, gamma } = event
      if (beta == null || gamma == null) return

      orientationActiveRef.current = true
      applyTiltFromAngles(beta, gamma)
    }

    const handleMotion = (event: DeviceMotionEvent) => {
      if (orientationActiveRef.current) return

      const gravity = event.accelerationIncludingGravity
      if (!gravity || gravity.x == null || gravity.y == null || gravity.z == null) {
        return
      }

      const { beta, gamma } = tiltFromGravity(gravity.x, gravity.y, gravity.z)
      applyTiltFromAngles(beta, gamma)
    }

    window.addEventListener('deviceorientation', handleOrientation, { passive: true })
    window.addEventListener('devicemotion', handleMotion, { passive: true })

    return () => {
      window.removeEventListener('deviceorientation', handleOrientation)
      window.removeEventListener('devicemotion', handleMotion)
    }
  }, [applyTiltFromAngles, enabled, listening])

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
