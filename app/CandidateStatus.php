<?php

namespace App;

enum CandidateStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reserve = 'reserve';
    case Selected = 'selected';
}
