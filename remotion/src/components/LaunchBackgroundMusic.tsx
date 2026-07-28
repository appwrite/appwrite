import {
  Audio,
  Easing,
  interpolate,
  Sequence,
  staticFile,
  useVideoConfig,
} from 'remotion'
import {
  DURATION_IN_FRAMES,
  FINALE_LOGO_FADE_OUT_START,
  LAUNCH_MUSIC_FADE_IN_FRAMES,
  LAUNCH_MUSIC_FADE_OUT_FRAMES,
  LAUNCH_MUSIC_HOLD_AT_ZERO_FRAMES,
  LAUNCH_MUSIC_PEAK_VOLUME,
  LAUNCH_MUSIC_SRC,
  LAUNCH_MUSIC_START_DELAY_FRAMES,
  LAUNCH_MUSIC_TRIM_START_FRAMES,
  SCENES,
} from '../constants'

/** Corporate background bed - loops and fades with the finale. */
export function LaunchBackgroundMusic() {
  const { durationInFrames } = useVideoConfig()
  const totalFrames = durationInFrames ?? DURATION_IN_FRAMES
  const playbackFrames = totalFrames - LAUNCH_MUSIC_START_DELAY_FRAMES

  const fadeOutStart =
    SCENES.finale.from +
    FINALE_LOGO_FADE_OUT_START -
    LAUNCH_MUSIC_FADE_OUT_FRAMES * 0.35 -
    LAUNCH_MUSIC_START_DELAY_FRAMES

  return (
    <Sequence
      from={LAUNCH_MUSIC_START_DELAY_FRAMES}
      durationInFrames={playbackFrames}
      layout="none"
    >
      <Audio
        src={staticFile(LAUNCH_MUSIC_SRC)}
        startFrom={LAUNCH_MUSIC_TRIM_START_FRAMES}
        endAt={playbackFrames}
        loop
        loopVolumeCurveBehavior="extend"
        useWebAudioApi={false}
        volume={(frame) => {
          const fadeIn = interpolate(
            frame,
            [
              LAUNCH_MUSIC_HOLD_AT_ZERO_FRAMES,
              LAUNCH_MUSIC_HOLD_AT_ZERO_FRAMES + LAUNCH_MUSIC_FADE_IN_FRAMES,
            ],
            [0, LAUNCH_MUSIC_PEAK_VOLUME],
            {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
              easing: Easing.out(Easing.cubic),
            },
          )

          const fadeOut = interpolate(
            frame,
            [fadeOutStart, fadeOutStart + LAUNCH_MUSIC_FADE_OUT_FRAMES],
            [LAUNCH_MUSIC_PEAK_VOLUME, 0],
            {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
              easing: Easing.in(Easing.cubic),
            },
          )

          return Math.min(fadeIn, fadeOut)
        }}
      />
    </Sequence>
  )
}
