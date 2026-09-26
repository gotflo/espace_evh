<?php

namespace Tests;

use App\Models\Event;
use App\Models\Exercise;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Evenement avec ses destinataires (toute l'eglise par defaut).
     *
     * @param  array<int, array{type: string, id: int|null}>  $scopes
     */
    protected function makeEvent(array $attributes, array $scopes = [['type' => 'church', 'id' => null]]): Event
    {
        $event = Event::create($attributes);
        $event->syncScopes($scopes);

        return $event->fresh();
    }

    /** @param array<int, array{type: string, id: int|null}> $scopes */
    protected function makeExercise(array $attributes, array $scopes = [['type' => 'church', 'id' => null]]): Exercise
    {
        $exercise = Exercise::create($attributes);
        $exercise->syncScopes($scopes);

        return $exercise->fresh();
    }
}
