import { FileText } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { DOCS_BODY_TEXT_CLASS } from '@/lib/docs/prose-typography'
import { cn } from '@/lib/utils'

type ThreadSummaryProps = {
  summary: string
}

export function ThreadSummary({ summary }: ThreadSummaryProps) {
  return (
    <Alert
      variant="default"
      className="not-prose mt-6 gap-y-2 border-border bg-muted/30 [&>svg]:text-muted-foreground"
    >
      <FileText className="h-4 w-4" aria-hidden />
      <AlertTitle className="text-[15px] font-medium leading-[1.45] text-foreground @[640px]:text-[16px]">
        Summary
      </AlertTitle>
      <AlertDescription
        className={cn(DOCS_BODY_TEXT_CLASS, 'whitespace-pre-line')}
      >
        {summary}
      </AlertDescription>
    </Alert>
  )
}
