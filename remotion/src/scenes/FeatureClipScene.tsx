import {
  AbsoluteFill,
  Easing,
  interpolate,
  Sequence,
  useCurrentFrame,
} from 'remotion'
import { BrandTypeTitle } from '../components/BrandTypeTitle'
import { EnterSwooshSound } from '../components/EnterSwooshSound'
import { FeatureScreenshotPlaceholder } from '../components/FeatureScreenshotPlaceholder'
import { HomeHeroBrowserFrame } from '../components/HomeHeroBrowserFrame'
import { TypingSoundEffects } from '../components/TypingSoundEffects'
import {
  SWOOSH_SOUND_SRC,
  SWOOSH_SOUND_VOLUME,
} from '../constants'
import {
  FEATURE_CLIP_ENTER_FRAMES,
  FEATURE_CLIP_EXIT_FRAMES,
  getFeatureTitlePhaseEnd,
  TITLE_TYPE_DELAY,
} from '../lib/feature-scene-timing'
import {
  getSplitFeatureBrowserShellHeight,
  getSplitFeatureBrowserTop,
  getSplitFeatureBrowserTravelDistanceX,
  getSplitFeatureBrowserWidth,
  getSplitFeatureBrowserZoneLeft,
  getSplitFeatureBrowserZoneWidth,
  getSplitFeatureTitleColumnWidth,
  getSplitFeatureTitleTravelDistanceX,
  SPLIT_FEATURE_CONTENT_ASPECT,
  SPLIT_FEATURE_ENTER_FRAMES,
  SPLIT_FEATURE_EXIT_FRAMES,
  SPLIT_FEATURE_LINE_HEIGHT,
  SPLIT_FEATURE_PADDING_X,
  SPLIT_FEATURE_TITLE_FONT_SIZE,
} from '../lib/split-feature-layout'

export type FeatureClipSceneProps = {
  text: string
  textLines?: string[]
  pageTitle: string
  videoSrc?: string
  videoStartFrame?: number
  imageSrc?: string
  videoDurationFrames: number
  layout?: 'default' | 'split'
  contentAspectRatio?: number
}

/** Typewriter title, then a browser card clip for a console feature. */
export function FeatureClipScene({
  text,
  textLines,
  pageTitle,
  videoSrc,
  videoStartFrame = 0,
  videoDurationFrames,
  layout = 'default',
  contentAspectRatio,
  imageSrc,
}: FeatureClipSceneProps) {
  if (layout === 'split') {
    return (
      <SplitFeatureClipScene
        text={text}
        textLines={textLines ?? [text]}
        pageTitle={pageTitle}
        videoSrc={videoSrc}
        videoStartFrame={videoStartFrame}
        videoDurationFrames={videoDurationFrames}
        contentAspectRatio={
          contentAspectRatio ?? SPLIT_FEATURE_CONTENT_ASPECT
        }
      />
    )
  }

  return (
    <StackedFeatureClipScene
      text={text}
      pageTitle={pageTitle}
      imageSrc={imageSrc}
      videoDurationFrames={videoDurationFrames}
    />
  )
}

type FeatureClipContentProps = {
  text: string
  textLines?: string[]
  pageTitle: string
  videoSrc?: string
  videoStartFrame?: number
  imageSrc?: string
  videoDurationFrames: number
}

function StackedFeatureClipScene({
  text,
  pageTitle,
  imageSrc,
  videoDurationFrames,
}: Pick<
  FeatureClipContentProps,
  'text' | 'pageTitle' | 'imageSrc' | 'videoDurationFrames'
>) {
  const frame = useCurrentFrame()
  const titlePhaseEnd = getFeatureTitlePhaseEnd(text)
  const clipDuration =
    FEATURE_CLIP_ENTER_FRAMES + videoDurationFrames + FEATURE_CLIP_EXIT_FRAMES

  const titleOpacity = interpolate(
    frame,
    [titlePhaseEnd - 6, titlePhaseEnd + 10],
    [1, 0],
    {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.out(Easing.quad),
    },
  )

  return (
    <AbsoluteFill>
      {frame < titlePhaseEnd + FEATURE_CLIP_ENTER_FRAMES ? (
        <>
          <TypingSoundEffects text={text} delay={TITLE_TYPE_DELAY} />
          <AbsoluteFill
            className="flex items-center justify-center px-24 text-center"
            style={{ opacity: titleOpacity }}
          >
            <BrandTypeTitle text={text} size="lg" delay={TITLE_TYPE_DELAY} />
          </AbsoluteFill>
        </>
      ) : null}

      <Sequence
        from={titlePhaseEnd}
        durationInFrames={clipDuration}
        layout="none"
      >
        <FeatureScreenshotClip
          pageTitle={pageTitle}
          imageSrc={imageSrc}
          videoDurationFrames={videoDurationFrames}
        />
      </Sequence>
    </AbsoluteFill>
  )
}

