import { AppwriteException } from '@appwrite.io/console'
import {
  isHttpForbiddenError,
  isHttpUnauthorizedError,
} from '@/lib/utils/error-formatting'

export type VideoEmbedErrorKind =
  | 'missing-project'
  | 'not-found'
  | 'unauthorized'
  | 'forbidden'
  | 'unavailable'
  | 'unknown'

export type VideoEmbedError = {
  kind: VideoEmbedErrorKind
  title: string
  message: string
}

export function videoEmbedErrorFromUnknown(error: unknown): VideoEmbedError {
  if (error instanceof AppwriteException) {
    if (error.code === 404 || error.type === 'video_not_found') {
      return {
        kind: 'not-found',
        title: 'Video not found',
        message:
          'This video does not exist or was removed. Check the link and try again.',
      }
    }
    if (isHttpUnauthorizedError(error)) return VIDEO_EMBED_UNAUTHORIZED
    if (isHttpForbiddenError(error)) {
      return {
        kind: 'forbidden',
        title: 'Access denied',
        message:
          'Your account cannot play this video. The owner may need to update bucket or video permissions.',
      }
    }
    if (isShortUserMessage(error.message)) {
      return {
        kind: 'unknown',
        title: 'Unable to play',
        message: error.message,
      }
    }
  }

  return {
    kind: 'unknown',
    title: 'Unable to play',
    message: 'Something went wrong while loading this video. Try again later.',
  }
}

function isShortUserMessage(message: string | undefined): boolean {
  if (!message?.trim()) return false
  return (
    message.length <= 400 &&
    !message.includes('\n') &&
    !message.includes(' at ')
  )
}

export const VIDEO_EMBED_UNAUTHORIZED: VideoEmbedError = {
  kind: 'unauthorized',
  title: 'This video is private',
  message: 'Sign in to watch it, or ask the owner to make it public.',
}

/** Native playback could not load the stream (the status is known). */
export const PLAYBACK_LOAD_FAILED = 'The stream could not be loaded.'
/** Native playback failed without an HTTP status. */
export const PLAYBACK_FAILED = 'The stream could not be played.'

/** Playback messages written by the Console, so they can be translated. */
export function isConsolePlaybackMessage(
  message: string | null | undefined,
): message is string {
  return (
    isEmbedUnauthorizedMessage(message) ||
    message === PLAYBACK_LOAD_FAILED ||
    message === PLAYBACK_FAILED
  )
}

export function isEmbedUnauthorizedMessage(
  message: string | null | undefined,
): boolean {
  return message === VIDEO_EMBED_UNAUTHORIZED.message
}

/** User-facing playback failure. A 401 uses the private-video copy. */
export function playbackFatalMessage(
  httpStatus: number | undefined,
  fallback: string,
): string {
  if (httpStatus === 401) return VIDEO_EMBED_UNAUTHORIZED.message
  return fallback
}

export const VIDEO_EMBED_MISSING_PROJECT: VideoEmbedError = {
  kind: 'missing-project',
  title: 'Missing project',
  message:
    'Add a project query parameter to this URL, for example ?project=yourProjectId.',
}

export const VIDEO_EMBED_NOT_READY: VideoEmbedError = {
  kind: 'unavailable',
  title: 'Not ready to play',
  message: 'This video is still being prepared. Try again in a few minutes.',
}
