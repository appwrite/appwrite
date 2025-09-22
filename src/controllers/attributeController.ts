import { Request, Response } from 'express';
import { Attribute } from '../models/Attribute';
import { serializeAttributeValue, deserializeAttributeValue } from '../utils/attributeSerializer';

export const createAttribute = async (req: Request, res: Response) => {
    try {
        const { name, type, value } = req.body;
        // When saving:
        const serializedValue = serializeAttributeValue(type, value);

        const newAttribute = new Attribute({
            name,
            type,
            value: serializedValue,
        });

        await newAttribute.save();
        res.status(201).json(newAttribute);
    } catch (error) {
        res.status(500).json({ message: error.message });
    }
};

export const getAttribute = async (req: Request, res: Response) => {
    try {
        const attribute = await Attribute.findById(req.params.id);
        if (!attribute) return res.status(404).send('Attribute not found');

        // When reading:
        const deserializedValue = deserializeAttributeValue(attribute.type, attribute.value);

        res.status(200).json({
            ...attribute.toObject(),
            value: deserializedValue,
        });
    } catch (error) {
        res.status(500).json({ message: error.message });
    }
};

// ...existing code for update and delete operations, applying serialization/deserialization as above...