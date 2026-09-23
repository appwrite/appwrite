<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\SMS\WhatsApp;

enum MetadataParameter: string
{
    /**
     * Template language code (for example `en_US` or `pt_BR`) overriding the adapter default for one message.
     * The authentication template must already exist in that language.
     */
    case LANGUAGE = 'language';

    /**
     * Name of an approved authentication template overriding the adapter default for one message,
     * so one adapter can serve flows that use different templates.
     */
    case TEMPLATE = 'template';

    /**
     * Opaque string Meta echoes back as `biz_opaque_callback_data` in every status webhook for the
     * message, so a host can correlate delivery reports with its own records.
     */
    case CALLBACK_DATA = 'callbackData';
}
