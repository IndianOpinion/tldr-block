<?php

// phpcs:ignoreFile -- WordPress class stubs for tests.

declare(strict_types=1);

#[AllowDynamicProperties]
class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_title = '';
    public string $post_content = '';
    public string $post_password = '';
    public int $post_author = 0;
    public string $post_modified_gmt = '';

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields)
    {
        foreach ($fields as $k => $v) {
            $this->$k = $v;
        }
    }
}

class WP_Term
{
    public int $term_id = 0;
    public string $name = '';
    public string $slug = '';
    public int $parent = 0;
    public string $taxonomy = 'category';

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields)
    {
        foreach ($fields as $k => $v) {
            $this->$k = $v;
        }
    }
}

class WP_Error
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(private string $code = '', private string $message = '', mixed $data = [])
    {
        $this->data = is_array($data) ? $data : [];
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

class WP_REST_Request
{
    /** @param array<string, mixed> $params */
    public function __construct(private string $method = 'GET', private string $route = '', private array $params = [])
    {
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function set_param(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_route(): string
    {
        return $this->route;
    }
}

class WP_REST_Response
{
    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

class WP_Query
{
    /** @var array<string, mixed> */
    public array $query_vars = [];

    public function __construct(private bool $main = true)
    {
    }

    public function is_main_query(): bool
    {
        return $this->main;
    }

    public function set(string $key, mixed $value): void
    {
        $this->query_vars[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->query_vars[$key] ?? null;
    }
}

class WP_CLI_Stop extends RuntimeException
{
}

class WP_CLI
{
    /** @var list<string> */
    public static array $lines = [];
    /** @var array<string, mixed> */
    public static array $commands = [];

    public static function add_command(string $name, mixed $callable): void
    {
        self::$commands[$name] = $callable;
    }

    public static function line(string $s = ''): void
    {
        self::$lines[] = $s;
    }

    public static function log(string $s): void
    {
        self::$lines[] = $s;
    }

    public static function success(string $s): void
    {
        self::$lines[] = 'Success: ' . $s;
    }

    public static function warning(string $s): void
    {
        self::$lines[] = 'Warning: ' . $s;
    }

    public static function error(string $s): void
    {
        self::$lines[] = 'Error: ' . $s;
        throw new WP_CLI_Stop($s);
    }
}

class wpdb
{
    public string $options = 'wp_options';
    public string $sitemeta = 'wp_sitemeta';
    /** @var list<string> */
    public array $queries = [];

    public function esc_like(string $s): string
    {
        return addcslashes($s, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $a) {
            $query = (string) preg_replace_callback('/%[si]/', static fn (array $m): string => $m[0] === '%i' ? '`' . $a . '`' : "'" . addslashes((string) $a) . "'", $query, 1);
        }

        return $query;
    }

    public function query(string $sql): int
    {
        $this->queries[] = $sql;
        // Mirror the transient delete against the stub store.
        if (preg_match_all("/LIKE '([^']*)'/", $sql, $m) > 0) {
            foreach ($m[1] as $like) {
                $prefix = str_replace(['\\_', '\\%', '%'], ['_', '%', ''], stripslashes($like));
                foreach (array_keys(\IndianOpinion\TldrBlock\Tests\Stubs\WpStub::$transients) as $k) {
                    if (str_starts_with('_transient_' . $k, $prefix) || str_starts_with('_transient_timeout_' . $k, $prefix)) {
                        unset(\IndianOpinion\TldrBlock\Tests\Stubs\WpStub::$transients[$k]);
                    }
                }
            }
        }

        return 0;
    }
}
