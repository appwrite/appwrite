<?php

namespace Appwrite\Distribution\Release;

use Appwrite\Distribution\Submission\Status as SubmissionStatus;
use Utopia\Database\Document;
use Utopia\Distribution\Release;

enum Status: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Published = 'published';
    case Attention = 'attention';

    private const array ATTENTION = [
        SubmissionStatus::Rejected->value,
        SubmissionStatus::Failed->value,
    ];

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * @param array<Document> $submissions every submission of the release
     */
    public static function derive(array $submissions): self
    {
        if ($submissions === []) {
            return self::Draft;
        }

        $targets = [];

        foreach ($submissions as $submission) {
            $status = (string) $submission->getAttribute('status', '');

            if (\in_array($status, self::ATTENTION, true)) {
                return self::Attention;
            }

            $target = (string) $submission->getAttribute('targetInternalId', '');
            $live = $status === SubmissionStatus::Published->value
                && $submission->getAttribute('track') === Release::TRACK_PRODUCTION;

            $targets[$target] = ($targets[$target] ?? false) || $live;
        }

        return \in_array(false, $targets, true) ? self::Active : self::Published;
    }
}
