<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Prepare la requete suivante avec (ou sans) access token.
     *
     * Dans un meme test l'application est partagee entre les requetes : on reinitialise donc
     * le guard et les services "scoped" (dont le token JWT parse), ce que le cycle de vie
     * d'une vraie requete HTTP fait deja en production.
     */
    protected function api(?string $accessToken = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        return $accessToken ? $this->withToken($accessToken) : $this->withoutToken();
    }
}
