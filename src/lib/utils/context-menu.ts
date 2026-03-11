import { toast } from 'sonner'

export async function copyToClipboard(label: string, value?: string | null) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    toast.success(`${label} copied to clipboard`)
  } catch {
    toast.error('Failed to copy')
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
