<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A timer falling due, on the queue.
 *
 * Only {@see FireWorkflowTimersHandler} journals `TimerCompleted`: a plain resume replays the run,
 * finds the timer still pending and suspends on it again (#726). The handler resumes the run once
 * a timer has fired, and re-schedules itself when it was delivered early.
 *
 * ponytail: no ResumeLock here. Firing is a pass (DUR053): a concurrent resume supersedes it, and
 * that resume, still waiting on the due timer, schedules a new firing. Take the lock if contention
 * on hot timers ever shows up as extra passes.
 */
final class FireWorkflowTimersJob implements ShouldQueue
{
    public function __construct(
        public readonly FireWorkflowTimersMessage $message,
    ) {}

    public function handle(FireWorkflowTimersHandler $handler): void
    {
        $handler($this->message);
    }
}
