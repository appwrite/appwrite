import { Audio, Easing, interpolate, Sequence, staticFile } from 'remotion'
import { SWOOSH_SOURCE_FRAMES } from '../constants'

type EnterSwooshSoundProps = {
  src: string
  durationFrames: number
  startFrame?: number
  peakVolume?: number
}

/** Motion swoosh synced to a card/browser enter animation. */
export function EnterSwooshSound({
  src,
  durationFrames,
  startFrame = 0,
  peakVolume = 0.28,
}: EnterSwooshSoundProps) {
  const playbackFrames = Math.min(durationFrames, SWOOSH_SOURCE_FRAMES)

  return (
    <Sequence
      from={startFrame}
      durationInFrames={durationFrames}
      layout="none"
    >
      <Audio
        src={staticFile(src)}
        trimAfter={playbackFrames}
        useWebAudioApi={false}
        volume={(frame) => {
          const progress = frame / Math.max(1, playbackFrames - 1)

          const attack = interpolate(frame, [0, 2], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          })

          const motion = interpolate(progress, [0, 0.4, 1], [0.6, 1, 0.2], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
            easing: Easing.out(Easing.cubic),
          })

          const release = interpolate(
            frame,
            [playbackFrames * 0.5, playbackFrames],
            [1, 0],
            {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
              easing: Easing.in(Easing.quad),
            },
          )

          return peakVolume * attack * motion * release
        }}
      />
    </Sequence>
  )
}
