<?php

namespace Dock\Installer;

use Dock\IO\ProcessRunner;
use Dock\IO\UserInteraction;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class InteractiveProcessRunner implements ProcessRunner
{
    /**
     * @var UserInteraction
     */
    private $userInteraction;

    /**
     * @param UserInteraction $userInteraction
     */
    public function __construct(UserInteraction $userInteraction)
    {
        $this->userInteraction = $userInteraction;
    }

    /**
     * {@inheritdoc}
     */
    public function run(Process $process, $mustSucceed = true)
    {
        $this->userInteraction->write('<info>RUN</info> '.$process->getCommandLine());

        if ($mustSucceed) {
            $process->setTimeout(null);

            return $process->mustRun($this->getRunningProcessCallback());
        }

        $process->run($this->getRunningProcessCallback());
        return $process;
    }

    /**
     * @return callable
     */
    private function getRunningProcessCallback()
    {
        return function ($type, $buffer) {
            $lines = explode("\n", $buffer);
            $prefix = Process::ERR === $type ? '<error>ERR</error> ' : '<question>OUT</question> ';

            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $this->userInteraction->write($prefix.$line);
                }
            }
        };
    }
}
