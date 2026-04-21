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

export interface BaseDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title?: string
  description?: string
  children: React.ReactNode
  headerActions?: React.ReactNode
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
  contentClassName,
  maxWidth = 'sm:max-w-lg',
  side = 'right',
  disableAutoFocus = false,
}: BaseDrawerProps) {
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
          'flex w-full flex-col p-0 overflow-hidden',
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
                // Prevent auto-focus on header buttons by focusing the content area instead
                e.preventDefault()
                // Find first focusable element in content area (not header)
                const contentArea = contentRef.current
                if (contentArea) {
                  const focusableSelector =
                    'button:not([tabindex="-1"]), [href]:not([tabindex="-1"]), input:not([tabindex="-1"]), select:not([tabindex="-1"]), textarea:not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])'
                  const firstFocusable = contentArea.querySelector(
                    focusableSelector,
                  ) as HTMLElement
                  if (firstFocusable) {
                    // Use setTimeout to ensure it happens after Radix's focus trap
                    setTimeout(() => firstFocusable.focus(), 0)
                  }
                }
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
            {title && (
              <SheetTitle className="text-[15px] m-0 leading-none font-semibold">
                {title}
              </SheetTitle>
            )}
            <SheetDescription className="sr-only">
              {description || title || 'Drawer'}
            </SheetDescription>
            {!title && <div className="flex-1" />}
            <div className="flex items-center gap-2 shrink-0">
              {headerActions}
              {headerActions && <div className="h-4 w-px bg-border mx-1" />}
              <SheetClose asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 p-0 cursor-pointer"
                >
                  <X className="h-4 w-4" />
                </Button>
              </SheetClose>
            </div>
          </div>
        </SheetHeader>
        <div ref={contentRef} className="flex flex-col flex-1 min-h-0 pt-0">
          {children}
        </div>
      </SheetContent>
    </Sheet>
  )
}
