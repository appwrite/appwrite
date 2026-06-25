import {
  Easing,
  Img,
  interpolate,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion'

type AppwriteLogotypeProps = {
  width?: number
  enterDelay?: number
  fadeDuration?: number
  fadeOutDuration?: number
  fadeOutStart?: number
  variant?: 'fade' | 'motion'
}

/** Full Appwrite logotype (homepage auth logo asset). */
export function AppwriteLogotype({
  width = 520,
  enterDelay = 0,
  fadeDuration = 45,
  fadeOutDuration,
  fadeOutStart,
  variant = 'motion',
}: AppwriteLogotypeProps) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  if (variant === 'fade') {
    const localFrame = frame - enterDelay

    const fadeInOpacity = interpolate(
      localFrame,
      [0, fadeDuration],
      [0, 1],
      {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: Easing.out(Easing.quad),
      },
    )

    const fadeOutOpacity =
      fadeOutDuration !== undefined && fadeOutStart !== undefined
        ? interpolate(
            localFrame,
            [fadeOutStart, fadeOutStart + fadeOutDuration],
            [1, 0],
            {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
              easing: Easing.in(Easing.quad),
            },
          )
        : 1

    const opacity = Math.min(fadeInOpacity, fadeOutOpacity)

    return (
      <div className="flex items-center justify-center" style={{ width, opacity }}>
        <Img
          src={staticFile('appwrite-dark.svg')}
          style={{ width, height: 'auto' }}
        />
      </div>
    )
  }

  const enter = spring({
    frame: frame - enterDelay,
    fps,
    config: { damping: 200 },
    durationInFrames: 50,
  })

  const enterOpacity = interpolate(enter, [0, 1], [0, 1])
  const enterScale = interpolate(enter, [0, 1], [0.94, 1])
  const enterY = interpolate(enter, [0, 1], [18, 0])

  const floatY = Math.sin((frame + enterDelay) / 38) * 3
  const breathe = interpolate(Math.sin((frame + enterDelay) / 52), [-1, 1], [0.995, 1.008])

  return (
    <div
      className="relative flex items-center justify-center"
      style={{
        width,
        opacity: enterOpacity,
        transform: `translateY(${enterY + floatY}px) scale(${enterScale * breathe})`,
      }}
    >
      <Img
        src={staticFile('appwrite-dark.svg')}
        style={{ width, height: 'auto' }}
      />
    </div>
  )
}
