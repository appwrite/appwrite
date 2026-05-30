import { Heart } from 'lucide-react'

const APPWRITE_PRESENCES_DOCS_URL = 'https://appwrite.io/docs/apis/realtime/presences'
const ACETERNITY_GLOBE_URL = 'https://ui.aceternity.com/components/github-globe'

const CREDIT_LINE_CLASS = 'text-[12px] leading-relaxed text-muted-foreground'
const CREDIT_LINK_CLASS =
  'font-medium text-foreground underline-offset-4 hover:underline'

interface InitPageCreditsProps {
  showPresenceCredit?: boolean
  showGlobeCredit?: boolean
}

export function InitPageCredits({
  showPresenceCredit = false,
  showGlobeCredit = false,
}: InitPageCreditsProps) {
  return (
    <div className="space-y-2 text-center">
      <p className="flex items-center justify-center gap-1.5 text-[13px] text-muted-foreground">
        Built with love
        <Heart className="size-3.5 shrink-0 fill-current text-muted-foreground" aria-hidden />
        for the Appwrite community
      </p>
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
        {showGlobeCredit ? (
          <>
            Community globe by{' '}
            <a
              href={ACETERNITY_GLOBE_URL}
              target="_blank"
              rel="noopener noreferrer"
              className={CREDIT_LINK_CLASS}
            >
              Aceternity UI
            </a>
            <span className="text-muted-foreground"> · </span>
          </>
        ) : null}
        Jool animation by{' '}
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
