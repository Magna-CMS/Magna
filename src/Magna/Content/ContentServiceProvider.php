<?php

declare(strict_types=1);

namespace Magna\Content;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Magna\Auth\PermissionRegistry;
use Magna\Content\Console\AddPerformanceIndexesCommand;
use Magna\Content\Console\AddTranslationGroupsCommand;
use Magna\Content\Console\MakeTypeCommand;
use Magna\Content\Console\PublishScheduledCommand;
use Magna\Content\Console\RevisionsPruneCommand;
use Magna\Content\Console\SchemaDiffCommand;
use Magna\Content\Console\SchemaEncryptCommand;
use Magna\Content\Console\SchemaExportCommand;
use Magna\Content\Console\SchemaSyncCommand;
use Magna\Content\FieldTypes\BlocksField;
use Magna\Content\FieldTypes\BooleanField;
use Magna\Content\FieldTypes\ColorField;
use Magna\Content\FieldTypes\DateField;
use Magna\Content\FieldTypes\DatetimeField;
use Magna\Content\FieldTypes\EmailField;
use Magna\Content\FieldTypes\JsonField;
use Magna\Content\FieldTypes\MarkdownField;
use Magna\Content\FieldTypes\MediaField;
use Magna\Content\FieldTypes\NumberField;
use Magna\Content\FieldTypes\RelationField;
use Magna\Content\FieldTypes\RichtextField;
use Magna\Content\FieldTypes\SelectField;
use Magna\Content\FieldTypes\SlugField;
use Magna\Content\FieldTypes\TextareaField;
use Magna\Content\FieldTypes\TextField;
use Magna\Content\FieldTypes\UrlField;
use Magna\Install\Installer;

class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FieldTypeRegistry::class, function (): FieldTypeRegistry {
            $registry = new FieldTypeRegistry;
            $registry->register('text', TextField::class);
            $registry->register('textarea', TextareaField::class);
            $registry->register('richtext', RichtextField::class);
            $registry->register('markdown', MarkdownField::class);
            $registry->register('number', NumberField::class);
            $registry->register('boolean', BooleanField::class);
            $registry->register('date', DateField::class);
            $registry->register('datetime', DatetimeField::class);
            $registry->register('select', SelectField::class);
            $registry->register('media', MediaField::class);
            $registry->register('relation', RelationField::class);
            $registry->register('blocks', BlocksField::class);
            $registry->register('json', JsonField::class);
            $registry->register('slug', SlugField::class);
            $registry->register('email', EmailField::class);
            $registry->register('url', UrlField::class);
            $registry->register('color', ColorField::class);

            return $registry;
        });

        $this->app->singleton(SchemaRegistry::class, function (Application $app): SchemaRegistry {
            return new SchemaRegistry($app->make(FieldTypeRegistry::class));
        });

        $this->app->singleton(TableGenerator::class);

        $this->app->singleton(SchemaDiffer::class, function (Application $app): SchemaDiffer {
            return new SchemaDiffer(
                $app->make(TableGenerator::class),
                $app->make(FieldTypeRegistry::class),
            );
        });

        $this->app->singleton(SchemaSyncer::class, function (Application $app): SchemaSyncer {
            return new SchemaSyncer(
                $app->make(TableGenerator::class),
                $app->make(SchemaDiffer::class),
            );
        });

        $this->app->singleton(SchemaValidator::class);
        $this->app->singleton(SlugGenerator::class);

        $this->app->singleton(EntryManager::class, function (Application $app): EntryManager {
            return new EntryManager(
                $app->make(SchemaRegistry::class),
                $app->make(SchemaValidator::class),
                $app->make(AutoSlugApplier::class),
                $app->make(EntryRevisionRecorder::class),
                $app->make(HierarchyMaintainer::class),
            );
        });
    }

    public function boot(): void
    {
        /** @var SchemaRegistry $registry */
        $registry = $this->app->make(SchemaRegistry::class);

        // Auto-register content permissions whenever a type is registered.
        $this->registerContentPermissions($registry);

        $schemasDir = $this->app->basePath('schemas');
        if (is_dir($schemasDir)) {
            $registry->loadFromDirectory($schemasDir);
        }

        // Skip DB access until installed — pre-install there may be no database
        // driver at all, and Schema::hasTable would throw instead of returning.
        if (Installer::isInstalled() && Schema::hasTable('content_types')) {
            $registry->loadFromDatabase();
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // withoutOverlapping: this runs every minute and mutates state
            // (publishes/unpublishes due entries, firing EntryPublished /
            // EntryUnpublished — which enqueue webhooks and invalidate caches).
            // If one tick ever runs long (large backlog, slow DB) the next tick
            // must not start concurrently and double-process the same due
            // entries. Mirrors magna:updater:check, which already guards itself.
            $schedule->command('magna:publish:scheduled')->everyMinute()->withoutOverlapping();

            // Stage 13 (S5-02): this command existed but was never actually
            // scheduled — magna_revisions only got pruned if an operator
            // remembered to run it manually.
            $schedule->command('magna:revisions:prune')->daily();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeTypeCommand::class,
                SchemaEncryptCommand::class,
                SchemaDiffCommand::class,
                SchemaSyncCommand::class,
                SchemaExportCommand::class,
                PublishScheduledCommand::class,
                RevisionsPruneCommand::class,
                AddPerformanceIndexesCommand::class,
                AddTranslationGroupsCommand::class,
            ]);
        }
    }

    private function registerContentPermissions(SchemaRegistry $registry): void
    {
        /** @var PermissionRegistry $permRegistry */
        $permRegistry = $this->app->make(PermissionRegistry::class);

        $registry->onTypeRegistered(function (ContentType $type) use ($permRegistry): void {
            foreach (['view', 'create', 'update', 'publish', 'delete'] as $action) {
                $key = "content.{$type->handle}.{$action}";
                if (! $permRegistry->has($key)) {
                    $permRegistry->register($key, ucfirst($action)." \"{$type->displayName}\" entries");
                }
            }
        });
    }
}
