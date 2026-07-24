<?php

namespace App\Filament\Resources\PlannedPosts\Pages;

use App\DeliveryStatus;
use App\Filament\Resources\PlannedPosts\PlannedPostResource;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\ModerationActionType;
use App\PlannedPostStatus;
use Filament\Resources\Pages\EditRecord;

class EditPlannedPost extends EditRecord
{
    protected static string $resource = PlannedPostResource::class;

    private bool $approvalWasRevoked = false;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var PlannedPost $record */
        $record = $this->getRecord();
        $hasChanged = ($data['text'] ?? null) !== $record->text
            || (string) ($data['scheduled_at'] ?? '') !== (string) $record->scheduled_at;

        if ($hasChanged && $record->status === PlannedPostStatus::Approved) {
            $data['status'] = PlannedPostStatus::FinalReview;
            $data['approved_by'] = null;
            $data['approved_at'] = null;
            $this->approvalWasRevoked = true;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->approvalWasRevoked) {
            return;
        }

        /** @var PlannedPost $record */
        $record = $this->getRecord();
        $record->deliveries()->update(['status' => DeliveryStatus::Cancelled]);
        ModerationAction::create([
            'user_id' => auth()->id(),
            'subject_type' => $record::class,
            'subject_id' => $record->id,
            'action' => ModerationActionType::EditPost,
            'reason' => 'Approval revoked after editing text or schedule.',
        ]);
    }
}
