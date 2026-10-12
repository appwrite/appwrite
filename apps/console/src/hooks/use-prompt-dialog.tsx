import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import {
  PromptDialog,
  type PromptDialogField,
} from '@/components/global/shared/PromptDialog'
import {
  stopDialogKeyPropagation,
  type DialogLayerOptions,
} from '@/hooks/use-confirm-dialog'

export type PromptDialogRequest = {
  title: string
  description?: ReactNode
  fields: PromptDialogField[]
  confirmLabel?: string
}

/**
 * Promise-based replacement for `window.prompt()`.
 *
 * Render `promptDialog` once in the component tree, then
 * `const values = await prompt({ title, fields })`. Resolves to the field
 * values keyed by name, or `null` when the dialog is dismissed or the
 * component unmounts.
 */
export function usePromptDialog(options: DialogLayerOptions = {}) {
  const [request, setRequest] = useState<PromptDialogRequest | null>(null)
  const [open, setOpen] = useState(false)
  const resolveRef = useRef<
    ((values: Record<string, string> | null) => void) | null
  >(null)
  const returnFocusRef = useRef<HTMLElement | null>(null)

  const settle = useCallback((values: Record<string, string> | null) => {
    resolveRef.current?.(values)
    resolveRef.current = null
    setOpen(false)
  }, [])

  const prompt = useCallback(
    (next: PromptDialogRequest) =>
      new Promise<Record<string, string> | null>((resolve) => {
        if (!returnFocusRef.current) {
          returnFocusRef.current =
            document.activeElement instanceof HTMLElement
              ? document.activeElement
              : null
        }
        resolveRef.current?.(null)
        resolveRef.current = resolve
        setRequest(next)
        setOpen(true)
      }),
    [],
  )

  useEffect(() => {
    const pending = resolveRef
    return () => pending.current?.(null)
  }, [])

  const promptDialog = (
    <div className="contents" onKeyDown={stopDialogKeyPropagation}>
      <PromptDialog
        open={open}
        onOpenChange={(nextOpen) => {
          if (!nextOpen) settle(null)
        }}
        title={request?.title ?? ''}
        description={request?.description}
        fields={request?.fields ?? []}
        confirmLabel={request?.confirmLabel}
        onSubmit={settle}
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

  return { prompt, promptDialog }
}
