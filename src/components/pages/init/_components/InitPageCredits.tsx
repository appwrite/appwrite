const APPWRITE_PRESENCES_DOCS_URL = 'https://appwrite.io/docs/apis/realtime/presences'

interface InitPageCreditsProps {
  showPresenceCredit?: boolean
}

export function InitPageCredits({ showPresenceCredit = false }: InitPageCreditsProps) {
  return (
    <div className="space-y-1 text-center text-[12px] text-muted-foreground">
      {showPresenceCredit ? (
        <p className="text-[11px] leading-relaxed text-muted-foreground/70">
          Realtime powered by{' '}
          <a
            href={APPWRITE_PRESENCES_DOCS_URL}
            target="_blank"
            rel="noopener noreferrer"
            className="font-medium text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
          >
            Appwrite Presences
          </a>
        </p>
      ) : null}
      <p>
        Hero particle animation by{' '}
        <a
          href="https://animejs.com/"
          target="_blank"
          rel="noopener noreferrer"
          className="font-medium text-foreground underline-offset-4 hover:underline"
        >
          Anime.js
        </a>
      </p>
    </div>
  )
}
