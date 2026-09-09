import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent,
  type ReactNode,
} from 'react'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import type { SupportedLanguage } from '@/lib/i18n/active-language'

export type ConfirmDialogRequest = {
  title: string
  description: ReactNode
  confirmLabel?: string
  confirmVariant?: 'default' | 'destructive'
}

export type DialogLayerOptions = {
  /** Extra classes for the dialog panel (e.g. a higher z-index inside popovers). */
  contentClassName?: string
  /** Extra classes for the backdrop. */
  overlayClassName?: string
  language?: SupportedLanguage
}

/**
 * Keystrokes inside a modal must not reach React ancestors that own keyboard
 * navigation (menus, popovers). Synthetic events bubble through portals.
 */
export function stopDialogKeyPropagation(event: KeyboardEvent) {
  event.stopPropagation()
}

/**
 * Promise-based replacement for `window.confirm()`.
 *
 * Render `confirmDialog` once in the component tree, then
 * `if (!(await confirm({ title, description }))) return`.
 * Resolves `false` when the dialog is dismissed or the component unmounts.
 */
export function useConfirmDialog(options: DialogLayerOptions = {}) {
  const [request, setRequest] = useState<ConfirmDialogRequest | null>(null)
  const [open, setOpen] = useState(false)
  const resolveRef = useRef<((confirmed: boolean) => void) | null>(null)
  const returnFocusRef = useRef<HTMLElement | null>(null)

  const settle = useCallback((confirmed: boolean) => {
    resolveRef.current?.(confirmed)
    resolveRef.current = null
    setOpen(false)
  }, [])

  const confirm = useCallback(
    (next: ConfirmDialogRequest) =>
      new Promise<boolean>((resolve) => {
        if (!returnFocusRef.current) {
          returnFocusRef.current =
            document.activeElement instanceof HTMLElement
              ? document.activeElement
              : null
        }
        resolveRef.current?.(false)
        resolveRef.current = resolve
        setRequest(next)
        setOpen(true)
      }),
    [],
  )

  useEffect(() => {
    const pending = resolveRef
    return () => pending.current?.(false)
  }, [])

  const confirmDialog = (
    <div className="contents" onKeyDown={stopDialogKeyPropagation}>
      <ConfirmActionDialog
        open={open}
        onOpenChange={(nextOpen) => {
          if (!nextOpen) settle(false)
        }}
        title={request?.title ?? ''}
        description={request?.description}
        confirmLabel={request?.confirmLabel}
        confirmVariant={request?.confirmVariant}
        onConfirm={() => settle(true)}
        contentClassName={options.contentClassName}
        overlayClassName={options.overlayClassName}
        language={options.language}
        onCloseAutoFocus={(event) => {
          event.preventDefault()
          returnFocusRef.current?.focus({ preventScroll: true })
          returnFocusRef.current = null
        }}
      />
    </div>
  )

  return { confirm, confirmDialog }
}
