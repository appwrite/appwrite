import { HomeHeroBrowserFrame } from './HomeHeroBrowserFrame'
import { getBrowserCardWidth } from '../lib/browser-card-layout'

type FeatureScreenshotPlaceholderProps = {
  pageTitle: string
  imageSrc?: string
  width?: number
}

/** Browser frame placeholder - empty content until a screenshot is added. */
export function FeatureScreenshotPlaceholder({
  pageTitle,
  imageSrc,
  width = getBrowserCardWidth(),
}: FeatureScreenshotPlaceholderProps) {
  return (
    <HomeHeroBrowserFrame
      width={width}
      pageTitle={pageTitle}
      closed
      emptyContent={!imageSrc}
      imageSrc={imageSrc}
    />
  )
}
