import { AttributeType } from '../models/AttributeType';

export function serializeAttributeValue(type: AttributeType, value: any): any {
    if (type === AttributeType.JSON) {
        return JSON.stringify(value);
    }
    return value;
}

export function deserializeAttributeValue(type: AttributeType, value: any): any {
    if (type === AttributeType.JSON) {
        try {
            return JSON.parse(value);
        } catch {
            return null;
        }
    }
    return value;
}