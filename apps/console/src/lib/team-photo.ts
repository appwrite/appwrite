/**
 * Version counter for team photos. A React `<img>` whose `src` does not change
 * never re-requests it, so bumping the version after `avatars.updateTeamPhoto` or
 * `avatars.deleteTeamPhoto` reloads every `TeamAvatar`.
 */
const TEAM_PHOTO_VERSION_EVENT = 'console:team-photo-version'

let teamPhotoVersion = 0

export function getTeamPhotoVersion(): number {
  return teamPhotoVersion
}

export function bumpTeamPhotoVersion(): number {
  teamPhotoVersion += 1
  if (typeof window !== 'undefined') {
    try {
      window.dispatchEvent(
        new CustomEvent(TEAM_PHOTO_VERSION_EVENT, {
          detail: { version: teamPhotoVersion },
        }),
      )
    } catch {
      // CustomEvent unavailable
    }
  }
  return teamPhotoVersion
}

export function subscribeTeamPhotoVersion(
  listener: (version: number) => void,
): () => void {
  if (typeof window === 'undefined') return () => {}

  const onChange = (event: Event) => {
    const detail = (event as CustomEvent<{ version: number }>).detail
    listener(detail?.version ?? teamPhotoVersion)
  }

  window.addEventListener(TEAM_PHOTO_VERSION_EVENT, onChange)
  return () => {
    window.removeEventListener(TEAM_PHOTO_VERSION_EVENT, onChange)
  }
}

/** Append the photo version to a `getPhoto` URL so the image reloads after a change. */
export function withTeamPhotoVersion(src: string, version: number): string {
  if (version <= 0) return src
  return `${src}${src.includes('?') ? '&' : '?'}v=${version}`
}
