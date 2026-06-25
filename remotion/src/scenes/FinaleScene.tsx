import { AbsoluteFill } from 'remotion'
import { AppwriteLogotype } from '../components/AppwriteLogotype'
import {
  FINALE_LOGO_FADE_FRAMES,
  FINALE_LOGO_FADE_OUT_FRAMES,
  FINALE_LOGO_FADE_OUT_START,
} from '../constants'

export function FinaleScene() {
  return (
    <AbsoluteFill className="flex items-center justify-center">
      <AppwriteLogotype
        width={400}
        variant="fade"
        fadeDuration={FINALE_LOGO_FADE_FRAMES}
        fadeOutDuration={FINALE_LOGO_FADE_OUT_FRAMES}
        fadeOutStart={FINALE_LOGO_FADE_OUT_START}
      />
    </AbsoluteFill>
  )
}
