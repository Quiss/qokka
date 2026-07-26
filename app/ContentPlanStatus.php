<?php

namespace App;

enum ContentPlanStatus: string
{
    case Generating = 'generating';
    case CandidateReview = 'candidate_review';
    case Rewriting = 'rewriting';
    case NeedsCandidates = 'needs_candidates';
    case FinalReview = 'final_review';
    case Ready = 'ready';
    case Active = 'active';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
