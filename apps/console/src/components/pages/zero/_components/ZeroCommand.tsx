import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import { analyticsAttrs, type AnalyticsActionId } from '@/lib/analytics-actions'
import { cn } from '@/lib/utils'

type ZeroCommandProps = {
  command: string
  analytics: AnalyticsActionId
  prompt?: string
  className?: string
}

export function ZeroCommand({
  command,
  analytics,
  prompt = '$',
  className,
}: ZeroCommandProps) {
  return (
    <div
      className={cn(
        'flex items-start gap-2 rounded-lg bg-muted/50 py-2 ps-4 pe-2',
        className,
      )}
    >
      <pre
        dir="ltr"
        className="min-w-0 flex-1 whitespace-pre-wrap break-words py-1 font-mono text-[14px] leading-6 text-foreground"
      >
        <code>
          <span className="select-none text-muted-foreground">{prompt} </span>
          {command}
        </code>
      </pre>
      <div className="pt-0.5" {...analyticsAttrs(analytics)}>
        <CodeSnippetCopyButton content={command} />
      </div>
    </div>
  )
}
