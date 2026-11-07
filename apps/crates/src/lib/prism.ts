import Prism from 'prismjs'

// Prism's language files register themselves on a global `Prism`. The dev
// server provides it; the production bundle does not, so set it before they load.
;(globalThis as { Prism?: typeof Prism }).Prism = Prism

export default Prism
