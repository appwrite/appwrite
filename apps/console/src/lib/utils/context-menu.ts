import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export type CopyToClipboardOptions = {
  /** When false, skips the success toast (caller may show inline feedback). */
  showToast?: boolean
}

export async function copyToClipboard(
  label: string,
  value?: string | null,
  options?: CopyToClipboardOptions,
): Promise<boolean> {
  if (!value) return false
  const showToast = options?.showToast !== false
  try {
    await navigator.clipboard.writeText(value)
    if (showToast) {
      toast.success(`${label} copied to clipboard`)
    }
    return true
  } catch {
    toast.error('Failed to copy')
    return false
  }
}

export function buildConsoleUrl(path: string) {
  const normalized = path.startsWith('/') ? path : `/${path}`
  return `${window.location.origin}${normalized}`
}

export function openInNewTab(url: string) {
  window.open(url, '_blank', 'noopener,noreferrer')
}

export function openInNewWindow(url: string) {
  window.open(url, '_blank', 'noopener,noreferrer,width=1200,height=800')
}

export function toPrettyJson(value: unknown) {
  return JSON.stringify(value, null, 2)
}

/**
 * Fetches a resource (or uses a provided value) and copies its full JSON to the clipboard.
 */
export async function copyResourceAsJson(
  fetchResource: () => Promise<unknown> | unknown,
  options?: { fallback?: unknown },
): Promise<boolean> {
  try {
    const resource = await Promise.resolve(fetchResource())
    const payload = resource ?? options?.fallback
    if (payload == null) {
      toast.error('Resource not found')
      return false
    }
    return await copyToClipboard('JSON', toPrettyJson(payload))
  } catch (error) {
    toast.error(getErrorMessage(error) ?? 'Failed to copy JSON')
    return false
  }
}
