import { assetUrl } from '@/lib/asset-url'
import { Mail } from 'lucide-react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { useT } from '@/lib/i18n/translate'

type MarketplaceAppContactsProps = {
  contacts: string[]
}

export function MarketplaceAppContacts({
  contacts,
}: MarketplaceAppContactsProps) {
  const t = useT()
  const entries = contacts.map((contact) => contact.trim()).filter(Boolean)
  if (entries.length === 0) return null

  return (
    <section className="space-y-2.5">
      <h4 className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
        {t('Contacts')}
      </h4>
      <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
        {entries.map((contact) => {
          const isEmail = contact.includes('@')
          const body = (
            <>
              {/* Contact addresses are company inboxes, so their avatars are
                  generic placeholders — only named contacts get one. */}
              {!isEmail && (
                <InitialsAvatar name={contact} size="sm" className="shrink-0" />
              )}
              <p className="min-w-0 flex-1 text-[13px] font-medium text-foreground truncate">
                {contact}
              </p>
              {isEmail && (
                <Mail
                  className="h-3.5 w-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                  aria-hidden
                />
              )}
            </>
          )
          const cardClass =
            'group flex items-center gap-3 rounded-lg border border-border bg-card px-4 py-3'

          return isEmail ? (
            <a
              key={contact}
              href={assetUrl(`mailto:${contact}`)}
              className={`${cardClass} transition-colors hover:border-foreground/20 hover:bg-accent/40`}
            >
              {body}
            </a>
          ) : (
            <div key={contact} className={cardClass}>
              {body}
            </div>
          )
        })}
      </div>
    </section>
  )
}
