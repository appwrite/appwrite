const WEBGL_CONTEXT_ERROR =
  /Error creating WebGL context|Could not create a WebGL context/i

/** Whether the current environment can create a WebGL rendering context. */
export function canUseWebGL(): boolean {
  if (typeof document === 'undefined') return false

  try {
    const canvas = document.createElement('canvas')
    const gl =
      canvas.getContext('webgl2') ||
      canvas.getContext('webgl') ||
      canvas.getContext('experimental-webgl')

    return gl !== null
  } catch {
    return false
  }
}

/** Three.js / react-three-fiber errors when WebGL is blocked or unavailable. */
export function isWebGLContextError(error: unknown): boolean {
  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : ''

  return WEBGL_CONTEXT_ERROR.test(message)
}
