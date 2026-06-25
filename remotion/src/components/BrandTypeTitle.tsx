import { interpolate, useCurrentFrame } from 'remotion'
import {
  TITLE_FRAMES_PER_CHAR,
  TITLE_TYPE_DELAY,
  getTitleTypeDuration,
} from '../lib/title-scene-timing'

const CURSOR_BLINK_CYCLE = 14

type BrandTypeTitleProps = {
  text: string
  size?: 'md' | 'lg' | 'xl' | 'hero'
  variant?: 'default' | 'gradient'
  delay?: number
  framesPerChar?: number
  /** Override timeline frame (e.g. beat-local frame inside a sequence). */
  frame?: number
  className?: string
}

function TypeCursor({
  fontSize,
  visible,
  frame,
}: {
  fontSize: number
  visible: boolean
  frame: number
}) {
  const blink = interpolate(
    frame % CURSOR_BLINK_CYCLE,
    [0, CURSOR_BLINK_CYCLE / 2, CURSOR_BLINK_CYCLE],
    [1, 0.25, 1],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
  )

  return (
    <span
      className="font-aeonik"
      style={{
        fontSize,
        lineHeight: 1,
        color: '#fd366e',
        opacity: visible ? blink : 1,
      }}
    >
      _
    </span>
  )
}

export function BrandTypeTitle({
  text,
  size = 'lg',
  variant = 'default',
  delay = TITLE_TYPE_DELAY,
  framesPerChar = TITLE_FRAMES_PER_CHAR,
  frame: frameOverride,
  className,
}: BrandTypeTitleProps) {
  const sequenceFrame = useCurrentFrame()
  const frame = frameOverride ?? sequenceFrame
  const elapsed = Math.max(0, frame - delay)
  const typedCount = Math.min(text.length, Math.floor(elapsed / framesPerChar))
  const visible = text.slice(0, typedCount)
  const isTyping = typedCount < text.length

  const fontSize =
    size === 'hero' ? 220 : size === 'xl' ? 96 : size === 'lg' ? 84 : 52

  if (variant === 'gradient') {
    return (
      <div
        className={`flex items-baseline justify-center whitespace-nowrap ${className ?? ''}`}
      >
        <span
          className="font-aeonik text-gradient-brand inline-block shrink-0 tracking-[-0.022em]"
          style={{ fontSize, lineHeight: 1, paddingRight: '0.075em' }}
        >
          {visible}
        </span>
        <TypeCursor fontSize={fontSize} visible={isTyping} frame={frame} />
      </div>
    )
  }

  if (size === 'md') {
    return (
      <p
        className={`font-aeonik whitespace-nowrap text-center tracking-[-0.022em] ${className ?? ''}`}
        style={{ fontSize, color: '#fafafa' }}
      >
        {visible}
        <TypeCursor fontSize={fontSize} visible={isTyping} frame={frame} />
      </p>
    )
  }

  return (
    <h1
      className={`font-aeonik whitespace-nowrap text-center leading-none tracking-[-0.022em] ${className ?? ''}`}
      style={{ fontSize, color: '#fafafa' }}
    >
      {visible}
      <TypeCursor fontSize={fontSize} visible={isTyping} frame={frame} />
    </h1>
  )
}

/** Frames until typing finishes (for sequencing multiple lines). */
export function getTypeDuration(
  text: string,
  delay = TITLE_TYPE_DELAY,
  framesPerChar = TITLE_FRAMES_PER_CHAR,
) {
  return getTitleTypeDuration(text, delay, framesPerChar)
}
