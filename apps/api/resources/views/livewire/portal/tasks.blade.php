@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Tasks</h1>

    @if ($mayAdd)
        <x-portal.fab :href="route('portal.tasks.new')" label="New task" />
    @endif

    @if ($mayViewAll)
        <div role="group" aria-label="Whose tasks" class="mb-4 mr-2 inline-flex flex-wrap gap-2">
            @foreach (['mine' => 'Mine', 'all' => 'All'] as $value => $label)
                <button type="button" wire:click="showScope('{{ $value }}')" aria-pressed="{{ $currentScope === $value ? 'true' : 'false' }}"
                        class="filter-chip">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @if ($currentScope === 'all')
        <x-portal.search id="task-search" label="Find a task" placeholder="Search by title" />
    @else
    <div role="group" aria-label="Which tasks" class="mb-4 inline-flex flex-wrap gap-2">
        @foreach (['open' => 'Open', 'closed' => 'Done'] as $value => $label)
            <button type="button" wire:click="showState('{{ $value }}')" aria-pressed="{{ $currentState === $value ? 'true' : 'false' }}"
                    class="filter-chip">{{ $label }}</button>
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
        <ul class="divide-y divide-line overflow-hidden card">
            @foreach ($tasks as $task)
                @php($due = Format::taskDueLabel($task, $companyDate))
                <li wire:key="task-{{ $task['public_id'] }}">
                    <a href="{{ route('portal.task', $task['public_id']) }}" class="chevron flex min-h-16 items-start gap-3 px-4 py-3 hover:bg-hover">
                        <x-portal.icon-tile icon="tasks" :tone="Format::tileTone(Format::tone($task['status']))" />
                        <span class="block min-w-0 flex-1">
                            <span class="block font-semibold [overflow-wrap:anywhere]">{{ $task['title'] }}</span>
                            @if ($task['project']['name'] ?? null)
                                <span class="block text-sm text-muted">{{ $task['project']['name'] }}</span>
                            @endif
                            @if ($currentScope === 'all')
                                <span class="block text-sm text-muted">{{ ($task['assignee']['display_name'] ?? null) ? 'Assigned to '.$task['assignee']['display_name'] : 'Not assigned' }}</span>
                            @endif
                            <span class="mt-1.5 flex flex-wrap items-center gap-2 text-sm">
                                <x-portal.chip :tone="Format::tone($task['status'])">{{ Format::taskStatus($task['status']) }}</x-portal.chip>
                                @if ($due)
                                    {{-- 37D: the due date as a pill, red when it's today or overdue (the words say which). --}}
                                    <span class="rounded-full px-2.5 py-0.5 font-medium {{ in_array(Format::taskDueState($task, $companyDate), ['overdue', 'due_today'], true) ? 'bg-danger-soft text-danger' : 'bg-hover text-muted' }}">{{ $due }}</span>
                                @endif
                                @if (in_array($task['priority'] ?? null, ['high', 'urgent'], true))
                                    <span class="inline-flex items-center gap-1 font-medium text-icon-rose"><x-portal.icon name="flag" class="size-4" />{{ Format::priority($task['priority']) }}</span>
                                @endif
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
