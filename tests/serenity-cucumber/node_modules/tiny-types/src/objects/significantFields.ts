/**
 * @access private
 *
 * @param {object|Array} o
 * @returns {string[]}
 */
export function significantFieldsOf(o: { [_: string]: any }): string[] {
    return Object.getOwnPropertyNames(o)
        .filter(field => typeof o[field] !== 'function')
        .sort();
}
