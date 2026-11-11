<?php

namespace Tests;

use Illuminate\Foundation\Application;
use LogicException;

/** Prevent tests with RefreshDatabase from wiping the operator's ACE MySQL. */
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $config = $app->make('config');
        if ($config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'sqlite'
            || $config->get('database.connections.sqlite.database') !== ':memory:') {
            throw new LogicException(
                'TESTES BLOQUEADOS: exige APP_ENV=testing e DB_CONNECTION=sqlite / DB_DATABASE=:memory:. '
                .'Nunca execute RefreshDatabase no banco ACE real.'
            );
        }
        return $app;
    }
}
