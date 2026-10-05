import { GitBranch, Terminal, Upload } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type DeployOption = {
  id: string
  title: string
  description: string
  icon: LucideIcon
  tone: 'primary' | 'secondary' | 'neutral'
  snippet: string
}

const DEPLOY_OPTIONS: DeployOption[] = [
  {
    id: 'git',
    title: 'Git',
    description:
      'Connect a repository for automatic builds on push, branch previews, and production deploys.',
    icon: GitBranch,
    tone: 'primary',
    snippet: 'git push origin main',
  },
  {
    id: 'cli',
    title: 'CLI',
    description:
      'Deploy from CI or your terminal with the Appwrite CLI and appwrite.config.json.',
    icon: Terminal,
    tone: 'secondary',
    snippet: 'appwrite push sites',
  },
  {
    id: 'manual',
    title: 'Manual upload',
    description:
      'Package your source as .tar.gz and upload from the Console when you need a one-off deploy.',
    icon: Upload,
    tone: 'neutral',
    snippet: 'marketing-site.tar.gz',
  },
]

const PIPELINE_STEPS = ['Build', 'Logs', 'Domains', 'Rollback'] as const

type SitesDeployOptionsProps = {
  className?: string
}

export function SitesDeployOptions({ className }: SitesDeployOptionsProps) {
  const t = useT()

  return (
    <div className={cn('relative', className)}>
      <div className="grid gap-4 sm:grid-cols-3">
        {DEPLOY_OPTIONS.map((option, index) => (
          <ArtPanel
            key={option.id}
            delayMs={100 + index * 140}
            float
            floatDelayMs={index * 600}
            innerClassName="flex h-full flex-col p-4 sm:p-5"
            className="h-full"
          >
            <ArtIconBadge icon={option.icon} tone={option.tone} />
            <h3 className="mt-3 text-[14px] font-semibold text-foreground">{t(option.title)}</h3>
            <p className="mt-1.5 flex-1 text-[13px] leading-5 text-muted-foreground">{t(option.description)}</p>
            <p
              dir="ltr"
              className="mt-4 truncate rounded-lg border border-border bg-muted/30 px-2.5 py-1.5 text-start font-mono text-[11px] text-foreground"
            >
              {option.id === 'manual' ? null : <span className="text-muted-foreground">$ </span>}
              {option.snippet}
            </p>
          </ArtPanel>
        ))}
      </div>

      <div className="relative mx-auto hidden h-10 w-2/3 sm:block" aria-hidden>
        <span className="absolute start-0 top-0 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute start-1/2 top-0 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute end-0 top-0 h-1/2 border-e border-dashed border-foreground/25" />
        <span className="absolute inset-x-0 top-1/2 border-t border-dashed border-foreground/25" />
        <span className="absolute start-1/2 top-1/2 h-1/2 border-s border-dashed border-foreground/25" />
      </div>

      <div
        className="product-hero-rise mt-6 flex flex-wrap items-center justify-center gap-1.5 sm:mt-0"
        style={riseStyle(700)}
      >
        {PIPELINE_STEPS.map((step, index) => (
          <span key={step} className="flex items-center gap-1.5">
            <span
              className={cn(
                'inline-flex items-center gap-1.5 rounded-full border bg-background px-3 py-1.5 text-[12px] font-medium shadow-sm dark:bg-card',
                index === 0
                  ? 'border-[rgb(var(--tone-rgb)/0.45)] text-[var(--tone-ink)]'
                  : 'border-border text-foreground',
              )}
            >
              {t(step)}
            </span>
            {index < PIPELINE_STEPS.length - 1 ? (
              <span className="h-px w-3 bg-foreground/20" aria-hidden />
            ) : null}
          </span>
        ))}
      </div>
    </div>
  )
}
