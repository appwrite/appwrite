import { Link } from '@tanstack/react-router'
import { Badge } from '@/components/ui/badge'
import {
  HoverCard,
  HoverCardContent,
  HoverCardTrigger,
} from '@/components/ui/hover-card'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { TerraformIcon } from '@/components/global/shared/TerraformIcon'
import { useTerraformProject } from '@/lib/react-query/hooks/terraform'
import { isTerraformProjectManaged } from '@/lib/terraform/state'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type TerraformBadgeProps = {
  projectId: string
  /** Activity resource path; omit for the project as a whole. */
  resource?: string | null
  className?: string
}

/** "Terraform" pill with a hover card explaining where the claim comes from. */
export function TerraformBadge({
  projectId,
  resource,
  className,
}: TerraformBadgeProps) {
  const t = useT()
  const project = useTerraformProject(projectId)
  const managed = resource
    ? project?.resources[resource]
    : isTerraformProjectManaged(project)
      ? project
      : null
  if (!project || !managed) return null

  const resourceCount = Object.keys(project.resources).length

  return (
    <HoverCard openDelay={150} closeDelay={100}>
      <HoverCardTrigger asChild>
        <Badge
          variant="terraform"
          tabIndex={0}
          className={cn(
            'cursor-default text-[10px] font-medium shrink-0',
            className,
          )}
        >
          <TerraformIcon variant="mark" className="h-2.5 w-2.5" />
          {t('Terraform')}
        </Badge>
      </HoverCardTrigger>
      <HoverCardContent align="start" className="w-80 p-0 text-[12px]">
        <div className="flex gap-2.5 px-3.5 py-3">
          <span className="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-violet-500/10 text-violet-600 dark:text-violet-400">
            <TerraformIcon variant="mark" className="h-3.5 w-3.5" />
          </span>
          <div className="min-w-0 space-y-0.5">
            <p className="text-[13px] font-semibold text-foreground">
              {t('Managed by Terraform')}
            </p>
            <p className="text-muted-foreground">
              {resource
                ? t(
                    'The Appwrite Terraform provider created this resource. Update it in your Terraform configuration.',
                  )
                : t(
                    'Resources in this project were created by the Appwrite Terraform provider. Update them in your Terraform configuration.',
                  )}
            </p>
          </div>
        </div>
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 border-t border-border px-3.5 py-2.5">
          {resource ? null : (
            <>
              <dt className="text-muted-foreground">
                {t('Managed resources')}
              </dt>
              <dd className="text-end tabular-nums text-foreground">
                {resourceCount}
              </dd>
            </>
          )}
          {managed.providerVersion ? (
            <>
              <dt className="text-muted-foreground">{t('Provider')}</dt>
              <dd className="truncate text-end font-mono text-foreground">
                {managed.providerVersion}
              </dd>
            </>
          ) : null}
          {managed.appliedAt ? (
            <>
              <dt className="text-muted-foreground">{t('Last apply')}</dt>
              <dd className="text-end text-foreground">
                <DateTooltip date={managed.appliedAt} disableTooltip />
              </dd>
            </>
          ) : null}
          {!resource && project.keyName ? (
            <>
              <dt className="text-muted-foreground">{t('API key')}</dt>
              <dd className="truncate text-end text-foreground">
                {project.keyName}
              </dd>
            </>
          ) : null}
        </dl>
        <div className="border-t border-border px-3.5 py-2.5">
          <Link
            to="/projects/$projectId/activity"
            params={{ projectId }}
            className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
          >
            {t('View activity')}
          </Link>
        </div>
      </HoverCardContent>
    </HoverCard>
  )
}
