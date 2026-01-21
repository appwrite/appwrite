import { ReactNode, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useSmartNavigation } from '@/lib/hooks/useSmartNavigation'

interface WizardLayoutProps {
  /** Wizard title displayed in the header */
  title: string
  /** Optional description displayed below the title */
  description?: string
  /** Main content area (typically forms and inputs) */
  children: ReactNode
  /** Sidebar content (typically summary or comparison boxes) */
  sidebar?: ReactNode
  /** Footer action buttons */
  footer?: ReactNode
  /** Handler for close/cancel button (deprecated: use fallbackPath instead) */
  onClose?: () => void
  /** Fallback path to navigate to if no browser history (e.g., '/organizations/$orgId/billing') */
  fallbackPath?: string
  /** Whether to render as fullscreen overlay (default: false) */
  fullscreen?: boolean
  /** Custom className for the main content area */
  contentClassName?: string
  /** Whether the main content should use 2/3 width with 1/3 sidebar (default: true) */
  useSidebar?: boolean
}

/**
 * WizardLayout Component
 *
 * A reusable layout for multi-step wizards with consistent header, content, sidebar, and footer structure.
 * Automatically handles browser history navigation with fallback support, and ESC key to close.
 *
 * @example
 * ```tsx
 * <WizardLayout
 *   title="Change Plan"
 *   description="Upgrade or downgrade your organization's billing plan"
 *   fallbackPath="/organizations/$orgId/billing"
 *   sidebar={<EstimatedTotalBox />}
 *   footer={
 *     <>
 *       <Button variant="outline" onClick={handleCancel}>Cancel</Button>
 *       <Button onClick={handleSubmit}>Submit</Button>
 *     </>
 *   }
 * >
 *   <PlanSelection />
 *   <PaymentMethod />
 * </WizardLayout>
 * ```
 */
export function WizardLayout({
  title,
  description,
  children,
  sidebar,
  footer,
  onClose,
  fallbackPath,
  fullscreen = false,
  contentClassName,
  useSidebar = true,
}: WizardLayoutProps) {
  // Use smart navigation hook for consistent back behavior
  const smartGoBack = useSmartNavigation({ fallbackPath })

  /**
   * Handle wizard close: use custom onClose or smart navigation
   */
  const handleClose = () => {
    // Custom onClose takes precedence (backward compatibility)
    if (onClose) {
      onClose()
    } else {
      smartGoBack()
    }
  }

  /**
   * Handle ESC key to close wizard
   */
  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        handleClose()
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [handleClose])
  const containerClasses = fullscreen
    ? 'fixed inset-0 z-[100] flex h-screen w-screen flex-col bg-background'
    : 'flex h-full flex-col'

  const headerClasses = fullscreen
    ? 'shrink-0 border-b border-border bg-background'
    : 'border-b border-border bg-background'

  const contentWrapperClasses = fullscreen
    ? 'flex-1 min-h-0 overflow-y-auto'
    : 'mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6'

  const footerClasses = fullscreen
    ? 'shrink-0 border-t border-border bg-muted/30'
    : 'border-t border-border bg-muted/30 px-4 py-4 sm:px-6'

  return (
    <div className={containerClasses}>
      {/* Header */}
      <div className={headerClasses}>
        <div
          className={cn(
            'mx-auto w-full max-w-7xl',
            fullscreen ? 'px-6 py-6' : 'px-4 py-4 sm:px-6',
          )}
        >
          <div className="flex items-center justify-between">
            <div>
              <h1
                className={cn(
                  'font-semibold text-foreground',
                  fullscreen ? 'text-lg' : 'text-xl',
                )}
              >
                {title}
              </h1>
              {description && (
                <p className="text-[13px] text-muted-foreground mt-1">
                  {description}
                </p>
              )}
            </div>
            <Button
              variant="ghost"
              size="sm"
              onClick={handleClose}
              className={fullscreen ? 'h-8 w-8 p-0' : undefined}
              aria-label="Close wizard"
            >
              <X className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>

      {/* Main Content */}
      <div className={contentWrapperClasses}>
        {fullscreen && (
          <div
            className={cn(
              'mx-auto w-full max-w-7xl px-6',
              fullscreen ? 'py-6' : 'pt-6',
            )}
          >
            {useSidebar ? (
              <div className="grid gap-8 lg:grid-cols-3">
                {/* Main Content */}
                <div
                  className={cn('lg:col-span-2 space-y-8', contentClassName)}
                >
                  {children}
                </div>

                {/* Sidebar */}
                {sidebar && <div className="lg:col-span-1">{sidebar}</div>}
              </div>
            ) : (
              <div className={contentClassName}>{children}</div>
            )}
          </div>
        )}

        {!fullscreen && useSidebar && (
          <div className="grid gap-6 lg:grid-cols-3 pt-6">
            {/* Main Content */}
            <div className={cn('lg:col-span-2 space-y-6', contentClassName)}>
              {children}
            </div>

            {/* Sidebar */}
            {sidebar && <div className="lg:col-span-1">{sidebar}</div>}
          </div>
        )}

        {!fullscreen && !useSidebar && (
          <div className={cn('pt-6', contentClassName)}>{children}</div>
        )}
      </div>

      {/* Footer */}
      {footer && (
        <div className={footerClasses}>
          <div
            className={cn(
              'mx-auto w-full max-w-7xl flex items-center justify-end gap-3',
              fullscreen && 'px-6 py-4',
            )}
          >
            {footer}
          </div>
        </div>
      )}
    </div>
  )
}
