import { Link } from '@tanstack/react-router'
import { Copy, ShieldCheck, Unplug, type LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { EnvironmentsUpgradeVisual } from '@/components/pages/projects/$projectId/shared/EnvironmentsUpgradeVisual'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import type { ProjectEnvironmentExample } from '@/lib/project-environments'

const BENEFITS: readonly {
  icon: LucideIcon
  title: string
  description: string
}[] = [
  {
    icon: Copy,
    title: 'Start from a copy of production',
    description:
      'Copy databases, functions and settings. Bringing data is optional.',
  },
  {
    icon: ShieldCheck,
    title: 'Keep production untouched',
    description: 'Each environment has its own data, files and users.',
  },
  {
    icon: Unplug,
    title: 'Switch with one line',
    description:
      'Same project ID and endpoint. Set the environment ID in your SDK.',
  },
]

interface EnvironmentsUpgradeProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  orgId: string
  /** The plan that offers environments: what the upgrade leads to. */
  plan: { $id: string; name: string; price: number; environments: number }
  /** The example picked in the switcher, drawn as created. */
  example: ProjectEnvironmentExample['id']
}

/**
 * What environments are and what they take, for a project whose plan offers
 * none. Opened from the examples in the environment switcher; the way on is the
 * upgrade wizard, with the plan that has them already picked.
 */
export function EnvironmentsUpgrade({
  open,
  onOpenChange,
  orgId,
  plan,
  example,
}: EnvironmentsUpgradeProps) {
  const t = useT()
  const { features } = useConsoleProfile()

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] gap-0 overflow-y-auto p-0 sm:max-w-[520px]">
        <EnvironmentsUpgradeVisual picked={example} />
        <DialogHeader className="px-6 pt-5 pb-4 text-start">
          <div className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground">
            {t('Environments')}
            <Badge variant="outline" className="px-1.5 py-0 text-[10px]">
              {t('Beta')}
            </Badge>
          </div>
          <DialogTitle>{t('Try changes before your users do')}</DialogTitle>
          <DialogDescription className="text-[13px]">
            {t(
              'An environment is a separate copy of your project, with its own resources and users.',
            )}{' '}
            {t('{plan} includes up to {limit} environments per project.')
              .replace('{plan}', plan.name)
              .replace('{limit}', String(plan.environments))}
          </DialogDescription>
        </DialogHeader>
        <ul className="space-y-3 px-6 pb-5">
          {BENEFITS.map(({ icon: Icon, title, description }) => (
            <li key={title} className="flex items-start gap-3">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="h-4 w-4" />
              </div>
              <div className="min-w-0">
                <p className="text-[13px] font-medium text-foreground">
                  {t(title)}
                </p>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  {t(description)}
                </p>
              </div>
            </li>
          ))}
        </ul>
        <div className="flex flex-col gap-3 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-[12px] text-muted-foreground">
            <span className="font-medium text-foreground">{plan.name}</span>
            {' · '}
            {formatCurrency(plan.price)} {t('per month')}
          </p>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button variant="outline" asChild>
              <a
                href={getDocsPageUrl(
                  '/docs/sdks#environments',
                  features.marketing,
                )}
                target="_blank"
                rel="noopener noreferrer"
              >
                {t('Read the docs')}
              </a>
            </Button>
            <Button variant="brandCta" asChild>
              <Link
                to="/upgrade"
                search={{ orgId, plan: plan.$id }}
                {...analyticsAttrs('upgrade-clicked')}
              >
                {t('Upgrade to {plan}').replace('{plan}', plan.name)}
              </Link>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
