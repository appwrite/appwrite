/**
 * @access private
 */
export function isObject(value: unknown): value is object {
    return value !== null
        && value !== undefined
        && typeof value === 'object'
        && Array.isArray(value) === false
        && Object.prototype.toString.call(value) === '[object Object]';
}
