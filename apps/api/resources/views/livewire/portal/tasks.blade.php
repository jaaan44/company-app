@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="mb-4 text-2xl font-semibold">Tasks</h1>

    <div role="group" aria-label="Which tasks" class="mb-4 inline-flex rounded-md border border-line bg-raised p-1">
        @foreach (['open' => 'Open', 'closed' => 'Done'] as $value => $label)
            <button type="button" wire:click="showState('{{ $value }}')" aria-pressed="{{ $currentState === $value ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center rounded px-4 {{ $currentState === $value ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($tasks) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">{{ $currentState === 'open' ? 'No open tasks assigned to you.' : 'No completed tasks yet.' }}</p>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($tasks as $task)
                @php($due = Format::taskDueLabel($task, $companyDate))
                <li wire:key="task-{{ $task['public_id'] }}">
                    <a href="{{ route('portal.task', $task['public_id']) }}" class="block min-h-11 px-5 py-3 hover:bg-hover">
                        <span class="block font-medium">{{ $task['title'] }}</span>
                        @if ($task['project']['name'] ?? null)
                            <span class="block text-sm text-muted">{{ $task['project']['name'] }}</span>
                        @endif
                        <span class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                            <x-portal.chip :tone="Format::tone($task['status'])">{{ Format::taskStatus($task['status']) }}</x-portal.chip>
                            @if ($due)
                                <span class="{{ Format::taskDueState($task, $companyDate) === 'overdue' ? 'font-semibold text-danger' : 'text-muted' }}">{{ $due }}</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
