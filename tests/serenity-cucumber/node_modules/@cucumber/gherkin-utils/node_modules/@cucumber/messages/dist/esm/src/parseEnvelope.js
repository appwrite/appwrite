import { Envelope } from './messages.js';
import { plainToClass } from 'class-transformer';
/**
 * Parses JSON into an Envelope object. The difference from JSON.parse
 * is that the resulting objects will have default values (defined in the JSON Schema)
 * for properties that are absent from the JSON.
 */
export function parseEnvelope(json) {
    const plain = JSON.parse(json);
    return plainToClass(Envelope, plain);
}
//# sourceMappingURL=parseEnvelope.js.map