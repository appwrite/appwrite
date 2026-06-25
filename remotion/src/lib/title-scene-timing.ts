import { SCENE_EXIT_FRAMES } from './slide-transition'

export const TITLE_TYPE_DELAY = 4
export const TITLE_FRAMES_PER_CHAR = 2
export const TITLE_HOLD_FRAMES = 20

export function getTitleTypeDuration(
  text: string,
  delay = TITLE_TYPE_DELAY,
  framesPerChar = TITLE_FRAMES_PER_CHAR,
) {
  return delay + text.length * framesPerChar
}

export function getTitleSceneDuration(text: string) {
  return (
    getTitleTypeDuration(text) + TITLE_HOLD_FRAMES + SCENE_EXIT_FRAMES
  )
}
