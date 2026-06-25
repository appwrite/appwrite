import { AbsoluteFill } from 'remotion'
import { BrandTypeTitle } from '../components/BrandTypeTitle'
import { TypingSoundEffects } from '../components/TypingSoundEffects'
import { TITLE_TYPE_DELAY } from '../lib/title-scene-timing'

type TitleSceneProps = {
  text: string
  size?: 'md' | 'lg' | 'xl' | 'hero'
}

export function TitleScene({ text, size = 'lg' }: TitleSceneProps) {
  return (
    <AbsoluteFill className="flex items-center justify-center px-24 text-center">
      <TypingSoundEffects text={text} delay={TITLE_TYPE_DELAY} />
      <BrandTypeTitle text={text} size={size} delay={TITLE_TYPE_DELAY} />
    </AbsoluteFill>
  )
}
