import './index.css'
import { Composition } from 'remotion'
import {
  APPWRITE_2_LAUNCH_DURATION,
  Appwrite2LaunchVideo,
} from './Appwrite2LaunchVideo'
import { DURATION_IN_FRAMES, FPS, VIDEO } from './constants'

export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition
        id="Appwrite2Launch"
        component={Appwrite2LaunchVideo}
        durationInFrames={DURATION_IN_FRAMES}
        fps={FPS}
        width={VIDEO.width}
        height={VIDEO.height}
      />
      <Composition
        id="Appwrite2LaunchVertical"
        component={Appwrite2LaunchVideo}
        durationInFrames={APPWRITE_2_LAUNCH_DURATION}
        fps={FPS}
        width={1080}
        height={1920}
      />
    </>
  )
}
