<?php

namespace Spatie\Async;

use ArrayAccess;
use Spatie\Async\Runtime\ParentRuntime;

class Pool implements ArrayAccess
{
    protected $concurrency = 20;
    protected $tasksPerProcess = 1;
    protected $timeout = 300;
    protected $sleepTime = 50000;

    /** @var \Spatie\Async\ParallelProcess[] */
    protected $queue = [];

    /** @var \Spatie\Async\ParallelProcess[] */
    protected $inProgress = [];

    /** @var \Spatie\Async\ParallelProcess[] */
    protected $finished = [];

    /** @var \Spatie\Async\ParallelProcess[] */
    protected $failed = [];

    /** @var \Spatie\Async\ParallelProcess[] */
    protected $timeouts = [];

    protected $results = [];

    protected $status;

    public function __construct()
    {
        $this->registerListener();

        $this->status = new PoolStatus($this);
    }

    /**
     * @return static
     */
    public static function create()
    {
        return new static();
    }

    public static function isSupported(): bool
    {
        return function_exists('pcntl_async_signals') && function_exists('posix_kill');
    }

    public function concurrency(int $concurrency): self
    {
        $this->concurrency = $concurrency;

        return $this;
    }

    public function timeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function autoload(string $autoloader): self
    {
        ParentRuntime::init($autoloader);

        return $this;
    }

    public function sleepTime(int $sleepTime): self
    {
        $this->sleepTime = $sleepTime;

        return $this;
    }

    public function notify()
    {
        if (count($this->inProgress) >= $this->concurrency) {
            return;
        }

        $process = array_shift($this->queue);

        if (! $process) {
            return;
        }

        $this->putInProgress($process);
    }

    /**
     * @param \Spatie\Async\ParallelProcess|callable $process
     *
     * @return \Spatie\Async\ParallelProcess
     */
    public function add($process): ParallelProcess
    {
        if (! $process instanceof ParallelProcess) {
            $process = ParentRuntime::createChildProcess($process);
        }

        $this->putInQueue($process);

        return $process;
    }

    public function wait(): array
    {
        while ($this->inProgress) {
            foreach ($this->inProgress as $process) {
                if ($process->getCurrentExecutionTime() > $this->timeout) {
                    $this->markAsTimedOut($process);
                }
            }

            if (! $this->inProgress) {
                break;
            }

            usleep($this->sleepTime);
        }

        return $this->results;
    }

    public function putInQueue(ParallelProcess $process)
    {
        $this->queue[$process->getId()] = $process;

        $this->notify();
    }

    public function putInProgress(ParallelProcess $process)
    {
        $process->getProcess()->setTimeout($this->timeout);

        $process->start();

        unset($this->queue[$process->getId()]);

        $this->inProgress[$process->getPid()] = $process;
    }

    public function markAsFinished(ParallelProcess $process)
    {
        unset($this->inProgress[$process->getPid()]);

        $this->notify();

        $this->results[] = $process->triggerSuccess();

        $this->finished[$process->getPid()] = $process;
    }

    public function markAsTimedOut(ParallelProcess $process)
    {
        unset($this->inProgress[$process->getPid()]);

        $this->notify();

        $process->triggerTimeout();

        $this->timeouts[$process->getPid()] = $process;
    }

    public function markAsFailed(ParallelProcess $process)
    {
        unset($this->inProgress[$process->getPid()]);

        $this->notify();

        $process->triggerError();

        $this->failed[$process->getPid()] = $process;
    }

    public function offsetExists($offset)
    {
        // TODO

        return false;
    }

    public function offsetGet($offset)
    {
        // TODO
    }

    public function offsetSet($offset, $value)
    {
        $this->add($value);
    }

    public function offsetUnset($offset)
    {
        // TODO
    }

    /**
     * @return \Spatie\Async\ParallelProcess[]
     */
    public function getFinished(): array
    {
        return $this->finished;
    }

    /**
     * @return \Spatie\Async\ParallelProcess[]
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * @return \Spatie\Async\ParallelProcess[]
     */
    public function getTimeouts(): array
    {
        return $this->timeouts;
    }

    public function status(): PoolStatus
    {
        return $this->status;
    }

    protected function registerListener()
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGCHLD, function ($signo, $status) {
            while (true) {
                $pid = pcntl_waitpid(-1, $processState, WNOHANG | WUNTRACED);

                if ($pid <= 0) {
                    break;
                }

                $process = $this->inProgress[$pid] ?? null;

                if (! $process) {
                    continue;
                }

                if ($status['status'] === 0) {
                    $this->markAsFinished($process);

                    continue;
                }

                $this->markAsFailed($process);
            }
        });
    }
}
