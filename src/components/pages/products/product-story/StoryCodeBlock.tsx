import { StoryAnimated } from '@/components/pages/products/product-story/StoryAnimated'
import { cn } from '@/lib/utils'

export type StoryCodeLine = {
  text: string
  tone?: 'comment' | 'keyword' | 'string' | 'plain' | 'muted'
}

export function StoryCodeBlock({
  title,
  language,
  lines,
  className,
  lineDelayMs = 50,
}: {
  title?: string
  language?: string
  lines: StoryCodeLine[]
  className?: string
  lineDelayMs?: number
}) {
  return (
    <div className={cn('overflow-hidden rounded-lg border border-border bg-muted/10', className)}>
      {(title || language) ? (
        <div className="flex items-center justify-between border-b border-border bg-muted/15 px-3 py-1.5">
          {title ? (
            <span className="text-[10px] font-medium text-muted-foreground">{title}</span>
          ) : (
            <span />
          )}
          {language ? (
            <span className="font-mono text-[10px] text-muted-foreground/70">{language}</span>
          ) : null}
        </div>
      ) : null}
      <pre className="overflow-x-auto p-3 font-mono text-[10px] leading-5 sm:text-[11px] sm:leading-5">
        {lines.map((line, index) => (
          <StoryAnimated key={`${index}-${line.text}`} delayMs={index * lineDelayMs}>
            <code
              className={cn(
                'block whitespace-pre',
                line.tone === 'comment' && 'text-muted-foreground italic',
                line.tone === 'keyword' && 'text-blue-600 dark:text-blue-400',
                line.tone === 'string' && 'text-emerald-600 dark:text-emerald-400',
                line.tone === 'muted' && 'text-muted-foreground',
                (!line.tone || line.tone === 'plain') && 'text-foreground',
              )}
            >
              {line.text || '\u00A0'}
            </code>
          </StoryAnimated>
        ))}
      </pre>
    </div>
  )
}
