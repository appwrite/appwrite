import { GitPullRequest, MessageSquareWarning } from 'lucide-react'
import {
  productBentoContainer,
  productBentoIdle,
} from '@/components/pages/home/product-bento/MockSyntax'
import { cn } from '@/lib/utils'

/** The lines the finding points at, as a diff. */
const DIFF = [
  { sign: '+', code: 'while (!session) {' },
  { sign: '+', code: '  session = await retry(email)' },
] as const

/** Init 5 day 5: a review posted on a pull request, with its finding inline. */
export function ReviewsDayVisual() {
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
              <GitPullRequest
                className={cn('size-3.5', productBentoIdle.emeraldIcon)}
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
                Retry sign-in
              </p>
              <p className="truncate font-mono text-[10px] text-muted-foreground">
                acme/web #482
              </p>
            </div>
            <span className="rounded border border-border/70 px-1.5 py-0.5 text-[9px] text-muted-foreground transition-colors duration-300 group-hover:border-amber-500/30 group-hover:bg-amber-500/10 group-hover:text-amber-600 dark:group-hover:text-amber-400 sm:text-[10px]">
              Changes requested
            </span>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-hidden p-2.5">
          <div className="overflow-hidden rounded-md border border-border/70 font-mono text-[9px] leading-relaxed sm:text-[10px]">
            <p className="border-b border-border/70 bg-muted/30 px-2 py-0.5 text-muted-foreground">
              src/auth/sign-in.ts
            </p>
            {DIFF.map((line, index) => (
              <p
                key={index}
                className={cn(
                  'flex gap-1.5 truncate px-2',
                  line.sign === '+' &&
                    'bg-emerald-500/5 transition-colors duration-300 group-hover:bg-emerald-500/10',
                )}
              >
                <span className="w-2 shrink-0 text-muted-foreground">
                  {line.sign}
                </span>
                <span className={productBentoIdle.text}>{line.code}</span>
              </p>
            ))}
          </div>

          <div
            className={cn(
              'mt-2 rounded-md border border-border/70 bg-background/80 px-2 py-1.5 transition-[border-color] duration-300',
              'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))]',
            )}
          >
            <p className="flex items-center gap-1.5 text-[9px] font-medium sm:text-[10px]">
              <MessageSquareWarning
                className={cn('size-3 shrink-0', productBentoIdle.brandIcon)}
                aria-hidden
              />
              <span className={productBentoIdle.text}>Appwrite Reviews</span>
              <span className="ms-auto rounded bg-muted px-1 text-[8px] uppercase tracking-wider text-muted-foreground sm:text-[9px]">
                Major · bug
              </span>
            </p>
            <p className="mt-1 text-[9px] leading-snug text-muted-foreground sm:text-[10px]">
              This loop never stops on a wrong password.
            </p>
          </div>
        </div>

        <div className="flex items-center justify-between gap-2 border-t border-border bg-muted/8 px-3 py-1.5 text-[9px] text-muted-foreground sm:text-[10px]">
          <span>1 finding posted on the pull request</span>
          <span className="tabular-nums transition-colors duration-300 group-hover:text-foreground">
            2 files
          </span>
        </div>
      </div>
    </div>
  )
}
