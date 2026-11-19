import { Permission, Role } from '@appwrite.io/console'

const PUBLIC_READ = Permission.read(Role.any())

/**
 * Every playback request (manifest, segments, captions, thumbnails, timeline)
 * is authorized against the video's own read permissions, never the source
 * file's. Project members and API keys skip the check. Visitors are guests,
 * so only read for Any lets them watch on the player page and in embeds.
 */
export function isVideoPublic(video: { $permissions: string[] }): boolean {
  return video.$permissions.includes(PUBLIC_READ)
}

/**
 * Read roles other than Any, such as Users, a team or a user. They grant
 * playback only where the viewer has a session for the project: in your app,
 * not on the Console player page.
 */
export function hasSignedInReaders(video: { $permissions: string[] }): boolean {
  return video.$permissions.some(
    (permission) =>
      permission !== PUBLIC_READ && permission.startsWith('read('),
  )
}

/** The video's permissions with public read added or removed. */
export function withPublicPlayback(
  permissions: string[],
  enabled: boolean,
): string[] {
  const rest = permissions.filter((permission) => permission !== PUBLIC_READ)
  return enabled ? [...rest, PUBLIC_READ] : rest
}
