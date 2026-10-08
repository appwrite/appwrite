import { useEffect } from 'react'
import {
  type AnalyticsEventName,
  type AnalyticsProps,
  getSafeInternalPathParts,
} from '@/lib/analytics'
import { getAnalyticsActionEventName } from '@/lib/analytics-actions'
import { deferAfterPaint } from '@/lib/defer-after-paint'
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

const CHANGE_SELECTOR = [
  'select',
  'input[type="checkbox"]',
  'input[type="radio"]',
  'input[type="range"]',
  '[role="switch"]',
  '[role="checkbox"]',
].join(',')

const DIALOG_SELECTOR = '[role="dialog"], [role="alertdialog"]'
const DIALOG_ATTR_FILTER = ['aria-hidden', 'data-state', 'inert']

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

/**
 * Prefer curated `data-analytics` actions (see `ANALYTICS_ACTIONS`).
 * Never derive names from visible text, aria-labels, or tooltips: those often
 * include resource names and create unbounded Plausible cardinality.
 */
function getDynamicEventName(
  element: HTMLElement,
  fallback: AnalyticsEventName,
) {
  const actionHost = element.closest<HTMLElement>('[data-analytics]')
  const actionId = actionHost?.getAttribute('data-analytics')
  if (actionId) {
    const catalogName = getAnalyticsActionEventName(actionId)
    if (catalogName) return catalogName
  }

  return fallback
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
    element.matches(DIALOG_SELECTOR)
  ) {
    dialogs.push(element)
  }
  dialogs.push(
    ...Array.from(
      element.querySelectorAll<HTMLElement>(DIALOG_SELECTOR),
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
    const dialogObservers = new Map<HTMLElement, MutationObserver>()

    const trackDeferred = (
      eventName: AnalyticsEventName,
      props: AnalyticsProps = {},
    ) => {
      deferAfterPaint(() => {
        track(eventName, props)
      })
    }

    const syncDialog = (dialog: HTMLElement) => {
      const isOpen = isDialogOpen(dialog)
      const isActive = activeDialogs.has(dialog)

      if (isOpen && !isActive) {
        activeDialogs.add(dialog)
        trackDeferred('Dialog Opened', getDialogProps(dialog))
      } else if (!isOpen && isActive) {
        activeDialogs.delete(dialog)
        trackDeferred('Dialog Closed', getDialogProps(dialog))
      }
    }

    const observeDialog = (dialog: HTMLElement) => {
      if (dialogObservers.has(dialog)) return

      const dialogObserver = new MutationObserver(() => {
        syncDialog(dialog)
      })

      dialogObserver.observe(dialog, {
        attributes: true,
        attributeFilter: DIALOG_ATTR_FILTER,
      })

      dialogObservers.set(dialog, dialogObserver)
      syncDialog(dialog)
    }

    const unobserveDialog = (dialog: HTMLElement) => {
      const dialogObserver = dialogObservers.get(dialog)
      if (!dialogObserver) return
      dialogObserver.disconnect()
      dialogObservers.delete(dialog)
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
      const eventName = getDynamicEventName(element, baseEventName)
      const props = getClickProps(element, event)
      deferAfterPaint(() => {
        track(eventName, props)
      })
    }

    const handleChange = (event: Event) => {
      if (!isHTMLElement(event.target)) return
      const element = event.target.closest<HTMLElement>(CHANGE_SELECTOR)
      if (!element || shouldSkipAnalytics(element)) return

      const eventName = getDynamicEventName(element, 'Control Changed')
      const props = getChangeProps(element)
      deferAfterPaint(() => {
        track(eventName, props)
      })
    }

    const handleSubmit = (event: SubmitEvent) => {
      if (!isFormElement(event.target) || shouldSkipAnalytics(event.target))
        return
      deferAfterPaint(() => {
        track('Form Submitted', getFormProps())
      })
    }

    document.addEventListener('click', handleClick)
    document.addEventListener('change', handleChange)
    document.addEventListener('submit', handleSubmit)

    document.querySelectorAll<HTMLElement>(DIALOG_SELECTOR).forEach(observeDialog)

    let observeCancelled = false
    const domObserver = new MutationObserver((mutations) => {
      deferAfterPaint(() => {
        if (observeCancelled) return
        mutations.forEach((mutation) => {
          mutation.addedNodes.forEach((node) => {
            if (!(node instanceof Element)) return
            getDialogElements(node).forEach(observeDialog)
          })

          mutation.removedNodes.forEach((node) => {
            if (!(node instanceof Element)) return
            getDialogElements(node).forEach((dialog) => {
              unobserveDialog(dialog)
              if (!activeDialogs.has(dialog)) return
              activeDialogs.delete(dialog)
              trackDeferred('Dialog Closed', getDialogProps(dialog))
            })
          })
        })
      })
    })

    domObserver.observe(document.body, {
      childList: true,
      subtree: true,
    })

    return () => {
      observeCancelled = true
      document.removeEventListener('click', handleClick)
      document.removeEventListener('change', handleChange)
      document.removeEventListener('submit', handleSubmit)
      domObserver.disconnect()
      dialogObservers.forEach((observer) => observer.disconnect())
      dialogObservers.clear()
    }
  }, [track])
}
