import { useState, useEffect } from 'react'
import { AlertTriangle, X, CreditCard } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { HeaderAlertBar } from '@/components/global/shared/HeaderAlertBar'

const STORAGE_KEY = 'payment-alert-dismissed'

interface PaymentAlertProps {
  className?: string
}

export function PaymentAlert({ className }: PaymentAlertProps) {
  const [isDismissed, setIsDismissed] = useState(true) // Start hidden to avoid flash

  // Read initial state from localStorage
  useEffect(() => {
    const dismissed = localStorage.getItem(STORAGE_KEY)
    setIsDismissed(dismissed === 'true')
  }, [])

  const handleDismiss = () => {
    setIsDismissed(true)
    localStorage.setItem(STORAGE_KEY, 'true')
  }

  // Always render container to prevent layout shift
  // When dismissed, hide content but keep container height to prevent shift
  return (
    <div
      className={cn(
        'relative min-h-14 transition-all duration-200',
        isDismissed
          ? 'overflow-hidden'
          : 'text-amber-600 dark:text-amber-400',
        className,
      )}
      style={isDismissed ? { height: 0, minHeight: 0 } : undefined}
    >
      {!isDismissed && (
        <>
          <HeaderAlertBar
            variant="warning"
            icon={AlertTriangle}
            className="pr-12"
            action={
              <Button
                size="sm"
                className="h-7 w-fit gap-1.5 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
              >
                <CreditCard className="h-3.5 w-3.5" />
                <span className="hidden sm:inline">Update Payment</span>
                <span className="sm:hidden">Update</span>
              </Button>
            }
          >
            Your payment method has expired. Please update your billing
            information to avoid service interruption.
          </HeaderAlertBar>
          <button
            type="button"
            onClick={handleDismiss}
            className="absolute right-3 top-3 flex h-7 w-7 items-center justify-center rounded-md text-amber-600 transition-colors hover:bg-amber-500/20 dark:text-amber-400"
            aria-label="Dismiss alert"
          >
            <X className="h-4 w-4" />
          </button>
        </>
      )}
    </div>
  )
}
