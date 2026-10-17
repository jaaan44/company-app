@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Tasks</h1>

    @if ($mayAdd)
        <x-portal.fab :href="route('portal.tasks.new')" label="New task" />
    @endif

    @if ($mayViewAll)
        <div role="group" aria-label="Whose tasks" class="mb-4 mr-2 inline-flex rounded-full border border-line bg-raised p-1">
            @foreach (['mine' => 'Mine', 'all' => 'All'] as $value => $label)
                <button type="button" wire:click="showScope('{{ $value }}')" aria-pressed="{{ $currentScope === $value ? 'true' : 'false' }}"
                        class="inline-flex min-h-11 items-center rounded-full px-4 {{ $currentScope === $value ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @if ($currentScope === 'all')
        <x-portal.search id="task-search" label="Find a task" placeholder="Search by title" />
    @else
    <div role="group" aria-label="Which tasks" class="mb-4 inline-flex rounded-full border border-line bg-raised p-1">
        @foreach (['open' => 'Open', 'closed' => 'Done'] as $value => $label)
            <button type="button" wire:click="showState('{{ $value }}')" aria-pressed="{{ $currentState === $value ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center rounded-full px-4 {{ $currentState === $value ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">{{ $label }}</button>
        @endforeach
    </div>
    @endif

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($tasks) === 0)
        <x-portal.notice>{{ $currentScope === 'all' ? ($search === '' ? 'No tasks yet.' : 'No task matches "'.trim($search).'".') : ($currentState === 'open' ? 'No open tasks assigned to you.' : 'No completed tasks yet.') }}</x-portal.notice>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($tasks as $task)
                @php($due = Format::taskDueLabel($task, $companyDate))
                <li wire:key="task-{{ $task['public_id'] }}">
                    <a href="{{ route('portal.task', $task['public_id']) }}" class="chevron block min-h-11 px-5 py-3 hover:bg-hover">
                        <span class="block font-medium">{{ $task['title'] }}</span>
                        @if ($task['project']['name'] ?? null)
                            <span class="block text-sm text-muted">{{ $task['project']['name'] }}</span>
                        @endif
                        @if ($currentScope === 'all')
                            <span class="block text-sm text-muted">{{ ($task['assignee']['display_name'] ?? null) ? 'Assigned to '.$task['assignee']['display_name'] : 'Not assigned' }}</span>
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
