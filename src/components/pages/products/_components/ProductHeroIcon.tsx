import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

type ProductHeroIconProps = {
  icon: LucideIcon
  name: string
  className?: string
}

export function ProductHeroIcon({ icon: Icon, name, className }: ProductHeroIconProps) {
  return (
    <div
      className={cn(
        'inline-flex items-center gap-2.5 rounded-full border border-border/80 bg-card/70 py-1.5 ps-1.5 pe-4 ring-1 ring-black/[0.03] backdrop-blur-sm dark:bg-card/40 dark:ring-white/[0.05]',
        className,
      )}
    >
      <span className="relative flex size-9 shrink-0 items-center justify-center rounded-full border border-border/70 bg-gradient-to-b from-card to-muted/30 dark:from-muted/20 dark:to-background">
        <Icon className="size-4 text-muted-foreground" strokeWidth={1.75} aria-hidden />
      </span>
      <span className="text-[13px] font-medium tracking-tight text-foreground">
        Appwrite {name}
      </span>
    </div>
  )
}
