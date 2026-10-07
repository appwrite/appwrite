import { Shield } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

/**
 * Sidebar for the platform step only. The connection diagram is shown on step 3 (setup).
 */
export function ConfigureWizardAside() {
  const t = useT()
  return (
    <div className="rounded-xl border border-border bg-card/50 p-5">
      <div className="flex items-start gap-3">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
          <Shield className="h-4 w-4 text-muted-foreground" />
        </div>
        <div className="min-w-0">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Why register an app?')}
          </p>
          <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
            {t(
              'Apps tell Appwrite which origins or bundle IDs are allowed to call your project API. Choose the kind of client you are building - you can register more apps later.', // pragma: allowlist secret
            )}
          </p>
          <ul className="mt-3 list-disc space-y-1.5 ps-4 text-[12px] leading-snug text-muted-foreground">
            <li>
              <span className="font-medium text-foreground/90">{t('Web')}</span> -{' '}
              {t('allowed hostnames (origins)')}
            </li>
            <li>
              <span className="font-medium text-foreground/90">{t('Mobile & desktop')}</span>{' '}
              - {t('bundle ID or package name')}
            </li>
          </ul>
        </div>
      </div>
    </div>
  )
}
