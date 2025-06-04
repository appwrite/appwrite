/**
 * Provides a try guarded require call that will throw a more detailed error when
 * the ERR_REQUIRE_ESM error code is encountered.
 *
 * @param {string} path File path to require from.
 */
export default function tryRequire(path: string): any;
