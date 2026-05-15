<?php

namespace App\Services;

use App\Models\StudentDocument;
use App\Models\User;
use App\Enums\DocumentType;
use App\Enums\DocumentStatus;

class DocumentValidationService
{
    private const REQUIRED_TYPES = [
        DocumentType::CALIFICACIONES_ORIGINALES,
        DocumentType::CONSTANCIA_ESTUDIOS,
        DocumentType::COMPROBANTE_PAGO,
    ];

    /**
     * Retorna los tipos de documento requeridos que faltan o no están aceptados para el periodo.
     *
     * @return DocumentType[]
     */
    public function getMissingDocumentTypes(User $user, int $year, int $month): array
    {
        $submitted = StudentDocument::where('user_id', $user->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereIn('status', [DocumentStatus::SUBMITTED->value, DocumentStatus::ACCEPTED->value])
            ->pluck('document_type')
            ->map(fn($v) => DocumentType::from($v))
            ->all();

        return array_values(
            array_filter(
                self::REQUIRED_TYPES,
                fn(DocumentType $required) => !in_array($required, $submitted, true)
            )
        );
    }

    /**
     * Retorna true si todos los documentos requeridos están aceptados para el periodo.
     */
    public function allRequiredAccepted(User $user, int $year, int $month): bool
    {
        $accepted = StudentDocument::where('user_id', $user->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', DocumentStatus::ACCEPTED->value)
            ->pluck('document_type')
            ->map(fn($v) => DocumentType::from($v))
            ->all();

        foreach (self::REQUIRED_TYPES as $required) {
            if (!in_array($required, $accepted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retorna los tipos de documento requeridos para refrendo.
     *
     * @return DocumentType[]
     */
    public function getRequiredTypes(): array
    {
        return self::REQUIRED_TYPES;
    }
}
