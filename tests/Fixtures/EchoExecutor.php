<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Fixtures;

use A2A\Helpers\ProtoHelpers;
use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\Part;

/**
 * Behaviour picked by the user's text:
 * - "message: ..." answers with a Message (no task)
 * - "input"        asks for more input (INPUT_REQUIRED)
 * - "slow N"       works for N seconds in 0.1 s steps, publishing progress, and stops when cancelled
 * - "fail"         throws
 * - "bad"          publishes a Task and then a Message (an invalid agent response)
 * - "pid"          one artifact "pid: <process id>" (shows which process ran it)
 * - anything else  one artifact "echo: <text>", then COMPLETED
 */
final class EchoExecutor implements AgentExecutor
{
    public function execute(RequestContext $context, EventQueue $eventQueue): void
    {
        $text = $context->getUserInput();
        $message = $context->message();
        if (str_starts_with($text, 'message:')) {
            $eventQueue->enqueueEvent(ProtoHelpers::newTextMessage('reply: ' . trim(substr($text, 8)), contextId: (string) $context->contextId()));

            return;
        }
        if ($context->currentTask() === null && $message !== null) {
            $eventQueue->enqueueEvent(ProtoHelpers::newTaskFromUserMessage($message));
        }
        $updater = new TaskUpdater($eventQueue, (string) $context->taskId(), (string) $context->contextId());

        if ($text === 'input') {
            $updater->requiresInput($updater->newAgentMessage([new Part(['text' => 'Tell me more.'])]));

            return;
        }
        if ($text === 'fail') {
            throw new \RuntimeException('boom');
        }
        if ($text === 'bad') {
            $eventQueue->enqueueEvent($updater->newAgentMessage([new Part(['text' => 'too late for a message'])]));

            return;
        }
        $updater->startWork();
        if (preg_match('/^slow (\d+(?:\.\d+)?)$/', $text, $m) === 1) {
            $steps = (int) round((float) $m[1] * 10);
            for ($i = 1; $i <= $steps; $i++) {
                usleep(100_000);
                if ($i % 10 === 0) {
                    $updater->addArtifact([new Part(['text' => "tick {$i}"])], artifactId: 'progress', append: $i > 10);
                }
            }
        }
        $reply = $text === 'pid' ? 'pid: ' . getmypid() : 'echo: ' . $text;
        $updater->addArtifact([new Part(['text' => $reply])], name: 'response', lastChunk: true);
        $updater->complete();
    }

    public function cancel(RequestContext $context, EventQueue $eventQueue): void
    {
        (new TaskUpdater($eventQueue, (string) $context->taskId(), (string) $context->contextId()))->cancel();
    }
}
