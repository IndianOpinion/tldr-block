<?php

// phpcs:ignoreFile -- WordPress function stubs for tests.

declare(strict_types=1);

use IndianOpinion\TldrBlock\Tests\Stubs\WpStub as S;

// Hooks.
function add_filter(string $tag, callable $cb, int $priority = 10, int $args = 1): bool
{
    S::$hooks[$tag][$priority][] = [$cb, $args];
    return true;
}
function add_action(string $tag, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($tag, $cb, $priority, $args);
}
function remove_all_filters(string $tag): void
{
    unset(S::$hooks[$tag]);
}
function has_filter(string $tag, mixed $cb = false): bool|int
{
    return !empty(S::$hooks[$tag]);
}
function has_action(string $tag, mixed $cb = false): bool|int
{
    return has_filter($tag, $cb);
}
function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    if (empty(S::$hooks[$tag])) {
        return $value;
    }
    S::$doing[] = $tag;
    try {
        ksort(S::$hooks[$tag]);
        foreach (S::$hooks[$tag] as $callbacks) {
            foreach ($callbacks as [$cb, $n]) {
                $value = $cb(...array_slice([$value, ...$args], 0, max(1, $n)));
            }
        }
    } finally {
        array_pop(S::$doing);
    }
    return $value;
}
function do_action(string $tag, mixed ...$args): void
{
    S::$fired[$tag][] = $args;
    if (empty(S::$hooks[$tag])) {
        return;
    }
    S::$doing[] = $tag;
    try {
        ksort(S::$hooks[$tag]);
        foreach (S::$hooks[$tag] as $callbacks) {
            foreach ($callbacks as [$cb, $n]) {
                $cb(...array_slice($args, 0, $n));
            }
        }
    } finally {
        array_pop(S::$doing);
    }
}
function doing_filter(?string $tag = null): bool
{
    return $tag === null ? S::$doing !== [] : in_array($tag, S::$doing, true);
}
function did_action(string $tag): int
{
    return count(S::$fired[$tag] ?? []);
}
function __return_false(): bool
{
    return false;
}

// i18n and escaping.
function __(string $s, string $domain = 'default'): string
{
    return $s;
}
function esc_html__(string $s, string $domain = 'default'): string
{
    return esc_html($s);
}
function esc_html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_url(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function sanitize_text_field(string $s): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($s)));
}
function sanitize_key(string $s): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
}
function sanitize_title(string $s): string
{
    return (string) preg_replace('/[^a-z0-9\-]+/', '-', strtolower(trim($s)));
}
function absint(mixed $v): int
{
    return abs((int) $v);
}
function wp_unslash(mixed $v): mixed
{
    return is_string($v) ? stripslashes($v) : $v;
}
function wp_strip_all_tags(string $s): string
{
    $s = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $s);
    return trim(strip_tags($s));
}
function wp_json_encode(mixed $v, int $flags = 0): string|false
{
    return json_encode($v, $flags);
}
function wp_generate_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}
function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return $a === $b ? ' checked="checked"' : '';
}

// Options and transients.
function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, S::$options[S::$blog] ?? []) ? S::$options[S::$blog][$name] : $default;
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    S::$options[S::$blog][$name] = $value;
    return true;
}
function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
{
    if (array_key_exists($name, S::$options[S::$blog] ?? [])) {
        return false;
    }
    S::$options[S::$blog][$name] = $value;
    return true;
}
function delete_option(string $name): bool
{
    unset(S::$options[S::$blog][$name]);
    return true;
}
function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}
function get_transient(string $k): mixed
{
    return S::$transients[$k] ?? false;
}
function set_transient(string $k, mixed $v, int $ttl = 0): bool
{
    S::$transients[$k] = $v;
    return true;
}
function delete_transient(string $k): bool
{
    unset(S::$transients[$k]);
    return true;
}

