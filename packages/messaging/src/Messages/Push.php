<?php

namespace Utopia\Messaging\Messages;

use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Message;
use Utopia\Messaging\Priority;

class Push implements Message
{
    private ?string $origin = null;

    /**
     * @param  array<string>  $to The recipients of the push notification.
     * @param  string|null  $title The title of the push notification.
     * @param  string|null  $body The body of the push notification.
     * @param  array<string, mixed>|null  $data This parameter specifies the custom key-value pairs of the message's payload. For example, with data:{"score":"3x1"}:<br><br>On Apple platforms, if the message is sent via APNs, it represents the custom data fields. If it is sent via FCM, it would be represented as key value dictionary in AppDelegate application:didReceiveRemoteNotification:.<br><br>On Android, this would result in an intent extra named score with the string value 3x1.<br><br>The key should not be a reserved word ("from", "message_type", or any word starting with "google" or "gcm"). Do not use any of the words defined in this table (such as collapse_key).<br><br>Values in string types are recommended. You have to convert values in objects or other non-string data types (e.g., integers or booleans) to string.
     * @param  string|null  $action The action associated with a user click on the notification.<br><br>On Android, this is the activity to launch.<br><br>On iOS, this is the category to launch.
     * @param  string|null  $sound The sound to play when the device receives the notification.<br><br>On Android, sound files must reside in /res/raw/.<br><br>On iOS, sounds files must reside in the main bundle of the client app or in the Library/Sounds folder of the app's data container.
     * @param  string|null  $image The image to display when the device receives the notification.<br><br>On Android, this image is displayed as a badge on the notification.<br><br>On iOS, this image is displayed next to the body of the notification. If present, the notification's type is set to media.
     * @param  string|null  $icon <b>Android only</b>. The icon of the push notification. Sets the notification icon to myicon for drawable resource myicon. If you don't send this key in the request, FCM displays the launcher icon specified in your app manifest.
     * @param  string|null  $color <b>Android only</b>. The icon color of the push notification, expressed in #rrggbb format.
     * @param  string|null  $tag <b>Android only</b>. Identifier used to replace existing notifications in the notification drawer.<br><br>If not specified, each request creates a new notification.<br><br>If specified and a notification with the same tag is already being shown, the new notification replaces the existing one in the notification drawer.
     * @param  int|null  $badge <b>iOS only</b>. The value of the badge on the home screen app icon. If not specified, the badge is not changed. If set to 0, the badge is removed.
     * @param  bool|null  $contentAvailable <b>iOS only</b>. When set to true, the notification is silent (no sounds or vibrations) and the content-available flag is set to 1. If not specified, the notification is not silent.
     * @param  bool|null  $critical <b>iOS only</b>. When set to true, if the app is granted the critical alert capability, the notification is displayed using Apple's critical alert option. If not specified, the notification is not displayed using Apple's critical alert option.
     * @param  Priority|null  $priority The priority of the message. Valid values are "normal" and "high". On iOS, these correspond to APNs priority 5 and 10.<br><br>By default, notification messages are sent with high priority, and data messages are sent with normal priority.
     */
    public function __construct(
        private readonly array $to,
        private readonly ?string $title = null,
        private readonly ?string $body = null,
        private readonly ?array $data = null,
        private readonly ?string $action = null,
        private readonly ?string $sound = null,
        private readonly ?string $image = null,
        private readonly ?string $icon = null,
        private readonly ?string $color = null,
        private readonly ?string $tag = null,
        private readonly ?int $badge = null,
        private readonly ?bool $contentAvailable = null,
        private readonly ?bool $critical = null,
        private readonly ?Priority $priority = null,
    ) {
        if (
            $title === null
            && $body === null
            && $data === null
        ) {
            throw new InvalidArgumentException(
                InvalidArgumentException::MESSAGE_EMPTY,
                'At least one of the following parameters must be set: title, body, data',
            );
        }
    }

    /**
     * @return array<string>
     */
    public function getTo(): array
    {
        return $this->to;
    }

    public function getFrom(): ?string
    {
        return null;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getSound(): ?string
    {
        return $this->sound;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function getBadge(): ?int
    {
        return $this->badge;
    }

    public function getContentAvailable(): ?bool
    {
        return $this->contentAvailable;
    }

    public function getCritical(): ?bool
    {
        return $this->critical;
    }

    public function getPriority(): ?Priority
    {
        return $this->priority;
    }

    public function setOrigin(?string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }
}
