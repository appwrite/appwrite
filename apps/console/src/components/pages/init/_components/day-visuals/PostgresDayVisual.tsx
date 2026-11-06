import { CheckCircle2, Loader2 } from 'lucide-react'
import { PostgresElephantIcon } from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import {
  PostgresIdleSql,
  PostgresTypedSql,
  productBentoContainer,
  productBentoIdle,
} from '@/components/pages/home/product-bento/MockSyntax'
import { cn } from '@/lib/utils'

export function PostgresDayVisual() {
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
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
              <PostgresElephantIcon className="size-4 text-muted-foreground" aria-hidden />
            </span>
            <div className="min-w-0 flex-1">
              <p className={cn('truncate text-[11px] font-medium sm:text-[12px]', productBentoIdle.text)}>
                race_analytics
              </p>
              <p className="truncate text-[10px] text-muted-foreground">PostgreSQL · us-east</p>
            </div>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-hidden p-2.5 font-mono text-[10px] leading-relaxed sm:text-[11px]">
          <div className="group-hover:hidden motion-reduce:hidden">
            <PostgresIdleSql />
          </div>
          <div className="hidden group-hover:block motion-reduce:block">
            <PostgresTypedSql baseDelayMs={80} />
          </div>
        </div>

        <div className="flex items-center justify-between gap-2 border-t border-border bg-muted/8 px-3 py-1.5">
          <p className="text-[9px] text-muted-foreground transition-colors duration-300 group-hover:text-foreground sm:text-[10px]">
            <span className="font-medium tabular-nums">5</span> rows
          </p>
          <span className="flex items-center gap-1 text-[9px] text-muted-foreground sm:text-[10px]">
            <Loader2
              className="size-3 animate-spin group-hover:hidden motion-reduce:hidden"
              aria-hidden
            />
            <CheckCircle2
              className={cn(
                'hidden size-3 group-hover:inline motion-reduce:inline',
                productBentoIdle.emeraldIcon,
              )}
              aria-hidden
            />
            <span className="transition-colors duration-300 group-hover:text-foreground">
              12ms
            </span>
          </span>
        </div>
      </div>
    </div>
  )
}