// Posts and meta.
function get_post(mixed $id = null): ?WP_Post
{
    if ($id instanceof WP_Post) {
        return $id;
    }
    return S::$posts[S::$blog][(int) $id] ?? null;
}
function get_post_type(mixed $id = null): string|false
{
    $p = get_post($id);
    return $p ? $p->post_type : false;
}
function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    return S::$postmeta[S::$blog][$id][$key] ?? ($single ? '' : []);
}
function update_post_meta(int $id, string $key, mixed $value): bool
{
    S::$postmeta[S::$blog][$id][$key] = $value;
    return true;
}
function add_post_meta(int $id, string $key, mixed $value, bool $unique = false): bool
{
    if ($unique && isset(S::$postmeta[S::$blog][$id][$key])) {
        return false;
    }
    S::$postmeta[S::$blog][$id][$key] = $value;
    return true;
}
function delete_post_meta(int $id, string $key): bool
{
    unset(S::$postmeta[S::$blog][$id][$key]);
    return true;
}
function get_term_meta(int $id, string $key = '', bool $single = false): mixed
{
    return S::$termmeta[S::$blog][$id][$key] ?? ($single ? '' : []);
}
function update_term_meta(int $id, string $key, mixed $value): bool
{
    S::$termmeta[S::$blog][$id][$key] = $value;
    return true;
}
function delete_term_meta(int $id, string $key): bool
{
    unset(S::$termmeta[S::$blog][$id][$key]);
    return true;
}
function delete_metadata(string $type, int $id, string $key, mixed $value = '', bool $all = false): bool
{
    if ($type === 'term' && $all) {
        foreach (S::$termmeta[S::$blog] ?? [] as $tid => $_) {
            unset(S::$termmeta[S::$blog][$tid][$key]);
        }
    }
    return true;
}
function register_post_meta(string $type, string $key, array $args): bool
{
    S::$registeredMeta['post:' . $type . ':' . $key] = $args;
    return true;
}
function register_term_meta(string $tax, string $key, array $args): bool
{
    S::$registeredMeta['term:' . $tax . ':' . $key] = $args;
    return true;
}
function is_protected_meta(string $key, string $type = ''): bool
{
    return str_starts_with($key, '_');
}
function wp_is_post_autosave(mixed $post): int|false
{
    $p = get_post($post);
    return $p && !empty($p->is_autosave) ? (int) $p->ID : false;
}
function wp_is_post_revision(mixed $post): int|false
{
    $p = get_post($post);
    return $p && $p->post_type === 'revision' ? (int) $p->ID : false;
}
function post_password_required(mixed $post = null): bool
{
    $p = get_post($post);
    return $p !== null && $p->post_password !== '' && !S::flag('password_cookie');
}
function clean_post_cache(int $id): void
{
    S::$cleaned[] = 'post:' . $id;
}
function get_the_title(int $id = 0): string
{
    return get_post($id)?->post_title ?? '';
}
function get_edit_post_link(int $id = 0, string $context = 'display'): string
{
    return 'https://example.org/wp-admin/post.php?post=' . $id . '&action=edit';
}
function is_object_in_taxonomy(string $type, string $tax): bool
{
    return $type === 'post' && $tax === 'category';
}
function wp_get_post_categories(int $id, array $args = []): array
{
    return S::$postCategories[$id] ?? [];
}
function get_ancestors(int $id, string $type = '', string $rt = ''): array
{
    $out = [];
    $t = S::$terms[$id] ?? null;
    while ($t !== null && $t->parent > 0) {
        $out[] = $t->parent;
        $t = S::$terms[$t->parent] ?? null;
    }
    return $out;
}
function get_term(int $id): ?WP_Term
{
    return S::$terms[$id] ?? null;
}
function get_post_types(array $args = [], string $output = 'names'): array
{
    return ['post' => 'post', 'page' => 'page'];
}
function post_type_supports(string $type, string $feature): bool
{
    return true;
}
function wp_dropdown_categories(array $args = []): string
{
    return '<select name="' . ($args['name'] ?? 'cat') . '"></select>';
}

/**
 * Minimal get_posts: post_type, post_status, meta_key, meta_value,
 * meta_query (one clause), cat, category_name, orderby modified, paging.
 *
 * @param array<string, mixed> $q
 * @return list<int|WP_Post>
 */
