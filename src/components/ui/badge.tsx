import * as React from 'react'
import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'

import { cn } from '@/lib/utils'

const badgeVariants = cva(
  'inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden',
  {
    variants: {
      variant: {
        default:
          'border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90',
        secondary:
          'border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90',
        destructive:
          'border-transparent bg-destructive text-white [a&]:hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60',
        outline:
          'text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground',
        // Status variants with standardized colors
        success:
          'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
        warning:
          'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
        error: 'bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20',
        info: 'bg-slate-500/10 text-slate-600 dark:text-slate-400 border-slate-500/20',
        pending:
          'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
        processing:
          'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
        active:
          'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
        inactive: 'bg-muted text-muted-foreground border-border',
        completed:
          'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
        failed:
          'bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20',
        verified:
          'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
        /** Deployment status rows only (see getDeploymentStatusBadge). Ready = blue; building = grey. */
        deploymentReady:
          'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
        deploymentBuilding:
          'bg-slate-500/10 text-slate-600 dark:text-slate-400 border-slate-500/20',
        unverified:
          'bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  },
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<'span'> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : 'span'

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
