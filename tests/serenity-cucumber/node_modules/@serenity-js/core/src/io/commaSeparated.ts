/**
 * Produces a comma-separated list based on the list provided.
 *
 * @param list
 * @param mappingFunction
 * @param [acc='']
 *
 * @returns {string}
 */
export function commaSeparated(
    list: Array<string | { toString: () => string }>,
    mappingFunction = item => `${ item }`.trim(),
    acc = '',
): string {
    switch (list.length) {
        case 0:     return acc;
        case 1:     return commaSeparated(tail(list), mappingFunction, `${ acc }${ mappingFunction(head(list)) }`);
        case 2:     return commaSeparated(tail(list), mappingFunction, `${ acc }${ mappingFunction(head(list)) } and `);
        default:    return commaSeparated(tail(list), mappingFunction, `${ acc }${ mappingFunction(head(list)) }, `);
    }
}

/** @package */
function head<T>(list: T[]): T {
    return list[0];
}

/** @package */
function tail<T>(list: T[]): T[] {
    return list.slice(1);
}
