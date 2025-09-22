import { Request, Response } from 'express';
import { AttributeModel } from '../models/Attribute'; // Use the concrete model
import { serializeAttributeValue, deserializeAttributeValue } from '../utils/attributeSerializer';
import { AttributeType } from '../models/AttributeType'; // adjust path if needed

export const createAttribute = async (req: Request, res: Response) => {
    try {
        const { name, type, value } = req.body;
        const doc: any = { name, type };
        if (type === AttributeType.JSON) {
            doc.value_json = serializeAttributeValue(type, value); // expects object
        } else {
            doc.value = serializeAttributeValue(type, value);
        }

        const newAttribute = new AttributeModel(doc);

        await newAttribute.save();

        // Return deserialized value, hide value_json
        const returned = newAttribute.toObject();
        const outValue = type === AttributeType.JSON
            ? deserializeAttributeValue(type, returned.value_json)
            : deserializeAttributeValue(type, returned.value);
        delete returned.value_json;
        returned.value = outValue;
        res.status(201).json(returned);
    } catch (error) {
        res.status(500).json({ message: 'Internal server error' });
        // Optionally log error
    }
};

export const getAttribute = async (req: Request, res: Response) => {
    try {
        const attribute = await AttributeModel.findById(req.params.id);
        if (!attribute) return res.status(404).send('Attribute not found');

        // Use value_json for JSON type, value otherwise
        const raw = attribute.type === AttributeType.JSON ? attribute.value_json : attribute.value;
        const deserializedValue = deserializeAttributeValue(attribute.type, raw);

        const out = attribute.toObject();
        delete out.value_json;
        out.value = deserializedValue;

        res.status(200).json(out);
    } catch (error) {
        res.status(500).json({ message: 'Internal server error' });
        // Optionally log error
    }
};

// Update attribute handler with JSON/non-JSON parity
export const updateAttribute = async (req: Request, res: Response) => {
    try {
        const { name, type, value } = req.body;
        const update: any = {};
        if (name !== undefined) update.name = name;
        if (type !== undefined) update.type = type;
        if (value !== undefined) {
            if (type === AttributeType.JSON) {
                update.value_json = serializeAttributeValue(type, value);
                update.value = undefined;
            } else {
                update.value = serializeAttributeValue(type, value);
                update.value_json = undefined;
            }
        }
        const attribute = await AttributeModel.findByIdAndUpdate(req.params.id, update, { new: true });
        if (!attribute) return res.status(404).send('Attribute not found');

        const raw = attribute.type === AttributeType.JSON ? attribute.value_json : attribute.value;
        const deserializedValue = deserializeAttributeValue(attribute.type, raw);

        const out = attribute.toObject();
        delete out.value_json;
        out.value = deserializedValue;

        res.status(200).json(out);
    } catch (error) {
        res.status(500).json({ message: 'Internal server error' });
        // Optionally log error
    }
};

// Delete attribute handler
export const deleteAttribute = async (req: Request, res: Response) => {
    try {
        const attribute = await AttributeModel.findByIdAndDelete(req.params.id);
        if (!attribute) return res.status(404).send('Attribute not found');
        res.status(204).send();
    } catch (error) {
        res.status(500).json({ message: 'Internal server error' });
        // Optionally log error
    }
};