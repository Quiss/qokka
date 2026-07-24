<?php

namespace App;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case RetryScheduled = 'retry_scheduled';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
