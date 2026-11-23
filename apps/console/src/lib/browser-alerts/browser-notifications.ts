export type BrowserNotificationPermission =
  | NotificationPermission
  | 'unsupported'

export type BrowserAlertShowResult =
  | 'shown'
  | 'denied'
  | 'unsupported'
  | 'background'
  | 'failed'

/** Keep strong references so notifications are not GC'd before they appear. */
const activeNotifications = new Map<string, Notification>()

export function notificationsSupported(): boolean {
  return typeof window !== 'undefined' && typeof Notification !== 'undefined'
}

export function getNotificationPermission(): BrowserNotificationPermission {
  if (!notificationsSupported()) return 'unsupported'
  return Notification.permission
}

export async function requestNotificationPermission(): Promise<BrowserNotificationPermission> {
  if (!notificationsSupported()) return 'unsupported'
  try {
    const result = Notification.requestPermission()
    if (
      result &&
      typeof (result as Promise<NotificationPermission>).then === 'function'
    ) {
      return await (result as Promise<NotificationPermission>)
    }
    if (typeof result === 'string') {
      return result as NotificationPermission
    }
  } catch {
    // Ignore - some browsers throw if the call comes too late after the click.
  }
  return getNotificationPermission()
}

function isPageInBackground(): boolean {
  if (typeof document === 'undefined') return false
  if (document.visibilityState === 'hidden') return true
  return typeof document.hasFocus === 'function' && !document.hasFocus()
}

export { isPageInBackground }

function releaseNotification(tag: string): void {
  activeNotifications.delete(tag)
}

function createBrowserNotification(
  title: string,
  body: string,
  tag: string,
  options?: { requireInteraction?: boolean },
): Notification | null {
  try {
    const notification = new Notification(title, {
      body,
      icon: new URL('/logo.svg', window.location.origin).href,
      tag,
      requireInteraction: options?.requireInteraction ?? false,
    })

    activeNotifications.get(tag)?.close()
    activeNotifications.set(tag, notification)

    notification.onclick = () => {
      try {
        window.focus()
        notification.close()
      } catch {
        // Ignore focus failures.
      }
    }
    notification.onclose = () => releaseNotification(tag)
    notification.onerror = () => releaseNotification(tag)

    return notification
  } catch (err) {
    console.warn('[BrowserAlerts] failed to show notification', err)
    return null
  }
}

export function showBrowserAlert(
  title: string,
  body: string,
  options?: { tag?: string; force?: boolean },
): boolean {
  if (!notificationsSupported()) return false
  if (Notification.permission !== 'granted') return false

  if (!options?.force && !isPageInBackground()) return false

  const tag = options?.tag ?? `appwrite-browser-alert-${Date.now()}`
  return createBrowserNotification(title, body, tag, {
    requireInteraction: !options?.force,
  }) !== null
}

/**
 * Test helper: wait until the OS shows the notification or reports an error.
 * Uses a unique tag on every call so repeat tests are not swallowed.
 */
export function showTestBrowserAlert(
  title: string,
  body: string,
): Promise<BrowserAlertShowResult> {
  if (!notificationsSupported()) return Promise.resolve('unsupported')
  if (Notification.permission !== 'granted') return Promise.resolve('denied')

  const tag = `appwrite-browser-alert-test-${Date.now()}`
  const notification = createBrowserNotification(title, body, tag, {
    requireInteraction: false,
  })
  if (!notification) return Promise.resolve('failed')

  return new Promise((resolve) => {
    let settled = false
    const finish = (result: BrowserAlertShowResult) => {
      if (settled) return
      settled = true
      resolve(result)
    }

    notification.onshow = () => finish('shown')
    notification.onerror = () => {
      releaseNotification(tag)
      finish('failed')
    }
    notification.onclose = () => releaseNotification(tag)

    // Some hosts never fire onshow (e.g. certain embedded webviews).
    window.setTimeout(() => finish('shown'), 750)
  })
}

/** @deprecated Use {@link showBrowserAlert}. */
export const showBuildNotification = showBrowserAlert

export function subscribeToNotificationPermissionChanges(
  onChange: (permission: BrowserNotificationPermission) => void,
): () => void {
  if (!notificationsSupported() || !navigator.permissions?.query) {
    return () => {}
  }

  let disposed = false
  let status: PermissionStatus | null = null
  let handleChange: (() => void) | null = null

  void navigator.permissions
    .query({ name: 'notifications' as PermissionName })
    .then((result) => {
      if (disposed) return
      status = result
      handleChange = () => {
        const fromStatus =
          result.state === 'prompt'
            ? 'default'
            : result.state === 'granted' || result.state === 'denied'
              ? result.state
              : getNotificationPermission()
        onChange(fromStatus)
      }
      result.addEventListener('change', handleChange)
      handleChange()
    })
    .catch(() => {
      // Permissions API unavailable for notifications in this browser.
    })

  return () => {
    disposed = true
    if (status && handleChange) {
      status.removeEventListener('change', handleChange)
    }
    status = null
    handleChange = null
  }
}
