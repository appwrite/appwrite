import { AttributeType } from '../models/AttributeType';

export function serializeAttributeValue(type: AttributeType, value: unknown): unknown {
    if (type === AttributeType.JSON) {
        if (value == null) return null;
        if (typeof value === 'object') return value;
        if (typeof value === 'string') {
            try {
                const parsed = JSON.parse(value);
                if (typeof parsed === 'object' && parsed !== null) return parsed;
                throw new TypeError('JSON string does not represent an object');
            } catch {
                throw new TypeError('Invalid JSON string');
            }
        }
        throw new TypeError('Value for JSON attribute must be object or JSON string');
    }
    return value;
}

export function deserializeAttributeValue(type: AttributeType, value: unknown): unknown {
    if (type === AttributeType.JSON) {
        if (value == null) return null;
        if (typeof value === 'object') return value;
        if (typeof value === 'string') {
            try {
                return JSON.parse(value);
            } catch {
                return null;
            }
        }
        return null;
    }
    return value;
}