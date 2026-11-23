import { getSecretVariantContent } from '@/lib/campaigns/secret/content'
import type { SecretCampaignVariant } from '@/lib/campaigns/secret/registry'
import { productToneAttrs } from '@/lib/products/theme'
import { SecretClosing } from './_components/SecretClosing'
import { SecretExhibits } from './_components/SecretExhibits'
import { SecretHero } from './_components/SecretHero'
import { SecretReveal } from './_components/SecretReveal'
import { SecretStickyCta } from './_components/SecretStickyCta'
import { SecretVerdict } from './_components/SecretVerdict'

const HERO_ID = 'secret-hero'
const CLOSING_ID = 'case-closed'

/**
 * "The secret ... is hiding from you" ad landing page. Always dark, to match the creative:
 * hero, six declassified exhibits, the reveal, a side-by-side comparison, migration, and the close.
 * A slim Appwrite bar follows the reader between the hero and the closing call to action.
 */
export function View({ variant }: { variant: SecretCampaignVariant }) {
  const content = getSecretVariantContent(variant)
  return (
    <div
      className="dark relative min-w-0 overflow-x-clip bg-background text-foreground [color-scheme:dark]"
      {...productToneAttrs({ tone: 'pink', secondaryTone: 'purple' })}
    >
      <SecretHero id={HERO_ID} content={content} />
      <SecretExhibits content={content} />
      <SecretReveal content={content} />
      <SecretVerdict content={content} />
      <SecretClosing content={content} />
      <SecretStickyCta heroId={HERO_ID} closingId={CLOSING_ID} />
    </div>
  )
}
