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
    if (isHttpUnauthorizedError(error)) {
      return {
        kind: 'unauthorized',
        title: 'Sign in required',
        message:
          'You need permission to watch this video. Sign in with an account that can access it, or ask the owner to make the stream public.',
      }
    }
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
    message:
      'Something went wrong while loading this video. Try again later.',
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
