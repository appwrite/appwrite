import { SCENE_EXIT_FRAMES } from './slide-transition'
import {
  SPLIT_FEATURE_ENTER_FRAMES,
  SPLIT_FEATURE_EXIT_FRAMES,
} from './split-feature-layout'
import {
  TITLE_FRAMES_PER_CHAR,
  TITLE_TYPE_DELAY,
  getTitleTypeDuration,
} from './title-scene-timing'

export const FEATURE_TITLE_HOLD_FRAMES = 12
export const FEATURE_CLIP_ENTER_FRAMES = 28
export const FEATURE_CLIP_EXIT_FRAMES = 28

export function getFeatureSceneDuration(
  text: string,
  videoDurationFrames: number,
  layout: 'default' | 'split' = 'default',
) {
  if (layout === 'split') {
    return (
      SPLIT_FEATURE_ENTER_FRAMES +
      videoDurationFrames +
      SPLIT_FEATURE_EXIT_FRAMES +
      SCENE_EXIT_FRAMES
    )
  }

  const titleLead = getTitleTypeDuration(text) + FEATURE_TITLE_HOLD_FRAMES

  return (
    titleLead +
    FEATURE_CLIP_ENTER_FRAMES +
    videoDurationFrames +
    FEATURE_CLIP_EXIT_FRAMES +
    SCENE_EXIT_FRAMES
  )
}

export function getFeatureTitlePhaseEnd(text: string) {
  return getTitleTypeDuration(text) + FEATURE_TITLE_HOLD_FRAMES
}

export { TITLE_TYPE_DELAY, TITLE_FRAMES_PER_CHAR }
