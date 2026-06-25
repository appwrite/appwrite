import { Audio, interpolate, Sequence, staticFile } from 'remotion'
import {
  TITLE_FRAMES_PER_CHAR,
  TITLE_TYPE_DELAY,
} from '../lib/title-scene-timing'
import { getTypingKeystrokeOffset } from '../lib/typing-keyboard-sound'
import {
  TYPING_SOUND_CLIP_FRAMES,
  TYPING_SOUND_SRC,
  TYPING_SOUND_VOLUME,
} from '../constants'

type TypingSoundEffectsProps = {
  text: string
  delay?: number
  framesPerChar?: number
  volume?: number
}

function getKeystrokeVolume(index: number, base: number) {
  const variation = [0, 0.025, -0.018, 0.015, -0.012][index % 5]
  return Math.max(0.15, Math.min(0.55, base + variation))
}

/** Mechanical keyboard clicks from Dragon Studio ASMR sample. */
export function TypingSoundEffects({
  text,
  delay = TITLE_TYPE_DELAY,
  framesPerChar = TITLE_FRAMES_PER_CHAR,
  volume = TYPING_SOUND_VOLUME,
}: TypingSoundEffectsProps) {
  if (!text.length) return null

  return (
    <>
      {Array.from({ length: text.length }, (_, index) => {
        const from = delay + (index + 1) * framesPerChar
        const char = text[index] ?? ' '
        const keystrokeVolume = getKeystrokeVolume(index, volume)
        const startFrom = getTypingKeystrokeOffset(index, char)

        return (
          <Sequence
            key={`${from}-${char}-${index}`}
            from={from}
            durationInFrames={TYPING_SOUND_CLIP_FRAMES}
            layout="none"
          >
            <Audio
              src={staticFile(TYPING_SOUND_SRC)}
              startFrom={startFrom}
              endAt={startFrom + TYPING_SOUND_CLIP_FRAMES}
              useWebAudioApi={false}
              volume={(frame) =>
                interpolate(
                  frame,
                  [0, 1, TYPING_SOUND_CLIP_FRAMES - 1, TYPING_SOUND_CLIP_FRAMES],
                  [0, keystrokeVolume, keystrokeVolume * 0.4, 0],
                  { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
                )
              }
            />
          </Sequence>
        )
      })}
    </>
  )
}
