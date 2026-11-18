/**
 * Version counter for team photos. A React `<img>` whose `src` does not change
 * never re-requests it, so bumping the version after `teams.updatePhoto` or
 * `teams.deletePhoto` reloads every `TeamAvatar`.
 */
const TEAM_PHOTO_VERSION_EVENT = 'console:team-photo-version'

let teamPhotoVersion = 0

export function getTeamPhotoVersion(): number {
  return teamPhotoVersion
}

export function bumpTeamPhotoVersion(): void {
  teamPhotoVersion += 1
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new Event(TEAM_PHOTO_VERSION_EVENT))
  }
}

export function subscribeTeamPhotoVersion(listener: () => void): () => void {
  if (typeof window === 'undefined') return () => {}

  window.addEventListener(TEAM_PHOTO_VERSION_EVENT, listener)
  return () => {
    window.removeEventListener(TEAM_PHOTO_VERSION_EVENT, listener)
  }
}
