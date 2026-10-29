import { useEffect, useState } from 'react'
import { canUseWebGL } from '@/lib/webgl'

/** `null` until checked on the client; `false` when WebGL is unavailable. */
export function useWebGLAvailable(): boolean | null {
  const [available, setAvailable] = useState<boolean | null>(null)

  useEffect(() => {
    setAvailable(canUseWebGL())
  }, [])

  return available
}
