<?php

namespace App\Console\Commands;

use App\Models\Worker;
use Illuminate\Console\Command;

class ListWorkers extends Command
{
    protected $signature = 'workers:list {--online : Only workers with a recent heartbeat}';
    protected $description = 'List the registered workers and what they are doing';

    public function handle(): int
    {
        $workers = Worker::query()->when($this->option('online'), fn ($query) => $query->online())->orderBy('name')->get();
        if ($workers->isEmpty()) {
            $this->warn('No workers registered. Start one with WORKER_APP_URL and WORKER_TOKEN set (see worker/.env.example).');
            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'URL', 'Status', 'GPU', 'Can', 'Priority', 'Last seen', 'Task'],
            $workers->map(fn (Worker $worker) => [
                $worker->name,
                $worker->url,
                ! $worker->enabled ? 'disabled' : ($worker->isOnline() ? 'online' : 'offline'),
                trim(($worker->gpu_name ?? '').' '.($worker->backend ? "({$worker->backend})" : '')),
                implode(', ', $worker->capabilities ?? []),
                $worker->priority,
                $worker->last_seen_at?->diffForHumans() ?? 'never',
                $worker->current_task !== null ? "{$worker->current_task} stream #{$worker->current_stream_id}" : '-',
            ]),
        );

        return self::SUCCESS;
    }
}
