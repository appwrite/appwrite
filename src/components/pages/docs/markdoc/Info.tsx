import { Info as InfoIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  BLOG_BODY_TEXT_CLASS,
  BLOG_BODY_TEXT_SIZE_CLASS,
  type MarkdocProseVariant,
} from '@/lib/blog/prose-typography'
import { cn } from '@/lib/utils'

const docsNoteContentClassName = cn(
  '[&_p]:my-0 [&_p+p]:mt-2',
  '[&_strong]:font-semibold [&_strong]:text-foreground',
  '[&_code]:rounded-md [&_code]:border [&_code]:border-border [&_code]:bg-muted/50 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[13px] [&_code]:text-foreground/85',
  '[&_ul]:my-2 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-4',
  '[&_ol]:my-2 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:ps-4',
  '[&_li]:leading-[1.65]',
  '[&_.not-prose]:w-full',
  'prose-links-neutral',
)

const blogNoteContentClassName = cn(
  '[&_p]:my-0 [&_p+p]:mt-2',
  '[&_strong]:font-semibold [&_strong]:text-foreground',
  '[&_code]:rounded-md [&_code]:border [&_code]:border-border [&_code]:bg-muted/50 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[15px] @[640px]:[&_code]:text-[16px] [&_code]:text-foreground/85',
  '[&_ul]:my-2 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-4',
  '[&_ol]:my-2 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:ps-4',
  '[&_li]:leading-[1.65]',
  '[&_.not-prose]:w-full',
  'prose-links-neutral',
)

export function Info({
  title,
  children,
  compact = false,
  proseVariant = 'docs',
}: {
  title: string
  children?: ReactNode
  compact?: boolean
  proseVariant?: MarkdocProseVariant
}) {
  const isBlog = proseVariant === 'blog'

  return (
    <Alert
      variant="default"
      className="not-prose my-6 gap-y-2 border-border bg-muted/30 [&>svg]:text-muted-foreground"
    >
      <InfoIcon className="h-4 w-4" />
      <AlertTitle
        className={cn(
          'line-clamp-none min-h-0 font-medium leading-[1.45] text-foreground',
          isBlog
            ? BLOG_BODY_TEXT_SIZE_CLASS
            : compact
              ? 'text-[13px]'
              : 'text-[15px]',
        )}
      >
        {title}
      </AlertTitle>
      {children ? (
        <AlertDescription
          className={cn(
            isBlog
              ? BLOG_BODY_TEXT_CLASS
              : compact
                ? 'text-[13px] leading-[1.6] text-muted-foreground @[480px]:text-[14px]'
                : 'text-[15px] leading-[1.65] text-muted-foreground @[640px]:text-[16px] @[640px]:leading-[1.65]',
            isBlog ? blogNoteContentClassName : docsNoteContentClassName,
          )}
        >
          {children}
        </AlertDescription>
      ) : null}
    </Alert>
  )
}
