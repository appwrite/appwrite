const PRIZE_ZOOM_EASE = 'ease-[cubic-bezier(0.33,0,0.2,1)]'
const PRIZE_ZOOM_EASE_COMPACT = 'ease-[cubic-bezier(0.22,1,0.36,1)]'

/** Grand prize and other large image frames. */
export const PRIZE_IMAGE_HOVER_ZOOM =
  `transition-transform duration-[1600ms] ${PRIZE_ZOOM_EASE} will-change-transform group-hover:scale-[1.03]`

/** Daily cells and sidebar promo: slower ramp and subtler scale so small frames do not snap. */
export const PRIZE_IMAGE_HOVER_ZOOM_COMPACT =
  `transition-transform duration-[2200ms] ${PRIZE_ZOOM_EASE_COMPACT} will-change-transform group-hover:scale-[1.02]`

export const PRIZE_SWAG_IMAGE_OPACITY =
  `opacity-[0.85] transition-opacity duration-[1600ms] ${PRIZE_ZOOM_EASE} group-hover:opacity-100`

export const PRIZE_SWAG_IMAGE_OPACITY_COMPACT =
  `opacity-[0.88] transition-opacity duration-[2200ms] ${PRIZE_ZOOM_EASE_COMPACT} group-hover:opacity-100`

export const PRIZE_IMAGE_INSET = 'p-3 sm:p-4'

export const PRIZE_IMAGE_FRAME = 'relative overflow-hidden rounded-lg'

export const PRIZE_CARD_BG = 'bg-card'