function get_posts(array $q = []): array
{
    $rows = [];
    $types = $q['post_type'] ?? 'post';
    $statuses = $q['post_status'] ?? 'publish';
    foreach (S::$posts[S::$blog] ?? [] as $id => $p) {
        if ($types !== 'any' && !in_array($p->post_type, (array) $types, true)) {
            continue;
        }
        if ($statuses === 'any') {
            if ($p->post_status === 'trash') {
                continue;
            }
        } elseif (!in_array($p->post_status, (array) $statuses, true)) {
            continue;
        }
        $meta = S::$postmeta[S::$blog][$id] ?? [];
        if (isset($q['meta_key']) && !array_key_exists($q['meta_key'], $meta)) {
            continue;
        }
        if (isset($q['meta_value']) && ($meta[$q['meta_key']] ?? null) !== $q['meta_value']) {
            continue;
        }
        if (isset($q['meta_query'][0])) {
            $c = $q['meta_query'][0];
            $v = $meta[$c['key']] ?? null;
            $cmp = $c['compare'] ?? (is_array($c['value'] ?? null) ? 'IN' : '=');
            if ($cmp === 'IN' && !in_array($v, (array) $c['value'], true)) {
                continue;
            }
            if ($cmp === '=' && $v !== $c['value']) {
                continue;
            }
            if ($cmp === 'NOT EXISTS' && $v !== null) {
                continue;
            }
        }
        if (isset($q['cat']) && !in_array((int) $q['cat'], S::$postCategories[$id] ?? [], true)) {
            continue;
        }
        if (isset($q['category_name'])) {
            $ok = false;
            foreach (S::$postCategories[$id] ?? [] as $tid) {
                if ((S::$terms[$tid]->slug ?? '') === $q['category_name']) {
                    $ok = true;
                }
            }
            if (!$ok) {
                continue;
            }
        }
        $rows[] = $p;
    }
    if (($q['orderby'] ?? '') === 'modified') {
        usort($rows, fn ($a, $b) => strcmp($b->post_modified_gmt, $a->post_modified_gmt));
    }
    $per = (int) ($q['posts_per_page'] ?? 5);
    if ($per > 0) {
        $page = max(1, (int) ($q['paged'] ?? 1));
        $rows = array_slice($rows, ($page - 1) * $per, $per);
    }
    if (($q['fields'] ?? '') === 'ids') {
        return array_map(fn ($p) => $p->ID, $rows);
    }
    return $rows;
}

// Users and capabilities.
function get_current_user_id(): int
{
    return S::$userId;
}
function user_can(int $user, string $cap, mixed ...$args): bool
{
    $caps = S::$caps[$user] ?? [];
    if ($cap === 'edit_post' || $cap === 'read_post') {
        $p = get_post($args[0] ?? 0);
        if ($p === null) {
            return false;
        }
        if ($cap === 'read_post' && $p->post_status === 'publish' && !empty($caps['read'])) {
            return true;
        }
        if (empty($caps['edit_posts'])) {
            return false;
        }
        return (int) $p->post_author === $user || !empty($caps['edit_others_posts']);
    }
    return !empty($caps[$cap]);
}
function current_user_can(string $cap, mixed ...$args): bool
{
    return user_can(S::$userId, $cap, ...$args);
}
function rest_authorization_required_code(): int
{
    return S::$userId > 0 ? 403 : 401;
}

// Conditionals and the loop.
function is_admin(): bool
{
    return S::flag('admin');
}
function is_singular(): bool
{
    return S::flag('singular');
}
function in_the_loop(): bool
{
    return S::flag('in_the_loop');
}
function is_main_query(): bool
{
    return S::flag('main_query');
}
function is_feed(): bool
{
    return S::flag('feed');
}
function get_the_ID(): int|false
{
    return (int) (S::$flags['the_id'] ?? 0) ?: false;
}
function get_queried_object_id(): int
{
    return (int) (S::$flags['queried_id'] ?? 0);
}
function is_multisite(): bool
{
    return S::$multisite;
}
function get_sites(array $args = []): array
{
    return array_slice(S::$sites, (int) ($args['offset'] ?? 0), (int) ($args['number'] ?? 100));
}
function switch_to_blog(int $id): bool
{
    S::$blogStack[] = S::$blog;
    S::$blog = $id;
    return true;
}
function restore_current_blog(): bool
{
    S::$blog = array_pop(S::$blogStack) ?? 1;
    return true;
}

