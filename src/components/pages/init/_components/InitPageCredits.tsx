const APPWRITE_PRESENCES_DOCS_URL = 'https://appwrite.io/docs/apis/realtime/presences'

const CREDIT_LINE_CLASS = 'text-[12px] leading-relaxed text-muted-foreground'
const CREDIT_LINK_CLASS =
  'font-medium text-foreground underline-offset-4 hover:underline'

interface InitPageCreditsProps {
  showPresenceCredit?: boolean
}

export function InitPageCredits({ showPresenceCredit = false }: InitPageCreditsProps) {
  return (
    <div className="space-y-1 text-center">
      {showPresenceCredit ? (
        <p className={CREDIT_LINE_CLASS}>
          Realtime powered by{' '}
          <a
            href={APPWRITE_PRESENCES_DOCS_URL}
            target="_blank"
            rel="noopener noreferrer"
            className={CREDIT_LINK_CLASS}
          >
            Appwrite Presences
          </a>
        </p>
      ) : null}
      <p className={CREDIT_LINE_CLASS}>
        Hero particle animation by{' '}
        <a
          href="https://animejs.com/"
          target="_blank"
          rel="noopener noreferrer"
          className={CREDIT_LINK_CLASS}
        >
          Anime.js
        </a>
      </p>
    </div>
  )
}
