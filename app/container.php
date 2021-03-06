<?php

use Dock\Cli\Docker\DoctorCommand;
use Dock\Cli\Docker\InstallCommand;
use Dock\Cli\IO\ConsoleUserInteraction;
use Dock\Cli\IO\InteractiveProcessRunner;
use Dock\Cli\IO\LocalProject;
use Dock\Cli\LogsCommand;
use Dock\Cli\PsCommand;
use Dock\Cli\BuildCommand;
use Dock\Cli\ResetCommand;
use Dock\Cli\Docker\RestartCommand;
use Dock\Cli\RunCommand;
use Dock\Cli\SelfUpdateCommand;
use Dock\Cli\StartCommand;
use Dock\Cli\StopCommand;
use Dock\Docker\Compose\ComposeExecutableFinder;
use Dock\Docker\Compose\Config;
use Dock\Docker\Containers\ConfiguredContainers;
use Dock\Docker\ContainerDetails;
use Dock\Docker\Dns\DnsDockResolver;
use Dock\Docker\Compose\ConfiguredContainerIds;
use Dock\Docker\Compose\ContainerInspector;
use Dock\Docker\Compose\Logs;
use Dock\Docker\Machine\DockerMachineCli;
use Dock\Doctor;
use Dock\Doctor\TaskBasedDoctor;
use Dock\Installer\DockerInstaller;
use Dock\Installer\System\Catcher\ErrorCatcherDecoratorFactory;
use Dock\IO\Process\InteractiveProcessBuilder;
use Dock\IO\SilentProcessRunner;
use Dock\Plugins\ExtraHostname\HostnameFromComposerResolver;
use Dock\Plugins\ExtraHostname\HostsFileResolutionWriter;
use Dock\Plugins\ExtraHostname\ProjectManager\UpdatesManualDnsNamesOfContainers;
use Dock\Project\Decorator\CheckDockerConfigurationBeforeStarting;
use Dock\Project\DockerComposeProjectManager;
use Dock\System\OperatingSystemDetector;
use Pimple\Container;
use SRIO\ChainOfResponsibility\DecoratorFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

$container = new Container();

$container['command.selfupdate'] = function () {
    return new SelfUpdateCommand();
};

$container['command.install'] = function ($c) {
    return new InstallCommand($c['installer.docker']);
};

$container['doctor'] = function ($c) {
    return new TaskBasedDoctor($c['doctor.tasks']);
};

$container['command.doctor'] = function ($c) {
    return new DoctorCommand($c['doctor']);
};

$container['console.user_interaction'] = function ($c) {
    $userInteraction = new ConsoleUserInteraction();

    $c['event_dispatcher']->addListener(
        ConsoleEvents::COMMAND,
        function (ConsoleCommandEvent $event) use ($userInteraction) {
            $userInteraction->onCommand($event);
        }
    );

    return $userInteraction;
};

$container['process.interactive_runner'] = function ($c) {
    return new InteractiveProcessRunner($c['console.user_interaction']);
};

$container['process.silent_runner'] = function () {
    return new SilentProcessRunner();
};

$container['compose.executable_finder'] = function () {
    return new ComposeExecutableFinder();
};

$container['installer.docker.process_decorator_factory'] = function($c) {
    $processDecoratorFactory = new DecoratorFactory();
    $processDecoratorFactory = new ErrorCatcherDecoratorFactory($processDecoratorFactory, $c['console.user_interaction']);

    return $processDecoratorFactory;
};

$container['installer.docker'] = function ($c) {
    return new DockerInstaller($c['installer.task_provider'], $c['installer.docker.process_decorator_factory']);
};


$container['plugins.extra_hostname.composer_hostname_resolver'] = function ($c) {
    return new HostnameFromComposerResolver();
};

$container['plugins.extra_hostname.hostname_resolution_writer'] = function($c) {
    return new HostsFileResolutionWriter($c['process.silent_runner']);
};

$container['project.manager.docker_compose'] = function ($c) {
    return new DockerComposeProjectManager(
        $c['interactive.process_builder'],
        $c['console.user_interaction'],
        $c['process.interactive_runner'],
        $c['compose.executable_finder']
    );
};

