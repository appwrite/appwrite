import type { ReactNode } from 'react'
import { Building2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type ConsoleNoOrganizationsScreenProps = {
  /**
   * `fill` - grow inside a parent that already defines full viewport height (e.g. under a top banner).
   * `fullscreen` - default standalone page.
   */
  layout?: 'fullscreen' | 'fill'
  /** Primary actions (e.g. create organization) shown under the description. */
  actions?: ReactNode
}

export function ConsoleNoOrganizationsScreen({
  layout = 'fill',
  actions,
}: ConsoleNoOrganizationsScreenProps) {
  const t = useT()
  const isFill = layout === 'fill'

  const title = t('No organizations for this account')
  const description = t(
    'This account is not a member of any organization. Ask an organization owner to invite you, or use Account in the menu to manage your profile.',
  )

  return (
    <div
      className={cn(
        'flex w-full flex-col items-center justify-center gap-8 px-4 py-12',
        isFill ? 'min-h-0 flex-1' : 'min-h-svh bg-background',
      )}
      role="status"
    >
      <div className="flex w-full max-w-md flex-col items-center gap-6">
        <div className="rounded-full bg-muted p-3">
          <Building2
            className="h-8 w-8 text-muted-foreground"
            aria-hidden
          />
        </div>
        <div className="space-y-2 text-center">
          <h1 className="text-xl font-semibold text-foreground">{title}</h1>
          <p className="text-sm leading-relaxed text-muted-foreground">
            {description}
          </p>
        </div>
        {actions ? (
          <div className="flex w-full flex-col items-stretch gap-2 sm:flex-row sm:justify-center">
            {actions}
          </div>
        ) : null}
      </div>
    </div>
  )
}
