import { Permission, Role } from '@appwrite.io/console'

const PUBLIC_READ = Permission.read(Role.any())

/**
 * Streams are authorized against the video's own read permissions. Without
 * read for Any, visitors get a 404 on the player page and in embeds.
 */
export function isVideoPublic(video: { $permissions: string[] }): boolean {
  return video.$permissions.includes(PUBLIC_READ)
}

/** The video's permissions with public read added or removed. */
export function withPublicPlayback(
  permissions: string[],
  enabled: boolean,
): string[] {
  const rest = permissions.filter((permission) => permission !== PUBLIC_READ)
  return enabled ? [...rest, PUBLIC_READ] : rest
}
