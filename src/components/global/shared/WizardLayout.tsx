import { ReactNode, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { Button } from '@/components/ui/button'
import { X, ArrowLeft } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useSmartNavigation } from '@/lib/hooks/useSmartNavigation'

interface WizardLayoutProps {
  /** Wizard title displayed in the header */
  title: string | ReactNode
  /** Optional description displayed below the title */
  description?: string | ReactNode
  /** Optional content to render at the bottom of the fixed header */
  headerBottom?: ReactNode
  /** Optional actions to render in the header (right side, before close button) */
  headerActions?: ReactNode
  /** Main content area (typically forms and inputs) */
  children: ReactNode
  /** Sidebar content (typically summary or comparison boxes) */
  sidebar?: ReactNode
  /** Footer action buttons */
  footer?: ReactNode
  /** Handler for close/cancel button (deprecated: use fallbackPath instead) */
  onClose?: () => void
  /** Fallback path to navigate to if no browser history (e.g., '/organizations/$orgId/settings/billing') */
  fallbackPath?: string
  /** Whether to render as fullscreen overlay (default: false) */
  fullscreen?: boolean
  /** Custom className for the main content area */
  contentClassName?: string
  /** Whether the main content should use 2/3 width with 1/3 sidebar (default: true) */
  useSidebar?: boolean
  /** Whether to constrain content width with max-w-7xl (default: true) */
  constrainWidth?: boolean
  /** Whether to constrain footer width with max-w-7xl (default: true) */
  constrainFooterWidth?: boolean
  /** Custom max-width class for content (default: 'max-w-7xl') */
  maxWidth?: string
  /** Whether to show a back button in addition to X button (default: false) */
  showBackButton?: boolean
  /** Custom label for the back button (default: 'Back') */
  backButtonLabel?: string
  /** Handler for back button click (if not provided, uses browser history) */
  onBack?: () => void
  /** Footer button alignment (default: 'left') */
  footerAlign?: 'left' | 'right'
  /** Whether to apply default content padding (default: true) */
  contentPadding?: boolean
  /**
   * When fullscreen, merged into the main content wrapper (between header and footer).
   * Use with `overflow-hidden` and `flex flex-col min-h-0` when children manage their own scroll.
   */
  contentWrapperClassName?: string
  /**
   * When fullscreen, merged into the inner `px-6` container around children/sidebar.
   * Use `flex min-h-0 flex-1 flex-col` so full-height split layouts fill the viewport.
   */
  fullscreenInnerClassName?: string
  /**
   * When fullscreen, replaces the default horizontal padding (`px-6`) on the inner container.
   * Use e.g. `ps-6` only when the right edge should be flush with the viewport.
   */
  fullscreenContentXClassName?: string
  /**
   * When true, skips auto-focusing the first text field or radio on mount.
   * Use for change/edit wizards (e.g. upgrade an existing org) or when another
   * region should receive initial focus (e.g. a canvas).
   */
  skipInitialFieldFocus?: boolean
  /**
   * When this value changes, initial focus runs again (e.g. after async content mounts).
   */
  initialFocusKey?: string | number
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
 *   fallbackPath="/organizations/$orgId/settings/billing"
 *   sidebar={<EstimatedTotalBox />}
 *   footerAlign="right"
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
  headerBottom,
  headerActions,
  children,
  sidebar,
  footer,
  onClose,
  fallbackPath,
  fullscreen = false,
  contentClassName,
  useSidebar = true,
  constrainWidth = true,
  constrainFooterWidth = true,
  maxWidth = 'max-w-7xl',
  showBackButton = false,
  backButtonLabel = 'Back',
  onBack,
  footerAlign = 'left',
  contentPadding = true,
  contentWrapperClassName,
  fullscreenInnerClassName,
  fullscreenContentXClassName,
  skipInitialFieldFocus = false,
  initialFocusKey,
}: WizardLayoutProps) {
  const t = useT()
  // Use smart navigation hook for consistent back behavior
  // Navigation priority: fallbackPath (if provided) > browser history > root
  const smartGoBack = useSmartNavigation({ fallbackPath })
  const rootRef = useRef<HTMLDivElement>(null)
  const contentRef = useRef<HTMLDivElement>(null)

  // Focus the first input or radio when the wizard opens (unless skipped for canvas-first wizards)
  useEffect(() => {
    if (skipInitialFieldFocus) return
    const timer = requestAnimationFrame(() => {
      const root = contentRef.current
      if (!root) return

      const textInput = root.querySelector<
        HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
      >(
        'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])',
      )
      if (textInput) {
        textInput.focus()
        return
      }

      const radio = root.querySelector<HTMLElement>(
        '[data-slot="radio-group-item"]:not([disabled])',
      )
      radio?.focus()
    })
    return () => cancelAnimationFrame(timer)
  }, [skipInitialFieldFocus, initialFocusKey])

  /**
   * Handle wizard close: use custom onClose or smart navigation
   * Memoized to prevent unnecessary re-renders of the ESC key effect
   */
  const handleClose = useCallback(() => {
    // Custom onClose takes precedence (backward compatibility)
    if (onClose) {
      onClose()
    } else {
      smartGoBack()
    }
  }, [onClose, smartGoBack])

  /**
   * Handle back button: uses onBack if provided, otherwise uses browser history
   */
  const handleBack = useCallback(() => {
    if (onBack) {
      onBack()
    } else {
      window.history.back()
    }
  }, [onBack])

  /**
   * Handle ESC key to close wizard
   * Only closes wizard if no Popover/Command/Dialog is open.
   * Uses capture so Escape is consumed before parent surfaces (e.g. expanded
   * assistant) also react to the same keypress. Only the topmost open wizard
   * handles Escape so nested overlays (like an image preview) close first.
   */
  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        const openWizards = document.querySelectorAll('[data-wizard-layout]')
        const topmostWizard = openWizards[openWizards.length - 1]
        if (!rootRef.current || topmostWizard !== rootRef.current) {
          return
        }

        // Check if the event target is within an open Popover or Command component
        const target = event.target as HTMLElement
        const isInPopover = target.closest('[data-slot="popover-content"]')
        const isInCommand = target.closest('[data-slot="command"]')
        const isInDialog = target.closest('[data-slot="dialog-content"]')

        // Also check if any Popover or Dialog is currently open (Radix UI sets data-state="open")
        const hasOpenPopover = document.querySelector(
          '[data-slot="popover-content"][data-state="open"]',
        )
        const hasOpenDialog = document.querySelector(
          '[data-slot="dialog-overlay"][data-state="open"]',
        )

        // If ESC is pressed within or if a Popover/Command/Dialog is open,
        // let it handle the event (it will close that layer, not the wizard)
        if (
          isInPopover ||
          isInCommand ||
          isInDialog ||
          hasOpenPopover ||
          hasOpenDialog
        ) {
          return
        }

        event.preventDefault()
        event.stopImmediatePropagation()
        handleClose()
      }
    }

    window.addEventListener('keydown', handleKeyDown, true)
    return () => window.removeEventListener('keydown', handleKeyDown, true)
  }, [handleClose])
  const containerClasses = fullscreen
    ? 'fixed inset-0 z-[9998] flex h-[100dvh] max-h-[100dvh] w-screen flex-col overflow-hidden bg-background'
    : 'flex h-full flex-col'

  const headerClasses = fullscreen
    ? cn(
        'shrink-0 bg-background/95 backdrop-blur-xl supports-[backdrop-filter]:bg-background/80',
        !headerBottom && 'border-b border-border',
      )
    : cn(
        'bg-background/95 backdrop-blur-xl supports-[backdrop-filter]:bg-background/80',
        !headerBottom && 'border-b border-border/30',
      )

  const contentWrapperClasses = fullscreen
    ? cn('flex-1 min-h-0 overflow-y-auto', contentWrapperClassName)
    : 'mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6'

  const footerClasses = fullscreen
    ? 'shrink-0 border-t border-border bg-background/95 backdrop-blur-xl supports-[backdrop-filter]:bg-background/80'
    : 'border-t border-border/30 bg-background/95 backdrop-blur-xl supports-[backdrop-filter]:bg-background/80 px-4 py-4 sm:px-6'

  const wizardContent = (
    <div ref={rootRef} data-wizard-layout="" className={containerClasses}>
      {/* Header */}
      <div className={headerClasses}>
        <div className={cn('mx-auto w-full', constrainWidth && maxWidth)}>
          {/* Original header content with padding */}
          <div
            className={cn(
              'h-14 flex items-center shrink-0',
              headerBottom &&
                (fullscreen
                  ? 'border-b border-border'
                  : 'border-b border-border/30'),
              fullscreen ? 'px-6' : 'px-4 @[1000px]:px-6',
            )}
          >
            <div className="flex items-center justify-between w-full">
              <div className="flex items-center gap-3 min-w-0 flex-1">
                {showBackButton && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={handleBack}
                    className="h-8 w-8 p-0 shrink-0"
                    aria-label={t(backButtonLabel)}
                  >
                    <ArrowLeft className="h-4 w-4" />
                  </Button>
                )}
                <div className="min-w-0">
                  <h1
                    className={cn(
                      'font-semibold text-foreground truncate',
                      'text-[15px]',
                      typeof title !== 'string' && 'flex items-center gap-2',
                    )}
                  >
                    {typeof title === 'string' ? t(title) : title}
                  </h1>
                  {description && (
                    <div className="mt-0.5">
                      {typeof description === 'string' ? (
                        <p className="text-[12px] text-muted-foreground truncate">
                          {t(description)}
                        </p>
                      ) : (
                        description
                      )}
                    </div>
                  )}
                </div>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                {headerActions && (
                  <>
                    {headerActions}
                    <div className="h-5 w-px bg-border" />
                  </>
                )}
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={handleClose}
                  className="h-8 w-8 p-0"
                  aria-label={t('Close wizard')}
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </div>
        </div>
        {/* Full-bleed below title row; background can span 100% of wizard width */}
        {headerBottom && (
          <div
            className={cn(
              'w-full border-b shadow-none',
              fullscreen ? 'border-border' : 'border-border/30',
            )}
          >
            {headerBottom}
          </div>
        )}
      </div>

      {/* Main Content */}
      <div className={contentWrapperClasses}>
        {fullscreen && (
          <div
            className={cn(
              'mx-auto w-full',
              fullscreenContentXClassName ?? 'px-6',
              constrainWidth && maxWidth,
              contentPadding && (fullscreen ? 'py-6' : 'pt-6'),
              fullscreenInnerClassName,
            )}
          >
            {useSidebar ? (
              <div className="grid gap-8 lg:grid-cols-3">
                {/* Main Content */}
                <div
                  ref={contentRef}
                  className={cn('lg:col-span-2 space-y-8', contentClassName)}
                >
                  {children}
                </div>

                {/* Sidebar */}
                {sidebar && (
                  <div className="lg:col-span-1 self-start sticky top-6">
                    {sidebar}
                  </div>
                )}
              </div>
            ) : (
              <div ref={contentRef} className={contentClassName}>
                {children}
              </div>
            )}
          </div>
        )}

        {!fullscreen && useSidebar && (
          <div
            className={cn(
              'grid gap-6 lg:grid-cols-3',
              contentPadding && 'pt-6',
            )}
          >
            {/* Main Content */}
            <div
              ref={contentRef}
              className={cn('lg:col-span-2 space-y-6', contentClassName)}
            >
              {children}
            </div>

            {/* Sidebar */}
            {sidebar && (
              <div className="lg:col-span-1 self-start sticky top-6">
                {sidebar}
              </div>
            )}
          </div>
        )}

        {!fullscreen && !useSidebar && (
          <div
            ref={contentRef}
            className={cn(contentPadding && 'pt-6', contentClassName)}
          >
            {children}
          </div>
        )}
      </div>

      {/* Footer */}
      {footer && (
        <div className={footerClasses}>
          <div
            className={cn(
              'mx-auto w-full flex items-center gap-3',
              constrainFooterWidth && maxWidth,
              fullscreen && 'px-6 py-4',
              footerAlign === 'right' && 'justify-end',
            )}
          >
            {footer}
          </div>
        </div>
      )}
    </div>
  )

  if (fullscreen && typeof document !== 'undefined') {
    return createPortal(wizardContent, document.body)
  }
  return wizardContent
}
