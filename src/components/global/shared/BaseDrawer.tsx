import * as React from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetClose,
} from '@/components/ui/sheet'
import { cn } from '@/lib/utils'

export interface BaseDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title?: string
  children: React.ReactNode
  headerActions?: React.ReactNode
  contentClassName?: string
  maxWidth?: string
  side?: 'right' | 'left' | 'top' | 'bottom'
}

export function BaseDrawer({
  open,
  onOpenChange,
  title,
  children,
  headerActions,
  contentClassName,
  maxWidth = 'sm:max-w-lg',
  side = 'right',
}: BaseDrawerProps) {
  const contentRef = React.useRef<HTMLDivElement>(null)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        className={cn(
          'flex w-full flex-col p-0 overflow-hidden',
          maxWidth,
          contentClassName,
        )}
        side={side}
        showCloseButton={false}
        onOpenAutoFocus={(e) => {
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
        }}
      >
        <SheetHeader className="!p-0 !gap-0 shrink-0">
          <div className="flex items-center justify-between gap-4 w-full px-6 pt-4 pb-2">
            {title && (
              <SheetTitle className="text-[15px] m-0 leading-none font-semibold">
                {title}
              </SheetTitle>
            )}
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
