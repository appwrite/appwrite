<?php

namespace Appwrite\Distribution\Submission;

enum Status: string
{
    case Waiting = 'waiting';
    case Validating = 'validating';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Pending = 'pending';
    case Reviewing = 'reviewing';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';
    case Halted = 'halted';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function canTransition(self $to): bool
    {
        return \in_array($to, $this->next(), true);
    }

    /**
     * @return list<self>
     */
    private function next(): array
    {
        return match ($this) {
            self::Waiting => [self::Validating, self::Uploading, self::Processing, self::Pending, self::Reviewing, self::Approved, self::Published, self::Failed, self::Canceled],
            self::Validating => [self::Uploading, self::Processing, self::Pending, self::Reviewing, self::Approved, self::Published, self::Failed, self::Canceled],
            self::Uploading => [self::Processing, self::Pending, self::Reviewing, self::Approved, self::Published, self::Failed, self::Canceled],
            self::Processing => [self::Pending, self::Reviewing, self::Approved, self::Published, self::Rejected, self::Failed, self::Canceled],
            self::Pending => [self::Reviewing, self::Approved, self::Published, self::Rejected, self::Failed, self::Canceled],
            self::Reviewing => [self::Approved, self::Published, self::Rejected, self::Failed, self::Canceled],
            self::Approved => [self::Published, self::Failed, self::Canceled],
            self::Published => [self::Halted, self::Failed],
            self::Halted => [self::Published, self::Failed],
            self::Rejected, self::Failed, self::Canceled => [],
        };
    }
}
