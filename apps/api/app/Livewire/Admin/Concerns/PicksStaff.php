<?php

namespace App\Livewire\Admin\Concerns;

use App\Services\Portal\PortalApiException;

/**
 * One staff member chosen by searching the directory (Phase 36B–36D): a
 * search box, a short list of matches, the chosen name with Remove.
 * Used by the Backoffice forms that act for or on a person.
 */
trait PicksStaff
{
    public string $staffId = '';

    public string $staffName = '';

    public string $staffSearch = '';

    public function chooseStaff(string $publicId, string $name): void
    {
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId)) {
            return;
        }
        $this->staffId = $publicId;
        $this->staffName = $name;
        $this->staffSearch = '';
        $this->staffChosen();
    }

    public function clearStaff(): void
    {
        $this->staffId = '';
        $this->staffName = '';
        $this->staffChosen();
    }

    /** Called after the choice changes. */
    protected function staffChosen(): void {}

    /**
     * @return list<array<string, mixed>>|null null when the search failed
     */
    protected function staffMatches(): ?array
    {
        if (mb_strlen(trim($this->staffSearch)) < 2) {
            return [];
        }
        try {
            return $this->api()->get('/staff', ['q' => trim($this->staffSearch), 'status' => 'active', 'per_page' => 8])['data'] ?? [];
        } catch (PortalApiException) {
            return null;
        }
    }
}
