import { ChevronRight } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import {
  BeepPhone,
  BeepSettingsGroup,
  BeepSwitch,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'

/** Settings behind the gear: what reaches you, from which organizations and projects. */
export function BeepSettingsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] justify-center py-4">
      <div className="product-hero-rise" style={riseStyle(40)}>
        <BeepPhone>
          <div className="flex items-center justify-between px-4 pt-1">
            <p className="font-aeonik-pro text-[23px] leading-none tracking-tight text-foreground">
              {t('Settings')}
            </p>
            <span className="text-[10.5px] font-semibold text-[var(--tone-ink)]">
              {t('Done')}
            </span>
          </div>
          <div className="mt-3.5 space-y-3 px-3.5">
            <BeepSettingsGroup
              title="Notifications"
              rows={[
                {
                  label: t('Snooze'),
                  trailing: (
                    <span className="flex items-center gap-0.5 text-[9.5px] text-muted-foreground">
                      {t('Off')}
                      <ChevronRight className="size-3" aria-hidden />
                    </span>
                  ),
                },
                {
                  label: t('Resolved alerts'),
                  detail: t('When an alert stops firing'),
                  trailing: <BeepSwitch on />,
                },
                {
                  label: t('Product updates'),
                  detail: t('When Appwrite publishes a changelog entry'),
                  trailing: <BeepSwitch on />,
                },
              ]}
            />
            <BeepSettingsGroup
              title="Organizations"
              rows={[
                { label: 'Acme Inc', trailing: <BeepSwitch on /> },
                { label: 'Side projects', trailing: <BeepSwitch on={false} /> },
              ]}
            />
            <BeepSettingsGroup
              title="Projects in Acme Inc"
              rows={[
                {
                  label: <span dir="ltr">storefront-prod</span>,
                  trailing: <BeepSwitch on />,
                },
                {
                  label: <span dir="ltr">checkout-api</span>,
                  trailing: <BeepSwitch on />,
                },
                {
                  label: <span dir="ltr">staging</span>,
                  trailing: <BeepSwitch on={false} />,
                },
              ]}
              footer="These settings belong to your console account and apply on all your devices. Emails are unaffected."
            />
          </div>
        </BeepPhone>
      </div>
    </div>
  )
}
