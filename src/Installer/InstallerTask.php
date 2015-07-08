<?php

namespace Dock\Installer;

use Dock\IO\ProcessRunner;
use SRIO\ChainOfResponsibility\ChainContext;
use SRIO\ChainOfResponsibility\ChainProcessInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

abstract class InstallerTask implements ChainProcessInterface
{
    /**
     * @param ChainContext $context
     */
    public function execute(ChainContext $context)
    {
        if (!$context instanceof InstallContext) {
            throw new \RuntimeException('Expected console context');
        }

        $this->run($context);
    }

    /**
     * @param InstallContext $context
     */
    abstract public function run(InstallContext $context);
}
