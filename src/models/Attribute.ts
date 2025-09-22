import { AttributeType } from './AttributeType';

export interface Attribute {
    // ...existing code...
    type: AttributeType;
    // ...existing code...
    // Optionally, add a schema for JSON type
    jsonSchema?: object;
}