function SplitFeatureClipScene({
  textLines,
  pageTitle,
  videoSrc,
  videoStartFrame,
  videoDurationFrames,
  contentAspectRatio,
}: FeatureClipContentProps & {
  textLines: string[]
  contentAspectRatio: number
}) {
  const frame = useCurrentFrame()
  const holdStart = SPLIT_FEATURE_ENTER_FRAMES
  const exitStart = holdStart + videoDurationFrames
  const exitEnd = exitStart + SPLIT_FEATURE_EXIT_FRAMES

  const frameWidth = getSplitFeatureBrowserWidth()
  const browserZoneLeft = getSplitFeatureBrowserZoneLeft()
  const browserZoneWidth = getSplitFeatureBrowserZoneWidth()
  const shellHeight = getSplitFeatureBrowserShellHeight(
    frameWidth,
    contentAspectRatio,
  )
  const browserTop = getSplitFeatureBrowserTop(frameWidth, contentAspectRatio)
  const titleColumnWidth = getSplitFeatureTitleColumnWidth()
  const titleTravelX = getSplitFeatureTitleTravelDistanceX()
  const browserTravelX = getSplitFeatureBrowserTravelDistanceX(frameWidth)

  const enterProgress = interpolate(frame, [0, holdStart], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.out(Easing.cubic),
  })

  const exitProgress = interpolate(frame, [exitStart, exitEnd - 1], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.in(Easing.cubic),
  })

  const titleTranslateX =
    frame >= exitStart
      ? interpolate(exitProgress, [0, 1], [0, -titleTravelX])
      : interpolate(enterProgress, [0, 1], [-titleTravelX, 0])

  const browserTranslateX =
    frame >= exitStart
      ? interpolate(exitProgress, [0, 1], [0, browserTravelX])
      : interpolate(enterProgress, [0, 1], [browserTravelX, 0])

  return (
    <AbsoluteFill style={{ overflow: 'hidden' }}>
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={SPLIT_FEATURE_ENTER_FRAMES}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={SPLIT_FEATURE_EXIT_FRAMES}
        startFrame={exitStart}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />

      <div
        style={{
          position: 'absolute',
          left: SPLIT_FEATURE_PADDING_X,
          top: 0,
          bottom: 0,
          width: titleColumnWidth,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'flex-start',
          transform: `translateX(${titleTranslateX}px)`,
        }}
      >
        <h1
          className="font-aeonik"
          style={{
            margin: 0,
            fontSize: SPLIT_FEATURE_TITLE_FONT_SIZE,
            lineHeight: SPLIT_FEATURE_LINE_HEIGHT,
            color: '#fafafa',
            letterSpacing: '-0.022em',
            textAlign: 'left',
          }}
        >
          {textLines.map((line, index) => (
            <span key={index} style={{ display: 'block' }}>
              {line}
            </span>
          ))}
        </h1>
      </div>

      <div
        style={{
          position: 'absolute',
          left: browserZoneLeft,
          top: browserTop,
          width: browserZoneWidth,
          height: shellHeight,
          overflow: 'hidden',
        }}
      >
        <div
          style={{
            width: frameWidth,
            height: shellHeight,
            transform: `translateX(${browserTranslateX}px)`,
          }}
        >
          <HomeHeroBrowserFrame
            width={frameWidth}
            pageTitle={pageTitle}
            videoSrc={videoSrc!}
            videoTrimBefore={videoStartFrame ?? 0}
            videoFitWidth
            contentAspectRatio={contentAspectRatio}
            closed
          />
        </div>
      </div>
    </AbsoluteFill>
  )
}

function FeatureScreenshotClip({
  pageTitle,
  imageSrc,
  videoDurationFrames,
}: {
  pageTitle: string
  imageSrc?: string
  videoDurationFrames: number
}) {
  const frame = useCurrentFrame()
  const exitStartFrame = FEATURE_CLIP_ENTER_FRAMES + videoDurationFrames
  const inset = 64

  const enterProgress = interpolate(
    frame,
    [0, FEATURE_CLIP_ENTER_FRAMES],
    [0, 1],
    {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.out(Easing.cubic),
    },
  )

  const exitProgress = interpolate(
    frame,
    [exitStartFrame, exitStartFrame + FEATURE_CLIP_EXIT_FRAMES - 1],
    [0, 1],
    {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.in(Easing.cubic),
    },
  )

  const enterOpacity = enterProgress
  const enterScale = interpolate(enterProgress, [0, 1], [0.985, 1])
  const exitOpacity = interpolate(exitProgress, [0, 1], [1, 0])
  const exitY = interpolate(exitProgress, [0, 1], [0, 48])
  const opacity = frame >= exitStartFrame ? exitOpacity : enterOpacity
  const translateY = frame >= exitStartFrame ? exitY : 0
  const scale = frame >= exitStartFrame ? 1 : enterScale

  return (
    <>
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={FEATURE_CLIP_ENTER_FRAMES}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />
      <EnterSwooshSound
        src={SWOOSH_SOUND_SRC}
        durationFrames={FEATURE_CLIP_EXIT_FRAMES}
        startFrame={exitStartFrame}
        peakVolume={SWOOSH_SOUND_VOLUME}
      />
      <AbsoluteFill
        className="flex items-center justify-center"
        style={{
          overflow: 'hidden',
          padding: inset,
          opacity,
          transform: `translateY(${translateY}px) scale(${scale})`,
        }}
      >
        <FeatureScreenshotPlaceholder
          pageTitle={pageTitle}
          imageSrc={imageSrc}
        />
      </AbsoluteFill>
    </>
  )
}
