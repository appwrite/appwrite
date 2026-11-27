import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { GitIcon } from '@/lib/vcs/providers'
import { useT } from '@/lib/i18n/translate'

/** Git installations belong to the production project; an environment cannot connect one. */
export function EnvironmentGitNotice() {
  const t = useT()
  return (
    <div className="rounded-lg border border-border bg-card/50 p-6 text-center">
      <div className="mb-3 flex justify-center">
        <AuthFlowHeaderIcon icon={GitIcon} />
      </div>
      <h3 className="mb-1 text-[13px] font-medium text-foreground">
        {t('Git is not available in environments')}
      </h3>
      <p className="text-[11px] text-muted-foreground">
        {t(
          'Deploy with the CLI or a template here. Connect Git repositories on the production project.',
        )}
      </p>
    </div>
  )
}
