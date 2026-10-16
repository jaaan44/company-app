<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New staff record / Edit staff record (Phase 36A): every field of POST
 * and PATCH /api/v1/staff except the sign-in account, which the staff
 * record page links. The manager is found by searching the directory.
 * The API checks every rule (unique employee number and email, a team in
 * the chosen department, dates in order, separation date when separated).
 */
class StaffForm extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    /** API field => [property, label, max length, input type]. */
    public const FIELDS = [
        'employee_number' => ['employeeNumber', 'Employee number', 50, 'text'],
        'first_name' => ['firstName', 'First name', 255, 'text'],
        'last_name' => ['lastName', 'Last name', 255, 'text'],
        'preferred_name' => ['preferredName', 'Preferred name', 255, 'text'],
        'company_email' => ['companyEmail', 'Company email', 255, 'email'],
        'company_phone' => ['companyPhone', 'Company phone', 50, 'tel'],
    ];

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public bool $notFound = false;

    public string $employeeNumber = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $preferredName = '';

    public string $companyEmail = '';

    public string $companyPhone = '';

    public string $status = 'active';

    public string $hireDate = '';

    public string $separationDate = '';

    public string $departmentId = '';

    public string $teamId = '';

    public string $positionId = '';

    public string $managerId = '';

    public string $managerName = '';

    public string $managerSearch = '';

    public function mount(?string $publicId = null): void
    {
        if ($publicId === null) {
            return;
        }

        $this->publicId = $publicId;
        try {
            $s = $this->api()->get('/staff/'.rawurlencode($publicId))['data'];
            foreach (self::FIELDS as $field => [$property]) {
                $this->{$property} = (string) ($s[$field] ?? '');
            }
            $this->status = (string) $s['status'];
            $this->hireDate = (string) ($s['hire_date'] ?? '');
            $this->separationDate = (string) ($s['separation_date'] ?? '');
            $this->departmentId = (string) ($s['department']['public_id'] ?? '');
            $this->teamId = (string) ($s['team']['public_id'] ?? '');
            $this->positionId = (string) ($s['position']['public_id'] ?? '');
            $this->managerId = (string) ($s['manager']['public_id'] ?? '');
            $this->managerName = (string) ($s['manager']['display_name'] ?? '');
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the staff record.', "You don't have access to staff records.");
            }
        }
    }

    public function chooseManager(string $publicId, string $name): void
    {
        if ($publicId === $this->publicId) {
            return;
        }
        $this->managerId = $publicId;
        $this->managerName = $name;
        $this->managerSearch = '';
    }

    public function clearManager(): void
    {
        $this->managerId = '';
        $this->managerName = '';
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();

        $body = [];
        foreach (self::FIELDS as $field => [$property, , $max]) {
            $value = trim((string) $this->{$property});
            if (mb_strlen($value) > $max) {
                $this->addError($property, "Use at most {$max} characters.");
            }
            $body[$field] = $value === '' ? null : $value;
        }
        foreach (['employee_number' => 'Give the employee number.', 'first_name' => 'Give the first name.', 'last_name' => 'Give the last name.'] as $field => $message) {
            if ($body[$field] === null) {
                $this->addError(self::FIELDS[$field][0], $message);
            }
        }
        if ($body['company_email'] !== null && filter_var($body['company_email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->addError('companyEmail', 'Enter a valid email address.');
        }
        $status = in_array($this->status, ['active', 'inactive', 'separated'], true) ? $this->status : 'active';
        if ($status === 'separated' && $this->separationDate === '') {
            $this->addError('separationDate', 'Give the separation date.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $body += [
            'status' => $status,
            'hire_date' => $this->hireDate === '' ? null : $this->hireDate,
            'separation_date' => $status === 'separated' && $this->separationDate !== '' ? $this->separationDate : null,
            'department_id' => $this->departmentId === '' ? null : $this->departmentId,
            'team_id' => $this->teamId === '' ? null : $this->teamId,
            'position_id' => $this->positionId === '' ? null : $this->positionId,
            'manager_id' => $this->managerId === '' ? null : $this->managerId,
        ];

        try {
            if ($this->publicId === null) {
                $s = $this->api()->send('POST', '/staff', json: $body)['data'];
                session()->flash('status', 'Staff record added.');
            } else {
                $s = $this->api()->send('PATCH', '/staff/'.rawurlencode($this->publicId), json: $body)['data'];
                session()->flash('status', 'Changes saved.');
            }
            $this->redirect(route('admin.staff.show', $s['public_id']), navigate: true);
        } catch (PortalApiException $e) {
            $map = [
                'status' => 'status', 'hire_date' => 'hireDate', 'separation_date' => 'separationDate',
                'department_id' => 'departmentId', 'team_id' => 'teamId', 'position_id' => 'positionId', 'manager_id' => 'managerSearch',
            ];
            foreach (self::FIELDS as $field => [$property]) {
                $map[$field] = $property;
            }
            $this->applyWriteFailure($e, $map, "Couldn't save the staff record. Please try again.");
        }
    }

    public function render(): View
    {
        $ready = ! $this->notFound && $this->errorMessage === null;
        $lists = ['departments' => [], 'teams' => [], 'positions' => []];
        $people = [];
        $searchFailed = false;

        if ($ready) {
            try {
                foreach (array_keys($lists) as $list) {
                    $lists[$list] = $this->api()->get("/{$list}", ['per_page' => 100])['data'] ?? [];
                }
            } catch (PortalApiException $e) {
                $this->failed($e, 'Something went wrong loading departments, teams and positions.', "You don't have access to the company structure.");
            }
        }
        if ($ready && mb_strlen(trim($this->managerSearch)) >= 2) {
            try {
                $people = array_values(array_filter(
                    $this->api()->get('/staff', ['q' => trim($this->managerSearch), 'status' => 'active', 'per_page' => 8])['data'] ?? [],
                    fn (array $person) => $person['public_id'] !== $this->publicId,
                ));
            } catch (PortalApiException) {
                $searchFailed = true;
            }
        }

        return view('livewire.admin.staff-form', $lists + ['people' => $people, 'searchFailed' => $searchFailed]);
    }
}
