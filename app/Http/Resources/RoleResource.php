<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'configuration' => $this->configuration ?? [
                'num_job_vacancies' => 0,
                'num_professional_vacancies' => 0,
                'num_jr_vacancies' => 0,
                'num_visualizations' => 0,
                'unlimited_jobs' => false,
                'unlimited_professionals' => false,
                'unlimited_jr' => false,
                'unlimited_visualizations' => false,
            ],
            'permissions' => $this->permissions,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
