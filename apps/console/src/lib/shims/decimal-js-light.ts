/**
 * ESM re-export for decimal.js-light. Recharts imports the default export as a
 * constructor (`new Decimal()`). Rolldown/Vite CJS interop can leave
 * `import_decimal.default` as a plain object instead of the constructor.
 */
import Decimal from 'decimal.js-light/decimal.mjs'

export { Decimal }
export default Decimal
