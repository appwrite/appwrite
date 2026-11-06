import {
  authFlowHeaderIconBoxClassName,
  authFlowHeaderIconClassName,
} from '@/components/global/auth/AuthFlowHeaderIcon'
import { AppwriteMarkIcon } from '@/components/global/shared/AppwriteMarkIcon'
import { GitHubIcon } from '@/lib/vcs/providers'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type EducationJoinPartnerHeaderProps = {
  className?: string
}

const partnerIconTileClassName = cn(
  authFlowHeaderIconBoxClassName,
  'rounded-full bg-muted text-muted-foreground ring-2 ring-card',
)

/**
 * Appwrite × GitHub partnership mark for the education program. Overlapping
 * circular tiles reuse auth header sizing but read as a dual-brand lockup.
 */
export function EducationJoinPartnerHeader({
  className,
}: EducationJoinPartnerHeaderProps) {
  const t = useT()

  return (
    <div
      className={cn('flex items-center justify-center', className)}
      role="img"
      aria-label={t('Appwrite and GitHub')}
    >
      <div className="flex items-center ps-0.5">
        <div className={cn(partnerIconTileClassName, 'relative z-10')}>
          <AppwriteMarkIcon className={authFlowHeaderIconClassName} />
        </div>
        <div className={cn(partnerIconTileClassName, '-ms-4')}>
          <GitHubIcon className={authFlowHeaderIconClassName} />
        </div>
      </div>
    </div>
  )
}
