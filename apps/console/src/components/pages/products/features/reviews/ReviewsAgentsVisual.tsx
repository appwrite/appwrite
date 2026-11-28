import { Bot, Check } from 'lucide-react'
import {
  ArtChip,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ReviewsGrade } from '@/components/pages/products/features/reviews/ReviewsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Line = {
  kind: 'prompt' | 'step' | 'done'
  text: string
  grade?: string
  code?: boolean
}

/** A coding agent working a pull request up to Tier S with the Appwrite review skills. */
const LINES: Line[] = [
  { kind: 'prompt', text: 'Get acme/storefront#482 to Tier S' },
  { kind: 'step', text: 'appwrite-review-status', grade: 'B', code: true },
  { kind: 'step', text: 'Fix: a wrong password retries forever' },
  {
    kind: 'step',
    text: 'Edit src/auth/retry.ts: stop on 401, cap at 5 attempts',
    code: true,
  },
  { kind: 'step', text: 'git push, then comment @appwrite review', code: true },
  { kind: 'done', text: 'appwrite-review-status', grade: 'S', code: true },
]

/** Prompts to fix with AI on every finding, and agent skills that loop until the grade is S. */
export function ReviewsAgentsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] pb-14 pt-4 text-start">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(40)}
        title={
          <span className="flex items-center gap-1.5">
            <Bot className="size-3" aria-hidden />
            {t('Coding agent')}
          </span>
        }
        bodyClassName="space-y-2 px-4 py-3.5 font-mono text-[11.5px] leading-5"
      >
        {LINES.map((line, index) => (
          <p
            key={index}
            dir="ltr"
            className={cn(
              'product-hero-rise flex items-center gap-2',
              line.kind === 'prompt'
                ? 'text-foreground'
                : 'text-muted-foreground',
            )}
            style={riseStyle(200 + index * 220)}
          >
            {line.kind === 'prompt' ? (
              <span className="text-[var(--tone-ink)]">&gt;</span>
            ) : line.kind === 'done' ? (
              <Check
                className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                aria-hidden
              />
            ) : (
              <span
                className="size-1.5 shrink-0 rounded-full bg-muted-foreground/50"
                aria-hidden
              />
            )}
            <span
              className={cn(
                'min-w-0 truncate',
                line.kind === 'done' && 'text-foreground',
              )}
            >
              {line.code ? line.text : t(line.text)}
            </span>
            {line.grade ? (
              <span className="ms-auto flex shrink-0 items-center gap-1.5 font-sans text-[10.5px]">
                {t('Tier')} <ReviewsGrade grade={line.grade} />
              </span>
            ) : null}
          </p>
        ))}
      </ArtWindow>

      <ArtChip
        className="bottom-1 start-[6%] hidden sm:block"
        delayMs={1500}
        floatDelayMs={400}
      >
        <span className="text-[11px] text-muted-foreground">
          {t('Every finding includes a prompt to fix it with AI')}
        </span>
      </ArtChip>
    </div>
  )
}
