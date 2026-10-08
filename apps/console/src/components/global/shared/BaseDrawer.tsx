import * as React from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
  SheetClose,
} from '@/components/ui/sheet'
import { cn } from '@/lib/utils'
import { handleModalOpenAutoFocus } from '@/lib/layout/modal-auto-focus'
import { useT } from '@/lib/i18n/translate'

export interface BaseDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title?: string
  description?: string
  children: React.ReactNode
  headerActions?: React.ReactNode
  /** Renders on the left side of the drawer header (e.g. primary header action with label). */
  headerLeading?: React.ReactNode
  contentClassName?: string
  maxWidth?: string
  side?: 'right' | 'left' | 'top' | 'bottom'
  disableAutoFocus?: boolean
}

export function BaseDrawer({
  open,
  onOpenChange,
  title,
  description,
  children,
  headerActions,
  headerLeading,
  contentClassName,
  maxWidth = 'sm:max-w-lg',
  side = 'right',
  disableAutoFocus = false,
}: BaseDrawerProps) {
  const t = useT()
  const contentRef = React.useRef<HTMLDivElement>(null)

  const blurActiveElement = React.useCallback(() => {
    if (document.activeElement instanceof HTMLElement) {
      document.activeElement.blur()
    }
  }, [])

  const handleSheetOpenChange = React.useCallback(
    (newOpen: boolean) => {
      if (!newOpen) {
        blurActiveElement()
      }
      onOpenChange(newOpen)
    },
    [blurActiveElement, onOpenChange],
  )

  // Blur the drawer container when it opens and auto-focus is disabled
  React.useEffect(() => {
    if (disableAutoFocus && open) {
      // Use requestAnimationFrame to ensure this runs after Radix's focus management
      const rafId = requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          // Find the SheetContent element by its data attribute
          const sheetContent = document.querySelector(
            '[data-slot="sheet-content"]',
          ) as HTMLElement | null
          if (sheetContent) {
            // Remove tabindex if it was set by Radix
            sheetContent.removeAttribute('tabindex')
            // Blur the SheetContent container itself
            sheetContent.blur()
          }
          // Also blur any focused element inside
          blurActiveElement()
        })
      })
      return () => cancelAnimationFrame(rafId)
    }
  }, [blurActiveElement, disableAutoFocus, open])

  return (
    <Sheet open={open} onOpenChange={handleSheetOpenChange}>
      <SheetContent
        className={cn(
          'flex h-[100dvh] max-h-[100dvh] w-full flex-col gap-0 overflow-hidden p-0',
          maxWidth,
          contentClassName,
        )}
        side={side}
        showCloseButton={false}
        tabIndex={disableAutoFocus ? -1 : undefined}
        onOpenAutoFocus={
          disableAutoFocus
            ? (e) => {
                // Completely prevent any focus manipulation
                e.preventDefault()
              }
            : (e) => {
                // Prevent Radix from auto-focusing header buttons (e.g. close,
                // copy-link), tabs, or in-content copy buttons. Only focus a
                // real text-entry control if one exists in the body.
                handleModalOpenAutoFocus(e, contentRef.current)
              }
        }
        onCloseAutoFocus={(e) => {
          // Prevent Radix from restoring focus into an element that is about
          // to be hidden by another modal opening immediately after close.
          e.preventDefault()
          blurActiveElement()
        }}
      >
        <SheetHeader className="!p-0 !gap-0 shrink-0">
          <div className="flex items-center justify-between gap-4 w-full px-6 pt-4 pb-2">
            <div className="flex min-w-0 flex-1 items-center gap-2">
              {headerLeading}
              {title && (
                <SheetTitle className="text-[15px] m-0 leading-none font-semibold">
                  {t(title)}
                </SheetTitle>
              )}
            </div>
            <SheetDescription className="sr-only">
              {description ? t(description) : title ? t(title) : t('Drawer')}
            </SheetDescription>
            <div className="flex items-center gap-2 shrink-0">
              {headerActions}
              {headerActions && <div className="h-4 w-px bg-border mx-1" />}
              <SheetClose asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 p-0 cursor-pointer"
                  aria-label={t('Close')}
                >
                  <X className="h-4 w-4" />
                </Button>
              </SheetClose>
            </div>
          </div>
        </SheetHeader>
        <div ref={contentRef} className="flex min-h-0 flex-1 flex-col overflow-hidden pt-0">
          {children}
        </div>
      </SheetContent>
    </Sheet>
  )
}
