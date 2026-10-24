@php
    use App\Support\Portal\Format;
    use App\Livewire\Portal\TaskShow;
    use Carbon\CarbonImmutable;
@endphp
<div>
    <x-portal.back :href="route('portal.tasks')">Tasks</x-portal.back>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <h1 class="sr-only">Task</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($task)
        @php
            $companyDate = $companyDay['date'] ?? null;
            $dueState = Format::taskDueState($task, $companyDate);
            $dueValue = $task['due_date'] === null ? null : Format::date($task['due_date']).match ($dueState) {
                'overdue' => ' · Overdue',
                'due_today' => ' · Due today',
                default => '',
            };
        @endphp
        <x-portal.icon-tile icon="tasks" :tone="Format::tileTone(Format::tone($task['status']))" size="lg" class="mb-3" />
        <h1 class="text-2xl font-bold tracking-tight">{{ $task['title'] }}</h1>
        @if ($mayEdit || $mayDelete)
            <div class="mt-3 flex flex-wrap gap-2">
                @if ($mayEdit)
                    <a href="{{ route('portal.tasks.edit', $task['public_id']) }}" class="btn btn-secondary">Edit task</a>
                @endif
                @if ($mayDelete)
                    <x-portal.confirm title="Delete this task?" body="Only a task that is still To do, with no work logged, can be deleted. Otherwise cancel it instead." confirm="Delete" action="delete()" :danger="true"
                                      class="btn btn-danger-outline">Delete task</x-portal.confirm>
                @endif
            </div>
            @if ($generalError)
                <p role="alert" class="mt-3 font-medium text-danger">{{ $generalError }}</p>
            @endif
        @endif
        <p class="mt-2 flex flex-wrap items-center gap-3">
            <x-portal.chip :tone="Format::tone($task['status'])">{{ Format::taskStatus($task['status']) }}</x-portal.chip>
            <span>Priority: {{ Format::priority($task['priority']) }}</span>
        </p>

        <section aria-labelledby="task-status" class="mt-6 card p-5">
            <h2 id="task-status" class="mb-3 text-lg font-bold tracking-tight">Status</h2>
            @if ($task['status'] === 'cancelled')
                <p class="text-muted">This task was cancelled. Its status can't be changed here.</p>
            @else
                <div role="group" aria-label="Change status" class="flex flex-wrap gap-2">
                    @foreach (TaskShow::SELECTABLE as $status)
                        <button type="button" wire:click="setStatus('{{ $status }}')" aria-pressed="{{ $task['status'] === $status ? 'true' : 'false' }}"
                                @disabled($task['status'] === $status || $locked) wire:loading.attr="disabled" wire:target="setStatus"
                                class="inline-flex min-h-11 items-center rounded-full border px-4 {{ $task['status'] === $status ? 'border-accent bg-accent-soft font-semibold text-accent-ink' : 'border-field hover:bg-hover disabled:opacity-50' }}">
                            {{ Format::taskStatus($status) }}
                        </button>
                    @endforeach
                </div>
                <p wire:loading wire:target="setStatus" class="mt-3 text-sm text-muted">Saving…</p>
                <div aria-live="polite">
                    <x-portal.toast :message="$savedMessage" :nonce="$toastNonce ?? 0" />
                    @if ($saveMessage)
                        <p role="alert" class="mt-3 text-sm font-medium text-danger">{{ $saveMessage }}</p>
                    @endif
                </div>
                <a href="{{ route('portal.work-logs.new', ['task' => $task['public_id']]) }}"
                   class="btn btn-secondary mt-4">Log work</a>
            @endif
        </section>

        <section aria-labelledby="task-details" class="mt-6 card px-5">
            <h2 id="task-details" class="pt-4 text-lg font-bold tracking-tight">Details</h2>
            <dl>
                <x-portal.info-row label="Due date" :value="$dueValue" />
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-line py-3">
                    <dt class="text-sm text-muted">Project</dt>
                    <dd class="font-medium">
                        @if ($task['project'] ?? null)
                            <a href="{{ route('portal.project', $task['project']['public_id']) }}" class="inline-flex min-h-11 items-center text-accent-ink underline-offset-4 hover:underline">{{ $task['project']['name'] }}</a>
                        @else
                            Independent task
                        @endif
                    </dd>
                </div>
                <x-portal.info-row label="Created by" :value="$task['created_by']['display_name'] ?? null" />
                @if ($task['completed_at'] ?? null)
                    <x-portal.info-row label="Completed" :value="Format::date(CarbonImmutable::parse($task['completed_at'])->setTimezone($companyDay['timezone'] ?? 'UTC')->toDateString())" />
                @endif
            </dl>
        </section>

        <section aria-labelledby="task-description" class="mt-6 card p-5">
            <h2 id="task-description" class="mb-3 text-lg font-bold tracking-tight">Description</h2>
            @if (filled($task['description'] ?? null))
                <p class="whitespace-pre-line">{{ $task['description'] }}</p>
            @else
                <p class="text-muted">No description.</p>
            @endif
        </section>
    @endif
</div>
