import { Envelope } from './messages.js';
/**
 * Parses JSON into an Envelope object. The difference from JSON.parse
 * is that the resulting objects will have default values (defined in the JSON Schema)
 * for properties that are absent from the JSON.
 */
export declare function parseEnvelope(json: string): Envelope;
//# sourceMappingURL=parseEnvelope.d.ts.map