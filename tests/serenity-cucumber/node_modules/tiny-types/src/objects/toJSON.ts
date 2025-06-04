/* eslint-disable unicorn/filename-case */
import { JSONObject, JSONValue } from '../types';
import { isRecord } from './isRecord';

/**
 * Serialises the object to a JSON representation.
 *
 * @param value
 */
export function toJSON(value: any): JSONValue | undefined { // eslint-disable-line @typescript-eslint/explicit-module-boundary-types
    switch (true) {
        case value && !! value.toJSON:
            return value.toJSON();
        case value && Array.isArray(value):
            return value.map(v => {
                return v === undefined
                    ? null
                    : toJSON(v) as JSONValue;
            });
        case value && value instanceof Map:
            return mapToJSON(value);
        case value && value instanceof Set:
            return toJSON(Array.from(value));
        case value && isRecord(value):
            return recordToJSON(value);
        case value && value instanceof Error:
            return errorToJSON(value);
        case isSerialisablePrimitive(value):
            return value;
        default:
            return JSON.stringify(value);
    }
}

function mapToJSON(map: Map<any, any>): JSONObject {
    const serialised = Array.from(map, ([key, value]) => [ toJSON(key), toJSON(value) ]);

    return Object.fromEntries(serialised);
}

function recordToJSON(value: Record<any, any>): JSONObject {
    const serialised = Object.entries(value)
        .map(([ k, v ]) => [ toJSON(k), toJSON(v) ]);

    return Object.fromEntries(serialised);
}

function errorToJSON(value: Error): JSONObject {
    return Object.getOwnPropertyNames(value)
        .reduce((serialised, key) => {
            serialised[key] = toJSON(value[key])
            return serialised;
        }, { }) as JSONObject;
}

function isSerialisableNumber(value: unknown): value is number {
    return typeof value === 'number'
        && ! Number.isNaN(value)
        && value !== Number.NEGATIVE_INFINITY
        && value !== Number.POSITIVE_INFINITY;
}

function isSerialisablePrimitive(value: unknown): value is string | boolean | number | null | undefined {
    if (['string', 'boolean'].includes(typeof value)) {
        return true;
    }

    if (value === null || value === undefined) {
        return true;
    }

    return isSerialisableNumber(value);
}
