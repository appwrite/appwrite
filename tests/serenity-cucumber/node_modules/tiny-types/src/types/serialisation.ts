import { JSONValue } from './json';

export interface Serialisable<S extends JSONValue = JSONValue> {
    toJSON(): S | undefined;
}
