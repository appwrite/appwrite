import { AbsoluteFill, Sequence } from 'remotion'
import {
  DURATION_IN_FRAMES,
  FEATURE_CLIP_PREMOUNT_FRAMES,
  SCENES,
} from './constants'
import { BrandBackground } from './components/BrandBackground'
import { LaunchBackgroundMusic } from './components/LaunchBackgroundMusic'
import { VideoPrefetch } from './components/VideoPrefetch'
import { BrandScene } from './components/BrandScene'
import { TitleScene } from './scenes/TitleScene'
import { ConsoleScreenshotScene } from './scenes/ConsoleScreenshotScene'
import { FlowPanelsScene } from './scenes/FlowPanelsScene'
import { FeatureClipScene } from './scenes/FeatureClipScene'
import { FinaleScene } from './scenes/FinaleScene'

export const Appwrite2LaunchVideo = () => {
  return (
    <AbsoluteFill>
      <BrandBackground />
      <LaunchBackgroundMusic />
      <VideoPrefetch />

      <Sequence
        from={SCENES.introducing.from}
        durationInFrames={SCENES.introducing.duration}
      >
        <BrandScene durationInFrames={SCENES.introducing.duration} exitTo="left">
          <TitleScene
            text={SCENES.introducing.text}
            size={SCENES.introducing.size}
          />
        </BrandScene>
      </Sequence>

      <Sequence
        from={SCENES.intro.from}
        durationInFrames={SCENES.intro.duration}
      >
        <BrandScene durationInFrames={SCENES.intro.duration} exitTo="left">
          <TitleScene text={SCENES.intro.text} size={SCENES.intro.size} />
        </BrandScene>
      </Sequence>

      <Sequence
        from={SCENES.consoleScreenshot.from}
        durationInFrames={SCENES.consoleScreenshot.duration}
      >
        <BrandScene
          durationInFrames={SCENES.consoleScreenshot.duration}
          skipExit
        >
          <ConsoleScreenshotScene />
        </BrandScene>
      </Sequence>

      <Sequence
        from={SCENES.thesis.from}
        durationInFrames={SCENES.thesis.duration}
      >
        <BrandScene durationInFrames={SCENES.thesis.duration} exitTo="left">
          <TitleScene text={SCENES.thesis.text} size={SCENES.thesis.size} />
        </BrandScene>
      </Sequence>

      <Sequence
        from={SCENES.flowPanels.from}
        durationInFrames={SCENES.flowPanels.duration}
      >
        <BrandScene durationInFrames={SCENES.flowPanels.duration} exitTo="left">
          <FlowPanelsScene />
        </BrandScene>
      </Sequence>

      {SCENES.features.map((scene) => (
        <Sequence
          key={scene.id}
          from={scene.from}
          durationInFrames={scene.duration}
          premountFor={FEATURE_CLIP_PREMOUNT_FRAMES}
        >
          <BrandScene durationInFrames={scene.duration} exitTo="left">
            <FeatureClipScene
              text={scene.text}
              textLines={scene.textLines}
              pageTitle={scene.pageTitle}
              videoSrc={scene.videoSrc}
              videoStartFrame={scene.videoStartFrame}
              videoDurationFrames={scene.videoDurationFrames}
              layout={scene.layout}
              contentAspectRatio={scene.contentAspectRatio}
              imageSrc={scene.imageSrc}
            />
          </BrandScene>
        </Sequence>
      ))}

      <Sequence
        from={SCENES.slogan.from}
        durationInFrames={SCENES.slogan.duration}
      >
        <BrandScene durationInFrames={SCENES.slogan.duration} exitTo="left">
          <TitleScene text={SCENES.slogan.text} size={SCENES.slogan.size} />
        </BrandScene>
      </Sequence>

      <Sequence
        from={SCENES.finale.from}
        durationInFrames={SCENES.finale.duration}
      >
        <BrandScene durationInFrames={SCENES.finale.duration} skipExit>
          <FinaleScene />
        </BrandScene>
      </Sequence>
    </AbsoluteFill>
  )
}

export const APPWRITE_2_LAUNCH_DURATION = DURATION_IN_FRAMES
