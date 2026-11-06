import { Heart } from 'lucide-react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'

const APPWRITE_PRESENCES_DOCS_URL = '/docs/apis/realtime/presences'
const ACETERNITY_GLOBE_URL = 'https://ui.aceternity.com/components/github-globe'

const CREDIT_LINE_CLASS = 'text-[12px] leading-relaxed text-muted-foreground'
const CREDIT_LINK_CLASS = 'link-neutral'

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
          <DocsRouteLink href={APPWRITE_PRESENCES_DOCS_URL} className={CREDIT_LINK_CLASS}>
            Appwrite Presences
          </DocsRouteLink>
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
