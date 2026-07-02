import { GitBranch, Terminal, Upload } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type DeployOption = {
  id: string
  title: string
  description: string
  icon: LucideIcon
}

const DEPLOY_OPTIONS: DeployOption[] = [
  {
    id: 'git',
    title: 'Git',
    description:
      'Connect a repository for automatic builds on push, branch previews, and production deploys.',
    icon: GitBranch,
  },
  {
    id: 'cli',
    title: 'CLI',
    description:
      'Deploy from CI or your terminal with the Appwrite CLI and appwrite.config.json.',
    icon: Terminal,
  },
  {
    id: 'manual',
    title: 'Manual upload',
    description:
      'Package your source as .tar.gz and upload from the Console when you need a one-off deploy.',
    icon: Upload,
  },
]

type SitesDeployOptionsProps = {
  className?: string
}

function DeployOptionTile({ option, className }: { option: DeployOption; className?: string }) {
  const t = useT()
  return (
    <div className={cn('p-4 sm:p-5', className)}>
      <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
        <option.icon className="size-3.5 text-muted-foreground" aria-hidden />
      </span>
      <h3 className="mt-3 text-[14px] font-semibold text-foreground">{t(option.title)}</h3>
      <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">{t(option.description)}</p>
    </div>
  )
}

export function SitesDeployOptions({ className }: SitesDeployOptionsProps) {
  return (
    <div
      className={cn(
        'grid overflow-hidden rounded-xl border border-border bg-card/45 sm:grid-cols-3',
        className,
      )}
    >
      {DEPLOY_OPTIONS.map((option, index) => (
        <DeployOptionTile
          key={option.id}
          option={option}
          className={cn(
            index < DEPLOY_OPTIONS.length - 1 && 'border-b border-border sm:border-b-0 sm:border-e',
          )}
        />
      ))}
    </div>
  )
}
