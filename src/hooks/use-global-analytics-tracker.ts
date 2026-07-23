import { useEffect } from 'react'
import {
  type AnalyticsEventName,
  type AnalyticsProps,
  getSafeInternalPathParts,
} from '@/lib/analytics'
import { useAnalytics } from './use-analytics'

const CLICK_SELECTOR = [
  'a[href]',
  'button',
  'input[type="button"]',
  'input[type="submit"]',
  'input[type="reset"]',
  '[role="button"]',
  '[role="menuitem"]',
  '[role="option"]',
  '[role="tab"]',
  '[role="switch"]',
  '[role="checkbox"]',
].join(',')

const MAX_DYNAMIC_EVENT_LABEL_LENGTH = 80

const CHANGE_SELECTOR = [
  'select',
  'input[type="checkbox"]',
  'input[type="radio"]',
  'input[type="range"]',
  '[role="switch"]',
  '[role="checkbox"]',
].join(',')

function isHTMLElement(value: EventTarget | null): value is HTMLElement {
  return value instanceof HTMLElement
}

function isFormElement(value: EventTarget | null): value is HTMLFormElement {
  return value instanceof HTMLFormElement
}

function shouldSkipAnalytics(element: Element) {
  return Boolean(
    element.closest(
      '[data-analytics-track="manual"], [data-analytics-track="false"]',
    ),
  )
}

function getElementRole(element: HTMLElement) {
  const explicitRole = element.getAttribute('role')
  if (explicitRole) return explicitRole
  if (element instanceof HTMLAnchorElement) return 'link'
  if (element instanceof HTMLButtonElement) return 'button'
  if (element instanceof HTMLInputElement) return element.type || 'input'
  if (element instanceof HTMLSelectElement) return 'select'
  return element.tagName.toLowerCase()
}

function getBaseClickEventName(element: HTMLElement): AnalyticsEventName {
  const role = getElementRole(element)
  if (role === 'tab') return 'Tab Changed'
  if (role === 'menuitem') return 'Menu Item Clicked'
  if (role === 'option') return 'Control Changed'
  if (role === 'switch' || role === 'checkbox') return 'Control Changed'
  if (element instanceof HTMLAnchorElement) {
    try {
      const url = new URL(element.href)
      return url.origin === window.location.origin
        ? 'Navigation Clicked'
        : 'External Link Opened'
    } catch {
      return 'Navigation Clicked'
    }
  }
  return 'Button Clicked'
}

function normalizeEventDescriptor(value: string | null | undefined) {
  if (!value) return undefined
  const normalized = value.replace(/\s+/g, ' ').trim()
  if (!normalized || normalized.length > MAX_DYNAMIC_EVENT_LABEL_LENGTH) {
    return undefined
  }
  return normalized
}

/**
 * Only Lucide icon class names are safe for event names.
 * Aria labels, tooltips, and visible text often include resource names
 * (projects, orgs, buckets, etc.) and must not become Plausible event names.
 */
function getIconName(element: HTMLElement) {
  const icon = element.querySelector<SVGElement>('svg')
  if (!icon) return undefined

  const lucideClass = Array.from(icon.classList).find((className) =>
    className.startsWith('lucide-'),
  )
  if (!lucideClass) return undefined

  // Skip the generic "lucide" base class if present as lucide-lucide
  const iconSlug = lucideClass.replace(/^lucide-/, '')
  if (!iconSlug || iconSlug === 'lucide') return undefined

  return normalizeEventDescriptor(
    iconSlug
      .split('-')
      .filter(Boolean)
      .join(' '),
  )
}

function toEventTitle(value: string) {
  return value
    .split(' ')
    .map((word) => {
      if (word.length <= 1) return word.toUpperCase()
      if (word === word.toUpperCase() && /[A-Z]/.test(word)) return word
      return `${word[0]?.toUpperCase() ?? ''}${word.slice(1).toLowerCase()}`
    })
    .join(' ')
}

function getDynamicEventName(
  element: HTMLElement,
  fallback: AnalyticsEventName,
) {
  const descriptor = getIconName(element)
  return descriptor ? `${toEventTitle(descriptor)} ${fallback}` : fallback
}

function getLinkProps(element: HTMLAnchorElement): AnalyticsProps {
  try {
    const url = new URL(element.href)
    const isExternal = url.origin !== window.location.origin
    if (isExternal) {
      return {
        external: true,
        has_query: url.search.length > 0,
        has_hash: url.hash.length > 0,
      }
    }

    const { scope, area } = getSafeInternalPathParts(url.pathname)
    return {
      external: false,
      destination_scope: scope,
      destination_area: area,
      has_query: url.search.length > 0,
      has_hash: url.hash.length > 0,
    }
  } catch {
    return { external: false }
  }
}

