import { significantFieldsOf } from './significantFields';

/**
 * @access private
 */
export function equal(v1: any, v2: any): boolean {  // eslint-disable-line @typescript-eslint/explicit-module-boundary-types
    switch (true) {
        case ! sameType(v1, v2):
            return false;
        case both(arePrimitives, v1, v2):
            return checkIdentityOf(v1, v2);
        case both(areObjects, v1, v2) && sameClass(v1, v2) && both(areDates, v1, v2):
            return checkTimestamps(v1, v2);
        case both(areObjects, v1, v2) && sameClass(v1, v2):
            return checkSignificantFieldsOf(v1, v2);
    }

    return false;
}

const areObjects     = (_: any) => new Object(_) === _;
const areDates       = (_: any) => _ instanceof Date;
const arePrimitives  = (_: any) => ! areObjects(_); // arrays are objects

function both(condition: (_: any) => boolean, v1: any, v2: any): boolean {
    return condition(v1) && condition(v2);
}

const sameType  = (v1: any, v2: any) => typeof v1 === typeof v2;
const sameClass = (v1: any, v2: any) => (v1.constructor && v2 instanceof v1.constructor) || (v2.constructor && v1 instanceof  v2.constructor);
const sameLength = (v1: { length: number }, v2: { length: number }) => v1.length === v2.length;

function checkIdentityOf(v1: any, v2: any) {
    return v1 === v2;
}

function checkTimestamps(v1: Date, v2: Date) {
    return v1.getTime() === v2.getTime();
}

function checkSignificantFieldsOf(o1: object, o2: object) {
    const
        fieldsOfObject1 = significantFieldsOf(o1),
        fieldsOfObject2 = significantFieldsOf(o2);

    if (! sameLength(fieldsOfObject1, fieldsOfObject2)) {
        return false;
    }

    return fieldsOfObject1.reduce((previousFieldsAreEqual: boolean, field: string) => {
        const currentFieldIsEqual = equal(o1[field], o2[field]);
        return previousFieldsAreEqual && currentFieldIsEqual;
    }, true);
}
