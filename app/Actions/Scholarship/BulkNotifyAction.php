<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;

class BulkNotifyAction
{
    public function __construct(private NotifyStudentAction $notifyAction) {}

    public function execute(array $ids, ?string $method, int $userId): array
    {
        $notified = 0;
        $skipped  = 0;
        $errors   = [];

        $refrends = ScholarshipRefrend::whereIn('id', $ids)->get();

        foreach ($refrends as $refrend) {
            try {
                $this->notifyAction->execute($refrend, $method, $userId);
                $notified++;
            } catch (\DomainException $e) {
                $skipped++;
                $errors[] = ['id' => $refrend->id, 'reason' => $e->getMessage()];
            }
        }

        return compact('notified', 'skipped', 'errors');
    }
}
