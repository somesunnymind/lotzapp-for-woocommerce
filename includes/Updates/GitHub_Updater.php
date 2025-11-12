<?php

namespace Lotzwoo\Updates;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight GitHub-based update manager inspired by plugin-update-checker.
 */
class GitHub_Updater
{
    private string $owner;
    private string $repository;
    private string $branch;
    private string $plugin_file;
    private string $plugin_basename;
    private string $slug;
    private string $cache_key;
    private int $cache_ttl;
    private ?string $token;

    /**
     * @var array<string, string>
     */
    private ?array $plugin_data = null;

    /**
     * @var array<string, string>
     */
    private ?array $readme_header = null;

    /**
     * @param array{
     *     owner:string,
     *     repository:string,
     *     plugin_file:string,
     *     branch?:string,
     *     slug?:string,
     *     token?:?string,
     *     cache_ttl?:int
     * } $args
     */
    public function __construct(array $args)
    {
        $defaults = [
            'owner'       => '',
            'repository'  => '',
            'plugin_file' => '',
            'branch'      => 'main',
            'slug'        => '',
            'token'       => null,
            'cache_ttl'   => 12 * HOUR_IN_SECONDS,
        ];

        $args = wp_parse_args($args, $defaults);

        $this->owner        = trim((string) $args['owner']);
        $this->repository   = trim((string) $args['repository']);
        $this->branch       = trim((string) $args['branch']) ?: 'main';
        $this->plugin_file  = (string) $args['plugin_file'];
        $this->slug         = $args['slug'] !== '' ? sanitize_key((string) $args['slug']) : '';
        $this->token        = $args['token'] !== null && $args['token'] !== '' ? (string) $args['token'] : null;
        $this->cache_ttl    = (int) $args['cache_ttl'] > 0 ? (int) $args['cache_ttl'] : 6 * HOUR_IN_SECONDS;
        $this->plugin_basename = plugin_basename($this->plugin_file);

        if ($this->slug === '') {
            $this->slug = dirname($this->plugin_basename);
        }

        $hash_source      = implode(':', [$this->owner, $this->repository, $this->branch]);
        $this->cache_key  = 'lotzwoo_github_release_' . md5($hash_source);
    }

    public static function boot(array $args): self
    {
        $instance = new self($args);
        $instance->init();
        return $instance;
    }

