<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;

class BulkApproveAction
{
    public function __construct(private ApproveRefrendAction $approveAction) {}

    public function execute(array $ids, int $userId): array
    {
        $approved = 0;
        $skipped  = 0;
        $errors   = [];

        $refrends = ScholarshipRefrend::whereIn('id', $ids)->get();

        foreach ($refrends as $refrend) {
            try {
                $this->approveAction->execute($refrend, $userId);
                $approved++;
            } catch (\DomainException $e) {
                $skipped++;
                $errors[] = ['id' => $refrend->id, 'reason' => $e->getMessage()];
            }
        }

        return compact('approved', 'skipped', 'errors');
    }
}
