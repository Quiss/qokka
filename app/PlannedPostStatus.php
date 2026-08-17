<?php

namespace App;

enum PlannedPostStatus: string
{
    case Rewriting = 'rewriting';
    case FinalReview = 'final_review';
    case Blocked = 'blocked';
    case Approved = 'approved';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case NeedsReschedule = 'needs_reschedule';

    /** @return list<self> */
    public static function reviewableCases(): array
    {
        return [
            self::FinalReview,
            self::Blocked,
            self::NeedsReschedule,
        ];
    }
}
