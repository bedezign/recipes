<?php
/* (c) Steve Guns <steve@bedezign.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer;

// Define the origin
use Deployer\Exception\RuntimeException;

set('docker_default_command_template', '{{bin/docker}} exec -i {{container}} bash -c {{?command}}');

function runTaskInDocker($task, $containers, $additionalConfiguration = [])
{
    $task = Deployer::get()->tasks->get($task);

    if (!$task) {
        throw new \InvalidArgumentException('Invalid task specified');
    }

    $containers = (array)$containers;
    $iterations = [];
    foreach ($containers as $name) {
        $iteration = [
            'command_template' => has('docker_command_template') ?
                get('docker_command_template') : get('docker_default_command_template'),
            'container'        => $name
        ];

        foreach ($additionalConfiguration as $key => $value) {
            $iteration[$key] = $value;
        }

        $iterations[] = $iteration;
    }

    $task->iterate($iterations);
}

function installDockerCE()
{
    startOperation('Installing <info>Docker CE</info> (takes a few minutes)');
    aptUpdate();

    run('apt-get install -y apt-transport-https gnupg2 software-properties-common');
    run('curl -fsSL https://download.docker.com/linux/$(. /etc/os-release; echo "$ID")/gpg | apt-key add -');
    run('add-apt-repository "deb [arch=amd64] https://download.docker.com/linux/$(. /etc/os-release; echo "$ID") $(lsb_release -cs) stable"');
    run('apt-get update && apt-get install -y docker-ce');
    endOperation();
}

function installDockerMachine()
{
    startOperation('Installing <info>Docker Machine</info>');
    run('curl -L https://github.com/docker/machine/releases/download/v0.12.2/docker-machine-`uname -s`-`uname -m` > /usr/bin/docker-machine && chmod +x /usr/bin/docker-machine');
    endOperation();
}

function installPHPExtension($extensions)
{
    static $pluginDefinitions = [
        'curl'      => ['libcurl4-openssl-dev'],
        'gd'        => ['libpng12-dev'],            // Used to require libfreetype6-dev and libjpeg62-turbo-dev as well but seems to work without now
        'hash'      => true,
        'imap'      => ['libc-client-dev libkrb5-dev', '--with-kerberos --with-imap-ssl'],
        'intl'      => ['libicu-dev'],
        'mbstring'  => true,
        'mcrypt'    => ['libmcrypt-dev'],
        'pcntl'     => true,
        'pdo_mysql' => true,
        'xsl'       => ['libxslt-dev'],
        'zip'       => ['zlib1g-dev'],
    ];

    $extensions = is_array($extensions) ? $extensions : func_get_args();

    foreach ($extensions as $extension) {
        if (!array_key_exists($extension, $pluginDefinitions))
            throw new RuntimeException("Unknown PHP Extension \"$extension\"");

        $configuration = $pluginDefinitions[$extension];
        $parts         = [];
        if (is_array($configuration)) {
            if ($dependencies = reset($configuration)) {
                $parts[] = 'apt-get install -y ' . $dependencies;
            }
            if (2 === count($configuration)) {
                $parts[] = "docker-php-ext-configure $extension " . end($configuration);
            }
        }
        $parts[] = "docker-php-ext-install $extension";

        startOperation("Installing PHP extension <info>\"$extension\"</info>");
        run(implode(' && ', $parts));
        endOperation();
    }
}

function aptUpdate()
{
    run('apt-get update');
}

function aptCleanup()
{
    run('apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*');
}

function runOnHost($command, $options = [])
{
    return runWithoutTemplate(function() use ($command, $options) {
        return run($command, $options);
    });
}

function isContainerRunning($container = null)
{
    $container = $container ?? '{{container}}';
    return runWithoutTemplate(function() use ($container) {
        return test("{{bin/docker}} exec $container true 2>/dev/null");
    });
}

set('bin/docker', function() {
    return runWithoutTemplate(function() {
        return locateBinaryPath('docker');
    });
});

set('bin/docker-compose', function() {
    return runWithoutTemplate(function() {
        return locateBinaryPath('docker-compose');
    });
});
