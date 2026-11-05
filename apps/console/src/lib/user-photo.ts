/**
 * Version counter for the signed-in console user's profile photo.
 *
 * `avatars.getPhoto` is served with `Cache-Control: private, no-store`, so the
 * browser never caches it, but a React `<img>` whose `src` does not change
 * never re-requests it either. Bumping the version after `updatePhoto` or
 * `deletePhoto` changes the `src` of every `PhotoAvatar` showing the current
 * user, so the header menu, the account page, and the Init presence badge all
 * reload together.
 */
const USER_PHOTO_VERSION_EVENT = 'console:user-photo-version'

let userPhotoVersion = 0

export function getUserPhotoVersion(): number {
  return userPhotoVersion
}

export function bumpUserPhotoVersion(): number {
  userPhotoVersion += 1
  if (typeof window !== 'undefined') {
    try {
      window.dispatchEvent(
        new CustomEvent(USER_PHOTO_VERSION_EVENT, {
          detail: { version: userPhotoVersion },
        }),
      )
    } catch {
      // CustomEvent unavailable
    }
  }
  return userPhotoVersion
}

export function subscribeUserPhotoVersion(
  listener: (version: number) => void,
): () => void {
  if (typeof window === 'undefined') return () => {}

  const onChange = (event: Event) => {
    const detail = (event as CustomEvent<{ version: number }>).detail
    listener(detail?.version ?? userPhotoVersion)
  }

  window.addEventListener(USER_PHOTO_VERSION_EVENT, onChange)
  return () => {
    window.removeEventListener(USER_PHOTO_VERSION_EVENT, onChange)
  }
}

/** Append the photo version to a `getPhoto` URL so the image reloads after a change. */
export function withUserPhotoVersion(src: string, version: number): string {
  if (version <= 0) return src
  return `${src}${src.includes('?') ? '&' : '?'}v=${version}`
}
