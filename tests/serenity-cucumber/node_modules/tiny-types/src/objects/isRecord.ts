import { isObject } from './isObject';

export function isRecord(value: unknown): value is Record<any, any> {
    if (! isObject(value)) {
        return false;
    }

    // It has modified constructor
    if (value.constructor === undefined) {
        return true;
    }

    // It has modified prototype
    if (! isObject(value.constructor.prototype)) {
        return false;
    }

    // If constructor does not have an Object-specific method
    if (! Object.prototype.hasOwnProperty.call(value.constructor.prototype, 'isPrototypeOf')) {
        return false;
    }

    // Most likely a plain Object
    return true;
}
