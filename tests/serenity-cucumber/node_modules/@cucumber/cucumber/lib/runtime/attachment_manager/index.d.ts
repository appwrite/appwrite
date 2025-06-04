/// <reference types="node" />
/// <reference types="node" />
import { Readable } from 'stream';
import * as messages from '@cucumber/messages';
export interface IAttachmentMedia {
    encoding: messages.AttachmentContentEncoding;
    contentType: string;
}
export interface IAttachment {
    data: string;
    media: IAttachmentMedia;
    fileName?: string;
}
export type IAttachFunction = (attachment: IAttachment) => void;
export interface ICreateAttachmentOptions {
    mediaType: string;
    fileName?: string;
}
export type ICreateStringAttachment = (data: string, mediaTypeOrOptions?: string | ICreateAttachmentOptions) => void;
export type ICreateBufferAttachment = (data: Buffer, mediaTypeOrOptions: string | ICreateAttachmentOptions) => void;
export type ICreateStreamAttachment = (data: Readable, mediaTypeOrOptions: string | ICreateAttachmentOptions) => Promise<void>;
export type ICreateStreamAttachmentWithCallback = (data: Readable, mediaTypeOrOptions: string | ICreateAttachmentOptions, callback: () => void) => void;
export type ICreateAttachment = ICreateStringAttachment & ICreateBufferAttachment & ICreateStreamAttachment & ICreateStreamAttachmentWithCallback;
export type ICreateLog = (text: string) => void;
export default class AttachmentManager {
    private readonly onAttachment;
    constructor(onAttachment: IAttachFunction);
    log(text: string): void | Promise<void>;
    create(data: Buffer | Readable | string, mediaTypeOrOptions?: string | ICreateAttachmentOptions, callback?: () => void): void | Promise<void>;
    createBufferAttachment(data: Buffer, mediaType: string, fileName?: string): void;
    createStreamAttachment(data: Readable, mediaType: string, fileName?: string, callback?: () => void): void | Promise<void>;
    createStringAttachment(data: string, media: IAttachmentMedia, fileName?: string): void;
}