$container['project.manager'] = function ($c) {
    $projectManager = $c['project.manager.docker_compose'];
    $projectManager = new CheckDockerConfigurationBeforeStarting($projectManager, $c['doctor'], $c['console.user_interaction']);
    $projectManager = new UpdatesManualDnsNamesOfContainers(
        $projectManager,
        $c['plugins.extra_hostname.composer_hostname_resolver'],
        $c['plugins.extra_hostname.hostname_resolution_writer'],
        $c['containers.inspector']
    );

    return $projectManager;
};

$container['machine'] = function($c) {
    return new DockerMachineCli($c['process.interactive_runner'], getenv('DOCKER_MACHINE_NAME') ?: DockerMachineCli::DEFAULT_MACHINE_NAME);
};

$container['command.restart'] = function ($c) {
    return new RestartCommand($c['machine']);
};

$container['interactive.process_builder'] = function($c) {
    return new InteractiveProcessBuilder($c['console.user_interaction']);
};
$container['command.start'] = function ($c) {
    return new StartCommand($c['project.manager'], $c['compose.project']);
};
$container['command.stop'] = function ($c) {
    return new StopCommand($c['project.manager'], $c['compose.project']);
};
$container['command.build'] = function ($c) {
    return new BuildCommand($c['project.manager.docker_compose'], $c['compose.project']);
};
$container['command.reset'] = function ($c) {
    return new ResetCommand($c['project.manager'], $c['compose.project']);
};
$container['command.ps'] = function ($c) {
    return new PsCommand(new ConfiguredContainers(
        $c['containers.configured_container_ids'],
        $c['containers.container_details']
    ));
};
$container['containers.configured_container_ids'] = function ($c) {
    return new ConfiguredContainerIds($c['process.silent_runner']);
};
$container['containers.container_details'] = function ($c) {
    return new ContainerDetails($c['process.silent_runner'], new DnsDockResolver());
};

$container['containers.inspector'] = function ($c) {
    return new ContainerInspector($c['containers.configured_container_ids'], $c['containers.container_details']);
};

$container['command.logs'] = function ($c) {
    return new LogsCommand($c['logs']);
};

$container['compose.project'] = function ($c) {
    return new LocalProject();
};

$container['compose.config'] = function ($c) {
    return new Config($c['compose.project']);
};

$container['command.run'] = function ($c) {
    return new RunCommand($c['process.interactive_runner'], $c['compose.config']);
};

$container['event_dispatcher'] = function () {
    return new EventDispatcher();
};

$container['logs'] = function ($c) {
    return new Logs($c['compose.executable_finder'], $c['process.silent_runner']);
};

$container['doctor.tasks'] = function($c) {
    return [
        new Doctor\Docker($c['process.silent_runner'], new Doctor\Action\StartMachineOrInstall($c['machine'], $c['installer.docker']), $c['installer.docker']),
        new Doctor\DnsDock($c['process.silent_runner'], $c['installer.dns.dnsdock'], $c['installer.dns.docker_routing']),
    ];
};

$container['application'] = function ($c) {
    $application = new Application('Dock CLI', '@package_version@');
    $application->setDispatcher($c['event_dispatcher']);
    $application->addCommands([
        $c['command.selfupdate'],
        $c['command.doctor'],
        $c['command.install'],
        $c['command.restart'],
        $c['command.run'],
        $c['command.start'],
        $c['command.stop'],
        $c['command.build'],
        $c['command.reset'],
        $c['command.ps'],
        $c['command.logs'],
    ]);

    return $application;
};

$osDetector = new OperatingSystemDetector();
if ($osDetector->isMac()) {
    require dirname(__FILE__) . DIRECTORY_SEPARATOR . 'container.mac.php';
} elseif ($osDetector->isDebian()) {
    require dirname(__FILE__) . DIRECTORY_SEPARATOR . 'container.debian.php';
} elseif ($osDetector->isRedHat()) {
    require dirname(__FILE__) . DIRECTORY_SEPARATOR . 'container.redhat.php';
} else {
    throw new \Exception($osDetector->isLinux()
        ? "Installer does not support linux distribution: " . $osDetector->getLinuxDistribution()
        : "Installer does not support operating system: " . $osDetector->getOperatingSystem());
}

return $container;
