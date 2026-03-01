<?php

// Load traits
foreach (glob(__DIR__ . '/traits/LF*.php') as $f) require_once $f;

class LF {
    use LFEntity, LFRequest, LFResponse, LFAuth, LFSettings, LFSchema,
        LFValidation, LFHooks, LFDerived, LFVariables, LFFiles, LFCron,
        LFCors, LFRateLimit, LFApi, LFBootstrap;

    protected static ?Database $db = null;
    protected static array $types = [];
    protected static array $hooks = [];
    protected static array $derived = [];
    protected static array $effects = [];
    protected static array $settings = [];
    protected static array $roles = [];
    protected static ?Request $request = null;
    protected static ?array $matched_route = null;
    protected static ?object $current_user = null;
    protected static array $crons = [];
    protected static array $api_directives = [];
    protected static string $project_dir = '';

    /** Public getter for Database — replaces `global $db` */
    public static function db(): Database { return self::$db; }

    /** Public getter for types — needed by EntityQuery */
    public static function types(): array { return self::$types; }
}
