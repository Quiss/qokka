<?php

namespace App;

enum AiOperation: string
{
    case RankAndCluster = 'rank_and_cluster';
    case ValidateClusters = 'validate_clusters';
    case Rewrite = 'rewrite';
    case ReviewPlan = 'review_plan';
    case AnalyzeImage = 'analyze_image';
    case GenerateTone = 'generate_tone';
    case GenerateAdvertisingPost = 'generate_advertising_post';
}
