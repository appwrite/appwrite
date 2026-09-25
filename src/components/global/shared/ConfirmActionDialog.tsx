import { useRef, type ReactNode } from 'react'
import { translateText, useT } from '@/lib/i18n/translate'
import type { SupportedLanguage } from '@/lib/i18n/active-language'
import { resolveEffectivePageDirection } from '@/lib/layout/page-direction'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'

type ConfirmActionDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: ReactNode
  confirmLabel?: string
  confirmVariant?: 'default' | 'destructive'
  onConfirm: () => void
  isConfirming?: boolean
  /** Extra classes for the dialog panel (e.g. a higher z-index inside popovers). */
  contentClassName?: string
  /** Extra classes for the backdrop. */
  overlayClassName?: string
  language?: SupportedLanguage
  onCloseAutoFocus?: (event: Event) => void
}

export function ConfirmActionDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel = 'Confirm',
  confirmVariant = 'default',
  onConfirm,
  isConfirming = false,
  contentClassName,
  overlayClassName,
  language,
  onCloseAutoFocus,
}: ConfirmActionDialogProps) {
  const defaultT = useT()
  const t = language
    ? (text: string) => translateText(text, language)
    : defaultT
  const cancelRef = useRef<HTMLButtonElement>(null)
  const returnFocusRef = useRef<HTMLElement | null>(null)
  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (!isConfirming) onOpenChange(nextOpen)
      }}
    >
      <DialogContent
        className={cn('sm:max-w-md p-0', contentClassName)}
        overlayClassName={overlayClassName}
        {...(language
          ? { lang: language, dir: resolveEffectivePageDirection(language) }
          : {})}
        onOpenAutoFocus={(event) => {
          event.preventDefault()
          returnFocusRef.current =
            document.activeElement instanceof HTMLElement
              ? document.activeElement
              : null
          if (cancelRef.current && !cancelRef.current.disabled) {
            cancelRef.current.focus()
          } else {
            const content = event.currentTarget as HTMLElement
            content.focus()
          }
        }}
        onCloseAutoFocus={(event) => {
          if (onCloseAutoFocus) {
            onCloseAutoFocus(event)
          } else {
            event.preventDefault()
            returnFocusRef.current?.focus({ preventScroll: true })
          }
          returnFocusRef.current = null
        }}
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t(title)}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {description}
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            ref={cancelRef}
            type="button"
            variant="outline"
            className="h-9 text-[13px]"
            disabled={isConfirming}
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            variant={
              confirmVariant === 'destructive' ? 'destructive' : 'default'
            }
            className="h-9 text-[13px]"
            disabled={isConfirming}
            onClick={onConfirm}
          >
            {t(confirmLabel)}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
