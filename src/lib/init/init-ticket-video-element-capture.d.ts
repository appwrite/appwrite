/** Chrome Region / Element Capture (CropTarget) - records painted pixels at display refresh rate. */
export interface CropTarget {}

export interface CropTargetConstructor {
  fromElement(element: Element): Promise<CropTarget>
}

declare global {
  interface Window {
    CropTarget?: CropTargetConstructor
  }

  interface MediaStreamTrack {
    cropTo?(cropTarget: CropTarget): Promise<void>
  }

  interface DisplayMediaStreamOptions extends MediaStreamConstraints {
    preferCurrentTab?: boolean
    selfBrowserSurface?: 'include' | 'exclude'
    surfaceSwitching?: 'include' | 'exclude'
  }
}

export {}
