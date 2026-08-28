<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Video extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly VideoAction $action,
        public readonly Document $video,
        public readonly ?Document $profile = null,
        public readonly ?Document $subtitle = null,
        public readonly ?Document $rendition = null,
        public readonly string $output = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            // The worker container resolves `project` and `dbForProject` from this key,
            // so it has to be present on every videos message.
            'project' => [
                '$id' => $this->project->getId(),
                '$sequence' => $this->project->getSequence(),
                'database' => $this->project->getAttribute('database', ''),
            ],
            'action' => $this->action->value,
            'video' => $this->video->getArrayCopy(),
            'profile' => $this->profile?->getArrayCopy(),
            'subtitle' => $this->subtitle?->getArrayCopy(),
            'rendition' => $this->rendition?->getArrayCopy(),
            'output' => $this->output,
        ];
    }

    public static function fromArray(array $data): static
    {
        if (empty($data['action'])) {
            throw new \InvalidArgumentException('Missing action in video message payload');
        }

        return new self(
            project: new Document($data['project'] ?? []),
            action: VideoAction::from($data['action']),
            video: new Document($data['video'] ?? []),
            profile: !empty($data['profile']) ? new Document($data['profile']) : null,
            subtitle: !empty($data['subtitle']) ? new Document($data['subtitle']) : null,
            rendition: !empty($data['rendition']) ? new Document($data['rendition']) : null,
            output: $data['output'] ?? '',
        );
    }
}
