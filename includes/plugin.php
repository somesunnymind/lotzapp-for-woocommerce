<?php

namespace Lotzwoo;

use DateTimeImmutable;
use DateTimeZone;
use Lotzwoo\Migrations\Legacy_Migrator;
use Lotzwoo\Migrations\Menu_Planning_Migrator;
use Lotzwoo\Providers\Admin_Service_Provider;
use Lotzwoo\Providers\Ajax_Service_Provider;
use Lotzwoo\Providers\Assets_Service_Provider;
use Lotzwoo\Providers\Cron_Service_Provider;
use Lotzwoo\Providers\Frontend_Service_Provider;
use Lotzwoo\Providers\Service_Provider_Interface;
use Lotzwoo\Providers\Shortcode_Service_Provider;
use Lotzwoo\Services\Menu_Planning_Runner;
use Lotzwoo\Services\Menu_Planning_Service;
use Lotzwoo\Services\Product_Media_Service;
use Lotzwoo\Settings\Defaults;
use Lotzwoo\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private static ?self $instance = null;
    private static ?Repository $options_repository = null;
    private static ?Defaults $defaults_provider = null;

    private Container $container;

    /**
     * @var array<int, Service_Provider_Interface>
     */
    private array $providers = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        $repository = new Repository();
        $migrator   = new Legacy_Migrator($repository);
        $migrator->run();
        (new Menu_Planning_Migrator())->maybe_run();
    }

    public static function deactivate(): void
    {
        Menu_Planning_Runner::unschedule_event();
    }

    private function __construct()
    {
        $this->define_constants();
        $this->bootstrap_container();
        $this->register_providers();
        $this->boot_providers();

        add_action('init', [$this, 'run_pending_migrations'], 5);
    }

    public function run_pending_migrations(): void
    {
        $migrators = [
            Legacy_Migrator::class,
            Menu_Planning_Migrator::class,
        ];

        foreach ($migrators as $migrator_class) {
            if (!$this->container->has($migrator_class)) {
                continue;
            }

            $migrator = $this->container->get($migrator_class);
            if (method_exists($migrator, 'maybe_run')) {
                $migrator->maybe_run();
            }
        }
    }

    public static function defaults(): array
    {
        return self::get_defaults_provider()->all();
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function opt(string $key, $default = null)
    {
        return self::get_repository()->get($key, $default);
    }

    public static function ca_prices_enabled(): bool
    {
        $enabled = self::opt('ca_prices_enabled', 1);
        return (bool) (is_numeric($enabled) ? (int) $enabled : $enabled);
    }

    public static function update_opt(array $data): void
    {
        self::get_repository()->update($data);
    }

    public static function legacy_option_name(): string
    {
        return implode('', ['c', 'a', 'e', 'p', '_options']);
    }

    public static function legacy_buffer_meta_key(): string
    {
        return '_' . implode('', ['c', 'a', 'e', 'p']) . '_is_buffer_product';
    }

    /**
     * @return array{frequency:string,weekday:string,monthday:int,time:string}
     */
    public static function menu_planning_schedule(): array
    {
        $defaults  = self::defaults();
        $frequency = (string) self::opt('menu_planning_frequency', $defaults['menu_planning_frequency']);
        $weekday   = (string) self::opt('menu_planning_weekday', $defaults['menu_planning_weekday']);
        $monthday  = (int) self::opt('menu_planning_monthday', $defaults['menu_planning_monthday']);
        $time      = (string) self::opt('menu_planning_time', $defaults['menu_planning_time']);

        $frequency = self::normalize_frequency($frequency, (string) $defaults['menu_planning_frequency']);
        $weekday   = self::normalize_weekday($weekday, (string) $defaults['menu_planning_weekday']);
        $monthday  = self::normalize_monthday($monthday, (int) $defaults['menu_planning_monthday']);
        $time      = self::normalize_time($time, (string) $defaults['menu_planning_time']);

        return [
            'frequency' => $frequency,
            'weekday'   => $weekday,
            'monthday'  => $monthday,
            'time'      => $time,
        ];
    }

    public static function next_menu_planning_event(?DateTimeZone $timezone = null, ?DateTimeImmutable $reference = null): DateTimeImmutable
    {
        $schedule = self::menu_planning_schedule();
        $timezone = $timezone ?: (function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get()));
        $now      = $reference instanceof DateTimeImmutable ? $reference->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);

        [$hour, $minute] = array_map('intval', explode(':', $schedule['time']));
        $frequency = $schedule['frequency'];

        if ($frequency === 'daily') {
            $candidate = $now->setTime($hour, $minute);
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+1 day');
            }
            return $candidate;
        }

        if ($frequency === 'monthly') {
            return self::next_monthly_occurrence($now, (int) $schedule['monthday'], $hour, $minute);
        }

        return self::next_weekly_occurrence($now, (string) $schedule['weekday'], $hour, $minute);
    }

    private static function next_weekly_occurrence(DateTimeImmutable $from, string $weekday, int $hour, int $minute): DateTimeImmutable
    {
        $weekday  = self::normalize_weekday($weekday, 'monday');
        $candidate = $from->modify('this ' . $weekday)->setTime($hour, $minute);
        if ($candidate <= $from) {
            $candidate = $candidate->modify('+1 week');
        }
        return $candidate;
    }

    private static function next_monthly_occurrence(DateTimeImmutable $from, int $monthday, int $hour, int $minute): DateTimeImmutable
    {
        $monthday = self::normalize_monthday($monthday, 1);
        $candidate = self::build_monthly_candidate($from, $monthday, $hour, $minute);
        if ($candidate <= $from) {
            $candidate = self::build_monthly_candidate($from->modify('first day of next month'), $monthday, $hour, $minute);
        }
        return $candidate;
    }

    private static function build_monthly_candidate(DateTimeImmutable $reference, int $monthday, int $hour, int $minute): DateTimeImmutable
    {
        $year  = (int) $reference->format('Y');
        $month = (int) $reference->format('n');
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $target_day    = min($monthday, $days_in_month);

        return $reference
            ->setDate($year, $month, $target_day)
            ->setTime($hour, $minute);
    }

    private function define_constants(): void
    {
        if (!defined('LOTZWOO_FEATURE_BLOCKS')) {
            define('LOTZWOO_FEATURE_BLOCKS', true);
        }
    }

    private function bootstrap_container(): void
    {
        $this->container = new Container();

        $this->container->set(Repository::class, static function (Container $container) {
            $repository = new Repository();
            self::$options_repository = $repository;
            return $repository;
        });

        $this->container->set(Defaults::class, static function () {
            $defaults = new Defaults();
            self::$defaults_provider = $defaults;
            return $defaults;
        });

        $this->container->set(Product_Media_Service::class, static function (Container $container) {
            return new Product_Media_Service($container->get(Repository::class));
        });

        $this->container->set(Legacy_Migrator::class, static function (Container $container) {
            return new Legacy_Migrator($container->get(Repository::class));
        });

        $this->container->set(Menu_Planning_Service::class, static function () {
            return new Menu_Planning_Service();
        });

        $this->container->set(Menu_Planning_Runner::class, static function (Container $container) {
            return new Menu_Planning_Runner($container->get(Menu_Planning_Service::class));
        });

        $this->container->set(Menu_Planning_Migrator::class, static function () {
            return new Menu_Planning_Migrator();
        });

        // Ensure repository is available for static access early.
        $this->container->get(Repository::class);
    }

    private function register_providers(): void
    {
        $this->providers = [
            new Assets_Service_Provider(),
            new Shortcode_Service_Provider(),
            new Ajax_Service_Provider(),
            new Admin_Service_Provider(),
            new Frontend_Service_Provider(),
            new Cron_Service_Provider(),
        ];

        foreach ($this->providers as $provider) {
            $provider->register($this->container);
        }
    }

    private function boot_providers(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }
    }

    private static function get_repository(): Repository
    {
        if (self::$options_repository instanceof Repository) {
            return self::$options_repository;
        }

        if (self::$instance instanceof self) {
            /** @var Repository $repository */
            $repository = self::$instance->container->get(Repository::class);
            self::$options_repository = $repository;
            return $repository;
        }

        $repository = new Repository();
        self::$options_repository = $repository;
        return $repository;
    }

    private static function get_defaults_provider(): Defaults
    {
        if (self::$defaults_provider instanceof Defaults) {
            return self::$defaults_provider;
        }

        if (self::$instance instanceof self) {
            /** @var Defaults $defaults */
            $defaults = self::$instance->container->get(Defaults::class);
            self::$defaults_provider = $defaults;
            return $defaults;
        }

        $defaults = new Defaults();
        self::$defaults_provider = $defaults;
        return $defaults;
    }

    private static function normalize_weekday(string $weekday, string $fallback): string
    {
        $weekday = strtolower(trim($weekday));
        $valid   = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        return in_array($weekday, $valid, true) ? $weekday : $fallback;
    }

    private static function normalize_frequency(string $frequency, string $fallback): string
    {
        $frequency = strtolower(trim($frequency));
        $valid     = ['daily', 'weekly', 'monthly'];
        return in_array($frequency, $valid, true) ? $frequency : $fallback;
    }

    private static function normalize_monthday(int $monthday, int $fallback): int
    {
        if ($monthday < 1 || $monthday > 31) {
            return $fallback >= 1 && $fallback <= 31 ? $fallback : 1;
        }
        return $monthday;
    }

    private static function normalize_time(string $time, string $fallback): string
    {
        $time = trim($time);
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : $fallback;
    }
}
