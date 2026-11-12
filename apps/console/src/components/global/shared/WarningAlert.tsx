import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { AlertTriangle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Red warning card - use whenever copy is shown in destructive/warning red. */
export const warningAlertContainerClassName =
  'border-red-500/30 bg-red-500/5'

export const warningAlertIconClassName = 'h-4 w-4 text-red-500'

export const warningAlertTitleClassName =
  'text-[13px] font-medium text-red-600 dark:text-red-400'

export const warningAlertDescriptionClassName =
  'text-[13px] leading-relaxed text-red-600/80 dark:text-red-400/80'

export const warningAlertTextClassName =
  'text-[13px] text-red-600 dark:text-red-400'

interface WarningAlertProps {
  title?: ReactNode
  children: ReactNode
  className?: string
  icon?: LucideIcon
  descriptionClassName?: string
}

export function WarningAlert({
  title,
  children,
  className,
  icon: Icon = AlertTriangle,
  descriptionClassName,
}: WarningAlertProps) {
  const t = useT()
  return (
    <Alert
      variant="default"
      className={cn(warningAlertContainerClassName, className)}
    >
      <Icon className={warningAlertIconClassName} />
      {title ? (
        <AlertTitle className={warningAlertTitleClassName}>
          {typeof title === 'string' ? t(title) : title}
        </AlertTitle>
      ) : null}
      <AlertDescription
        className={cn(
          title ? cn('mt-2', warningAlertDescriptionClassName) : warningAlertTextClassName,
          !title && 'col-start-2',
          descriptionClassName,
        )}
      >
        {typeof children === 'string' ? t(children) : children}
      </AlertDescription>
    </Alert>
  )
}
