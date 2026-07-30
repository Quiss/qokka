<?php

namespace App;

enum TelegramOwnerCommandType: string
{
    case VerifySource = 'verify_source';
    case SyncSourceHistory = 'sync_source_history';
    case DownloadMedia = 'download_media';
    case DownloadMediaPreview = 'download_media_preview';
    case ScanDeletedParticipants = 'scan_deleted_participants';
    case RemoveDeletedParticipants = 'remove_deleted_participants';
}
