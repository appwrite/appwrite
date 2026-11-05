const GUEST_DISMISSED_BANNERS_STORAGE_KEY = 'console.dismissedBanners.guest'

function readGuestDismissedBannerIds(): string[] {
  if (typeof window === 'undefined') return []
  try {
    const raw = window.localStorage.getItem(GUEST_DISMISSED_BANNERS_STORAGE_KEY)
    if (!raw) return []
    return raw.split(',').filter(Boolean)
  } catch {
    return []
  }
}

function writeGuestDismissedBannerIds(ids: string[]): void {
  if (typeof window === 'undefined') return
  try {
    if (ids.length === 0) {
      window.localStorage.removeItem(GUEST_DISMISSED_BANNERS_STORAGE_KEY)
      return
    }
    window.localStorage.setItem(
      GUEST_DISMISSED_BANNERS_STORAGE_KEY,
      ids.join(','),
    )
  } catch {
    // ignore quota / private mode
  }
}

export function isGuestConsoleBannerDismissed(bannerId: string): boolean {
  return readGuestDismissedBannerIds().includes(bannerId)
}

export function persistGuestConsoleBannerDismiss(bannerId: string): void {
  const current = readGuestDismissedBannerIds()
  if (current.includes(bannerId)) return
  writeGuestDismissedBannerIds([...current, bannerId])
}

/** Device marker for one account (`<bannerId>:<accountId>`). */
export function getAccountScopedConsoleBannerId(
  bannerId: string,
  accountId: string,
): string {
  return `${bannerId}:${accountId}`
}

/** Clears the banner's marker and every account-scoped marker for it. */
export function clearGuestConsoleBannerDismiss(bannerId: string): void {
  writeGuestDismissedBannerIds(
    readGuestDismissedBannerIds().filter(
      (id) => id !== bannerId && !id.startsWith(`${bannerId}:`),
    ),
  )
}
