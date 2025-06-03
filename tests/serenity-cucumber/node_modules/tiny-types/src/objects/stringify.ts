import { significantFieldsOf } from './significantFields';

/**
 * @access private
 */
export function stringify(v: unknown): string {
    if (Array.isArray(v)) {
        return `${v.constructor.name}(${ v.map(i => stringify(i)).join(', ') })`;
    }

    if (v instanceof Date) {
        return v.toISOString();
    }

    if (isObject(v)) {
        const fields = significantFieldsOf(v)
            .map(field => ({ field, value: stringify(v[field]) }))
            .reduce((acc: string[], current: { field: string, value: string }) => {
                return acc.concat(`${ current.field }=${ current.value }`);
            }, []);

        return `${ v.constructor.name }(${ fields.join(', ') })`;
    }

    return String(v);
}

function isObject(value: any): value is object {
    return new Object(value) === value;
}
