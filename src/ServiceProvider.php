<?php

namespace elnurvl\DemoSailPlugin;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Sail\Sail;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
    }

    public function boot()
    {
        Sail::setBaseTemplate(__DIR__ . '/../stubs/docker-compose.stub')
            ->addService('redis', isDefault: true, env: [
                'REDIS_FOO' => 'bar',
            ], preInstallCallback: function (Command $command, array $services, string $appService) {
                $command->getOutput()->info('[Demo Sail Plugin] This message appears only if you select redis service.');
            })
            ->addService('phpmyadmin', __DIR__ . '/../stubs/phpmyadmin.stub', isDependency: false)
            ->addPreInstallCallback(function (Command $command, array $services, string $appService) {
                $answer = $command->ask('Would you like to do additional stuff before building the images?', 'y');
                $command->getOutput()->info('[Demo Sail Plugin] You chose: '. $answer);
                $command->getOutput()->info('[Demo Sail Plugin] Selected services: '. implode(', ', $services));
                $command->getOutput()->info('[Demo Sail Plugin] APP_SERVICE: '. $appService);
            })->addNetwork(['sail-external' => ['external' => true]]);
    }
}