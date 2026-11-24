import { Bot, Check, Sparkles } from 'lucide-react'
import {
  productBentoContainer,
  productBentoIdle,
} from '@/components/pages/home/product-bento/MockSyntax'
import { cn } from '@/lib/utils'

/** What an agent does with a Zero project before anyone signs up. */
const STEPS = [
  'Created a project, no account needed',
  'Created table tasks',
  'Enabled email sign-in',
] as const

/** Init 5 day 3: an agent builds on a fresh project; claim it later. */
export function ZeroDayVisual() {
  return (
    <div className="absolute inset-0 flex flex-col overflow-hidden">
      <div
        className={cn(
          'mx-auto flex h-full min-h-0 w-full max-w-[22rem] flex-col',
          productBentoContainer.shell,
        )}
      >
        <div className={cn(productBentoContainer.header, 'px-3 py-2')}>
          <div className="flex items-center gap-2">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background">
              <Sparkles
                className={cn('size-3.5', productBentoIdle.brandIcon)}
                aria-hidden
              />
            </span>
            <div className="min-w-0 flex-1">
              <p
                className={cn(
                  'truncate text-[11px] font-medium sm:text-[12px]',
                  productBentoIdle.text,
                )}
              >
                Appwrite Zero
              </p>
              <p className="truncate text-[10px] text-muted-foreground">
                Build first, sign up later
              </p>
            </div>
            <span className="rounded border border-border/70 px-1.5 py-0.5 text-[9px] text-muted-foreground transition-colors duration-300 group-hover:border-[var(--brand-cta)]/25 group-hover:bg-[var(--brand-cta)]/10 group-hover:text-[var(--brand-cta)] sm:text-[10px]">
              Building
            </span>
          </div>
        </div>

        <div className="min-h-0 flex-1 space-y-2 overflow-hidden p-2.5">
          <div className="flex items-start gap-2">
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-border bg-muted/40">
              <Bot className="size-3 text-muted-foreground" aria-hidden />
            </span>
            <p
              className={cn(
                'rounded-md bg-muted/40 px-2 py-1 text-[10px] leading-snug sm:text-[11px]',
                productBentoIdle.text,
              )}
            >
              Build a task tracker with sign-in.
            </p>
          </div>
          <ul className="space-y-1 ps-7">
            {STEPS.map((step, index) => (
              <li
                key={step}
                className="flex items-center gap-1.5 text-[9px] text-muted-foreground transition-colors duration-300 group-hover:text-foreground sm:text-[10px]"
                style={{ transitionDelay: `${index * 60}ms` }}
              >
                <Check
                  className={cn(
                    'size-3 shrink-0',
                    productBentoIdle.emeraldIcon,
                  )}
                  aria-hidden
                />
                <span className="truncate">{step}</span>
              </li>
            ))}
          </ul>
        </div>

        <div className="flex items-center justify-between gap-2 border-t border-border bg-muted/8 px-3 py-2">
          <p className="min-w-0 truncate text-[9px] text-muted-foreground sm:text-[10px]">
            Yours to keep when you claim it
          </p>
          <span
            className={cn(
              'shrink-0 rounded-md px-2 py-1 text-[9px] font-medium sm:text-[10px]',
              productBentoIdle.ctaBlock,
            )}
          >
            Claim project
          </span>
        </div>
      </div>
    </div>
  )
}
