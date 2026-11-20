import { useCallback, useRef } from 'react'
import { AlertTriangle } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

/**
 * Error text on the single sign-on screens. In the default dark theme
 * `--destructive` is a dark red meant for backgrounds (about 1.6:1 on the card),
 * so text there takes `--destructive-foreground`, the readable red. The other
 * themes use `--destructive-foreground` as the colour on a red background
 * (white, or black in high contrast), so they keep `text-destructive`.
 */
export const SSO_ERROR_TEXT =
  'text-destructive [.dark_&]:text-destructive-foreground'

/** The same for a destructive `Alert`'s description, which sets its own colour. */
export const SSO_ALERT_ERROR_TEXT = '[.dark_&]:!text-destructive-foreground'

/** A list that failed to load: say so, with a way to try again. */
export function SSOListLoadError({
  title,
  onRetry,
  retrying,
}: {
  title: string
  onRetry: () => void
  retrying: boolean
}) {
  const t = useT()
  return (
    <div className="px-6 py-4" role="alert">
      <EmptyState
        icon={AlertTriangle}
        title={title}
        description={t('Check your connection and try again.')}
        isEmpty
        variant="default"
        className="py-2"
        action={
          <Button
            variant="outline"
            size="sm"
            className="h-8 text-[13px]"
            onClick={onRetry}
            disabled={retrying}
          >
            {t('Try again')}
          </Button>
        }
      />
    </div>
  )
}

/** Whether `element` can take focus now. */
function canFocus(
  element: HTMLElement | null | undefined,
): element is HTMLElement {
  return (
    !!element &&
    element.isConnected &&
    !(element as HTMLButtonElement).disabled &&
    element.getAttribute('aria-hidden') !== 'true'
  )
}

/**
 * Where focus goes when a dialog closes. The SSO dialogs open from state (a row
 * menu, a button), not from a `DialogTrigger`, so Radix has nothing to return
 * focus to and drops it on `<body>`. `returnTo` names the candidates in order,
 * read when the dialog closes, so a control that is gone or disabled by then is
 * skipped.
 */
export function useDialogFocusReturn() {
  const candidates = useRef<Array<() => HTMLElement | null | undefined>>([])

  const returnTo = useCallback(
    (...next: Array<() => HTMLElement | null | undefined>) => {
      candidates.current = next
    },
    [],
  )

  const onCloseAutoFocus = useCallback((event: Event) => {
    for (const candidate of candidates.current) {
      const element = candidate()
      if (canFocus(element)) {
        event.preventDefault()
        element.focus()
        return
      }
    }
  }, [])

  return { returnTo, onCloseAutoFocus }
}

/**
 * Focus `element` when a dialog without a text field opens. The shared dialog
 * focuses only text fields, so a confirmation would otherwise leave focus on
 * the page behind it.
 */
export function focusOnOpen(element: () => HTMLElement | null | undefined) {
  return (event: Event) => {
    const content = event.currentTarget as HTMLElement | null
    const target = element()
    if (!target) return
    event.preventDefault()
    target.focus()
    // A row menu that opened the dialog returns focus to its trigger as it
    // closes, a task later: take it back once, after that.
    setTimeout(() => {
      if (content && !content.contains(document.activeElement)) {
        element()?.focus()
      }
    }, 0)
  }
}

/**
 * Lets a submit through once until it settles. `isPending` from React Query is
 * only seen after a re-render, so a double-click or a second Enter in the same
 * moment would otherwise send the request twice.
 */
export function useSingleSubmit() {
  const busy = useRef(false)
  return useCallback((start: (settle: () => void) => void): boolean => {
    if (busy.current) return false
    busy.current = true
    start(() => {
      busy.current = false
    })
    return true
  }, [])
}