    public function init(): void
    {
        if (!$this->is_configured()) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'filter_plugin_information'], 20, 3);
        add_filter('http_request_args', [$this, 'maybe_authorize_http'], 15, 2);
        add_action('upgrader_process_complete', [$this, 'maybe_flush_cache'], 10, 2);
    }

    private function is_configured(): bool
    {
        return (bool) ($this->owner && $this->repository && $this->plugin_file && $this->plugin_basename);
    }

    /**
     * @param object $transient
     * @return object
     */
    public function inject_update($transient)
    {
        if (!$this->is_configured()) {
            return $transient;
        }

        if (!is_object($transient) || empty($transient->checked[$this->plugin_basename])) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (!$release || empty($release['version'])) {
            return $transient;
        }

        $current_version = (string) $transient->checked[$this->plugin_basename];
        if (version_compare($release['version'], $current_version, '<=')) {
            unset($transient->response[$this->plugin_basename]);
            return $transient;
        }

        $transient->response[$this->plugin_basename] = (object) [
            'slug'        => $this->slug,
            'plugin'      => $this->plugin_basename,
            'new_version' => $release['version'],
            'url'         => $release['homepage'],
            'package'     => $release['package'],
            'tested'      => $release['tested'],
            'requires'    => $release['requires'],
        ];

        return $transient;
    }

    /**
     * @param mixed $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function filter_plugin_information($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $result;
        }

        $data = $this->get_plugin_data();

        $sections = array_filter([
            'description' => !empty($data['Description']) ? wpautop(wp_kses_post($data['Description'])) : '',
            'changelog'   => !empty($release['body']) ? wpautop(wp_kses_post($release['body'])) : '',
        ]);

        return (object) [
            'name'          => $data['Name'] ?: $this->slug,
            'slug'          => $this->slug,
            'version'       => $release['version'],
            'author'        => $data['Author'],
            'author_profile'=> $data['AuthorURI'],
            'requires'      => $release['requires'],
            'requires_php'  => $data['RequiresPHP'],
            'tested'        => $release['tested'],
            'homepage'      => $release['homepage'],
            'sections'      => $sections,
            'download_link' => $release['package'],
            'external'      => true,
        ];
    }

    public function maybe_authorize_http(array $args, string $url): array
    {
        if (!$this->token) {
            return $args;
        }

        $needs_auth = (stripos($url, 'github.com/') !== false)
            || (stripos($url, 'codeload.github.com/') !== false)
            || (stripos($url, 'api.github.com/') !== false);

        if (!$needs_auth) {
            return $args;
        }

        $args['headers']['Authorization'] = 'token ' . $this->token;
        return $args;
    }

    /**
     * Flush cached release data once the plugin was updated.
     *
     * @param \WP_Upgrader $upgrader
     * @param array<string, mixed> $hook_extra
     */
    public function maybe_flush_cache($upgrader, array $hook_extra): void
    {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = (array) ($hook_extra['plugins'] ?? []);
        if (in_array($this->plugin_basename, $plugins, true)) {
            $this->purge_cache();
        }
    }

    public function purge_cache(): void
    {
        delete_site_transient($this->cache_key);
    }

    /**
     * @return array{version:string,package:string,body:string,tested:string,requires:string,homepage:string}|null
     */
    private function get_latest_release(): ?array
    {
        $cached = get_site_transient($this->cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $endpoint = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode($this->owner),
            rawurlencode($this->repository)
        );

        $response = $this->request($endpoint);
        if (!$response) {
            return null;
        }

        $mapped = $this->map_release_response($response);
        if (!$mapped) {
            return null;
        }

        set_site_transient($this->cache_key, $mapped, $this->cache_ttl);

        return $mapped;
    }

    private function request(string $url): ?array
    {
        $args = [
            'timeout' => 20,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => $this->get_user_agent(),
            ],
        ];

        if ($this->token) {
            $args['headers']['Authorization'] = 'token ' . $this->token;
        }

        $response = wp_remote_get($url, $args);
        if ($response instanceof WP_Error) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $response
     * @return array{version:string,package:string,body:string,tested:string,requires:string,homepage:string}|null
     */
    private function map_release_response(array $response): ?array
    {
        if (empty($response['tag_name'])) {
            return null;
        }

        $version = $this->normalize_version((string) $response['tag_name']);
        if ($version === '') {
            return null;
        }

        $package = !empty($response['zipball_url'])
            ? (string) $response['zipball_url']
            : sprintf(
                'https://github.com/%s/%s/archive/refs/tags/%s.zip',
                rawurlencode($this->owner),
                rawurlencode($this->repository),
                rawurlencode((string) $response['tag_name'])
            );

        $plugin_data = $this->get_plugin_data();
        $readme = $this->get_readme_header();

        $requires = $readme['requires_at_least'] ?? ($plugin_data['RequiresWP'] ?? '');
        $tested   = $readme['tested_up_to'] ?? '';
        $homepage = !empty($plugin_data['PluginURI'])
            ? $plugin_data['PluginURI']
            : sprintf('https://github.com/%s/%s', $this->owner, $this->repository);

        return [
            'version'  => $version,
            'package'  => $package,
            'body'     => (string) ($response['body'] ?? ''),
            'tested'   => $tested,
            'requires' => $requires,
            'homepage' => $homepage,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function get_plugin_data(): array
    {
        if ($this->plugin_data !== null) {
            return $this->plugin_data;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $defaults = [
            'Name'        => '',
            'Version'     => '',
            'Author'      => '',
            'AuthorURI'   => '',
            'RequiresPHP' => '',
            'RequiresWP'  => '',
            'PluginURI'   => '',
            'Description' => '',
        ];

        $data = get_plugin_data($this->plugin_file, false, false);

        $this->plugin_data = wp_parse_args($data, $defaults);

        return $this->plugin_data;
    }

    /**
     * @return array<string, string>
     */
    private function get_readme_header(): array
    {
        if ($this->readme_header !== null) {
            return $this->readme_header;
        }

        $path = trailingslashit(dirname($this->plugin_file)) . 'readme.txt';
        if (!is_readable($path)) {
            $this->readme_header = [];
            return $this->readme_header;
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            $this->readme_header = [];
            return $this->readme_header;
        }

        $headers = [];
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                break;
            }

            if (strpos($line, ':') === false) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $normalized = strtolower(str_replace([' ', '-'], '_', $key));
            $headers[$normalized] = $value;
        }

        fclose($handle);

        $this->readme_header = $headers;

        return $this->readme_header;
    }

    private function normalize_version(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return '';
        }

        return ltrim($tag, 'v');
    }

    private function get_user_agent(): string
    {
        $site = function_exists('home_url') ? home_url('/') : get_bloginfo('url');
        if (!$site) {
            $site = 'LotzWoo';
        }

        return sprintf('LotzWoo Update Checker (%s)', untrailingslashit($site));
    }
}