function getClickProps(
  element: HTMLElement,
  event: MouseEvent,
): AnalyticsProps {
  const role = getElementRole(element)
  const props: AnalyticsProps = {
    element: role,
    modifier_key:
      event.metaKey || event.ctrlKey || event.shiftKey || event.altKey,
  }

  if (element instanceof HTMLAnchorElement) {
    return { ...props, ...getLinkProps(element) }
  }

  if (element instanceof HTMLButtonElement) {
    props.button_type = element.type || 'button'
  }

  if (element instanceof HTMLInputElement) {
    props.control_type = element.type || 'input'
  }

  return props
}

function getChangeProps(element: HTMLElement): AnalyticsProps {
  const role = getElementRole(element)
  const props: AnalyticsProps = {
    element: role,
  }

  if (element instanceof HTMLInputElement) {
    props.control_type = element.type || 'input'
    props.checked =
      element.type === 'checkbox' || element.type === 'radio'
        ? element.checked
        : undefined
    props.has_value = element.value.length > 0
  }

  if (element instanceof HTMLSelectElement) {
    props.control_type = element.multiple ? 'multi_select' : 'select'
    props.has_value = element.value.length > 0
  }

  return props
}

function getFormProps(): AnalyticsProps {
  return {}
}

function getDialogElements(element: Element) {
  const dialogs: HTMLElement[] = []
  if (
    element instanceof HTMLElement &&
    element.matches('[role="dialog"], [role="alertdialog"]')
  ) {
    dialogs.push(element)
  }
  dialogs.push(
    ...Array.from(
      element.querySelectorAll<HTMLElement>(
        '[role="dialog"], [role="alertdialog"]',
      ),
    ),
  )
  return dialogs
}

function isDialogOpen(element: HTMLElement) {
  return (
    !element.hasAttribute('inert') &&
    element.getAttribute('aria-hidden') !== 'true' &&
    element.dataset.state !== 'closed'
  )
}

function getDialogProps(element: HTMLElement): AnalyticsProps {
  return {
    role: element.getAttribute('role') ?? 'dialog',
  }
}

export function useGlobalAnalyticsTracker() {
  const { track } = useAnalytics()

  useEffect(() => {
    const activeDialogs = new Set<HTMLElement>()

    const syncDialog = (dialog: HTMLElement) => {
      const isOpen = isDialogOpen(dialog)
      const isActive = activeDialogs.has(dialog)

      if (isOpen && !isActive) {
        activeDialogs.add(dialog)
        track('Dialog Opened', getDialogProps(dialog))
      } else if (!isOpen && isActive) {
        activeDialogs.delete(dialog)
        track('Dialog Closed', getDialogProps(dialog))
      }
    }

    const handleClick = (event: MouseEvent) => {
      if (!isHTMLElement(event.target)) return
      const element = event.target.closest<HTMLElement>(CLICK_SELECTOR)
      if (!element || shouldSkipAnalytics(element)) return
      if (
        element instanceof HTMLButtonElement &&
        (element.disabled || element.ariaDisabled === 'true')
      ) {
        return
      }

      const baseEventName = getBaseClickEventName(element)
      track(
        getDynamicEventName(element, baseEventName),
        getClickProps(element, event),
      )
    }

    const handleChange = (event: Event) => {
      if (!isHTMLElement(event.target)) return
      const element = event.target.closest<HTMLElement>(CHANGE_SELECTOR)
      if (!element || shouldSkipAnalytics(element)) return

      track(
        getDynamicEventName(element, 'Control Changed'),
        getChangeProps(element),
      )
    }

    const handleSubmit = (event: SubmitEvent) => {
      if (!isFormElement(event.target) || shouldSkipAnalytics(event.target))
        return
      track('Form Submitted', getFormProps())
    }

    document.addEventListener('click', handleClick)
    document.addEventListener('change', handleChange)
    document.addEventListener('submit', handleSubmit)
    document
      .querySelectorAll<HTMLElement>('[role="dialog"], [role="alertdialog"]')
      .forEach(syncDialog)

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (
          mutation.type === 'attributes' &&
          mutation.target instanceof HTMLElement
        ) {
          syncDialog(mutation.target)
          return
        }

        mutation.addedNodes.forEach((node) => {
          if (!(node instanceof Element)) return
          getDialogElements(node).forEach(syncDialog)
        })

        mutation.removedNodes.forEach((node) => {
          if (!(node instanceof Element)) return
          getDialogElements(node).forEach((dialog) => {
            if (!activeDialogs.has(dialog)) return
            activeDialogs.delete(dialog)
            track('Dialog Closed', getDialogProps(dialog))
          })
        })
      })
    })

    observer.observe(document.body, {
      attributes: true,
      attributeFilter: ['aria-hidden', 'data-state', 'inert'],
      childList: true,
      subtree: true,
    })

    return () => {
      document.removeEventListener('click', handleClick)
      document.removeEventListener('change', handleChange)
      document.removeEventListener('submit', handleSubmit)
      observer.disconnect()
    }
  }, [track])
}
