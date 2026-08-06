<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Idle timeout (minutes)
    |--------------------------------------------------------------------------
    |
    | Après cette durée sans activité API, le token Sanctum est invalidé.
    | Pendant un examen / quiz / formulaire, le front envoie un heartbeat
    | pour maintenir la session.
    |
    */
    'idle_timeout_minutes' => (int) env('SESSION_IDLE_TIMEOUT_MINUTES', 20),

    /*
    |--------------------------------------------------------------------------
    | Throttle des écritures last_activity_at
    |--------------------------------------------------------------------------
    */
    'activity_touch_seconds' => (int) env('SESSION_ACTIVITY_TOUCH_SECONDS', 60),

];