// Locale and time.
function get_locale(): string
{
    return S::$locale;
}
function get_bloginfo(string $show = ''): string
{
    return $show === 'language' ? str_replace('_', '-', S::$locale) : 'Test site';
}
function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(S::$timezone);
}
function home_url(string $path = ''): string
{
    return 'https://example.org' . $path;
}
function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . $path;
}
function plugins_url(string $path = '', string $file = ''): string
{
    return 'https://example.org/wp-content/plugins/tldr-block/' . $path;
}
function plugin_basename(string $file): string
{
    return 'tldr-block/' . basename($file);
}
function load_plugin_textdomain(string $d, bool $x = false, string $p = ''): bool
{
    return true;
}

// Cron.
function wp_schedule_single_event(int $ts, string $hook, array $args = []): bool
{
    S::$cron[S::$blog][] = ['ts' => $ts, 'hook' => $hook, 'args' => $args];
    return true;
}
function wp_next_scheduled(string $hook, array $args = []): int|false
{
    $next = false;
    foreach (S::$cron[S::$blog] ?? [] as $e) {
        if ($e['hook'] === $hook && $e['args'] === $args) {
            $next = $next === false ? $e['ts'] : min($next, $e['ts']);
        }
    }
    return $next;
}
function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    $n = 0;
    foreach (S::$cron[S::$blog] ?? [] as $i => $e) {
        if ($e['hook'] === $hook && $e['args'] === $args) {
            unset(S::$cron[S::$blog][$i]);
            $n++;
        }
    }
    S::$cron[S::$blog] = array_values(S::$cron[S::$blog] ?? []);
    return $n;
}
function wp_unschedule_hook(string $hook): int
{
    $before = count(S::$cron[S::$blog] ?? []);
    S::$cron[S::$blog] = array_values(array_filter(S::$cron[S::$blog] ?? [], fn ($e) => $e['hook'] !== $hook));
    return $before - count(S::$cron[S::$blog]);
}
function _get_cron_array(): array
{
    $out = [];
    foreach (S::$cron[S::$blog] ?? [] as $e) {
        $out[$e['ts']][$e['hook']][md5(serialize($e['args']))] = ['args' => $e['args']];
    }
    return $out;
}

// Assets, shortcodes, blocks, REST.
function wp_register_style(string $h, string $src, array $deps = [], mixed $ver = false): bool
{
    return true;
}
function wp_enqueue_style(string $h): void
{
    S::$styles[] = $h;
}
function wp_enqueue_script(string $h, string $src = '', array $deps = [], mixed $ver = false, mixed $footer = false): void
{
    S::$styles[] = 'script:' . $h;
}
function wp_set_script_translations(string $h, string $d): bool
{
    return true;
}
function add_shortcode(string $tag, callable $cb): void
{
    S::$shortcodes[$tag] = $cb;
}
function register_block_type(string $path, array $args = []): bool
{
    S::$blocks[$path] = $args;
    return true;
}
function get_block_wrapper_attributes(array $extra = []): string
{
    return 'class="wp-block-tldr-block-summary"';
}
function register_rest_route(string $ns, string $route, array $args): bool
{
    S::$routes[$ns . $route] = $args;
    return true;
}
function register_rest_field(string $type, string $field, array $args): void
{
    S::$restFields[$type . ':' . $field] = $args;
}
function is_wp_error(mixed $v): bool
{
    return $v instanceof WP_Error;
}

// Admin helpers.
function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    return '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '" />';
}
function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return $nonce === 'nonce-' . $action ? 1 : false;
}
function wp_add_privacy_policy_content(string $name, string $text): void
{
    S::$privacy[] = $text;
}
function add_options_page(string ...$a): string
{
    return 'settings';
}
function add_posts_page(string ...$a): string
{
    return 'review';
}
function wp_remote_post(string $url, array $args = []): WP_Error
{
    return new WP_Error('http_request_failed', 'No network in tests.');
}
