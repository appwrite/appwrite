import { Info as InfoIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { cn } from '@/lib/utils'

const docsNoteContentClassName = cn(
  '[&_p]:my-0 [&_p+p]:mt-2',
  '[&_strong]:font-semibold [&_strong]:text-foreground',
  '[&_code]:rounded-md [&_code]:border [&_code]:border-border [&_code]:bg-muted/50 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[13px] [&_code]:text-foreground/85',
  '[&_ul]:my-2 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-4',
  '[&_ol]:my-2 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:pl-4',
  '[&_li]:leading-[1.65]',
  '[&_a]:font-medium [&_a]:text-foreground/85 [&_a]:underline [&_a]:decoration-dotted [&_a]:decoration-muted-foreground/60 [&_a]:underline-offset-[3px] [&_a]:[text-decoration-thickness:1px] hover:[&_a]:text-foreground/90 hover:[&_a]:decoration-muted-foreground hover:[&_a]:[text-decoration-thickness:2px]',
)

export function Info({
  title,
  children,
  compact = false,
}: {
  title: string
  children?: ReactNode
  compact?: boolean
}) {
  return (
    <Alert
      variant="default"
      className="not-prose my-6 gap-y-2 border-border bg-muted/30 [&>svg]:text-muted-foreground"
    >
      <InfoIcon className="h-4 w-4" />
      <AlertTitle
        className={cn(
          'line-clamp-none min-h-0 font-medium leading-[1.45] text-foreground',
          compact ? 'text-[13px]' : 'text-[15px]',
        )}
      >
        {title}
      </AlertTitle>
      {children ? (
        <AlertDescription
          className={cn(
            compact
              ? 'text-[13px] leading-[1.6] text-muted-foreground sm:text-[14px]'
              : 'text-[15px] leading-[1.65] text-muted-foreground sm:text-[16px] sm:leading-[1.65]',
            docsNoteContentClassName,
          )}
        >
          {children}
        </AlertDescription>
      ) : null}
    </Alert>
  )
}
