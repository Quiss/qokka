<?php

namespace App;

enum ModerationActionType: string
{
    case ApproveCandidate = 'approve_candidate';
    case RejectCandidate = 'reject_candidate';
    case ApprovePost = 'approve_post';
    case RejectPost = 'reject_post';
    case OverrideAiBlock = 'override_ai_block';
    case EditPost = 'edit_post';
    case ReschedulePost = 'reschedule_post';
    case RewritePost = 'rewrite_post';
    case RestorePostRevision = 'restore_post_revision';
    case ReplacePostFromReserve = 'replace_post_from_reserve';
    case PlaceCandidateInPlan = 'place_candidate_in_plan';
}
