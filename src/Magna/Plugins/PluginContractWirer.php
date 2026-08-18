<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Conditions\DisplayConditionRegistry;
use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Blocks\DynamicTags\DynamicTagRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Contracts\CaptchaSurface;
use Magna\Contracts\DecoratesDeliveryResponse;
use Magna\Contracts\ExtendsEntryForm;
use Magna\Contracts\LoginCheck;
use Magna\Contracts\ProvidesFrontendPages;
use Magna\Contracts\RegistersAdminNavigation;
use Magna\Contracts\RegistersBlocks;
use Magna\Contracts\RegistersCaptchaSurfaces;
use Magna\Contracts\RegistersDataSources;
use Magna\Contracts\RegistersDisplayConditions;
use Magna\Contracts\RegistersDynamicTags;
use Magna\Contracts\RegistersLoginChecks;
use Magna\Frontend\FrontendPageRegistry;

/**
 * Wires a booted plugin's container-backed capability contracts (extracted
 * from PluginManager, which orchestrates the lifecycle and stays out of
 * per-contract detail): admin navigation, content-type schemas, blocks,
 * data sources, dynamic tags, entry-form extensions, delivery decorators.
 *
 * The remaining capability contracts are dispatched where their target
 * surface is actually built, not here:
 *   - RegistersDashboardWidgets / RegistersSettingsPages → the Filament
 *     panel in AdminServiceProvider + AdminPanelProvider.
 *   - RegistersWebhookEvents → WebhookServiceProvider (event registry).
 */
class PluginContractWirer
{
    public function __construct(private readonly Application $app) {}

    public function wire(Plugin $plugin): void
    {
        if ($plugin instanceof RegistersAdminNavigation) {
            $this->app->instance(
                'magna.nav.'.$plugin->getManifest()->name,
                $plugin->adminNavigation(),
            );
        }

        // Load plugin content type schemas from schemas/ directory.
        $schemasDir = $plugin->getBasePath().'/schemas';
        if (is_dir($schemasDir)) {
            /** @var SchemaRegistry $schemaRegistry */
            $schemaRegistry = $this->app->make(SchemaRegistry::class);
            $schemaRegistry->loadFromDirectory($schemasDir);
        }

        // Wire RegistersBlocks: load plugin block definitions into the
        // BlockRegistry, stamped with their source plugin — theme addons may
        // only override views for blocks their pairsWith names (§C8).
        if ($plugin instanceof RegistersBlocks) {
            /** @var BlockRegistry $blockRegistry */
            $blockRegistry = $this->app->make(BlockRegistry::class);
            foreach ($plugin->blocks() as $definition) {
                $blockRegistry->register(
                    $definition->withSourcePlugin($plugin->getManifest()->name)
                );
            }
        }

        // Wire RegistersDataSources: plugin data feeds for the Loop block.
        if ($plugin instanceof RegistersDataSources) {
            /** @var DataSourceRegistry $dataSources */
            $dataSources = $this->app->make(DataSourceRegistry::class);
            foreach ($plugin->dataSources() as $source) {
                $dataSources->register($source);
            }
        }

        // Wire RegistersDynamicTags: plugin values page fields can bind to.
        if ($plugin instanceof RegistersDynamicTags) {
            /** @var DynamicTagRegistry $dynamicTags */
            $dynamicTags = $this->app->make(DynamicTagRegistry::class);
            foreach ($plugin->dynamicTags() as $tag) {
                $dynamicTags->register($tag);
            }
        }

        // Wire RegistersDisplayConditions: plugin show/hide rules for nodes.
        if ($plugin instanceof RegistersDisplayConditions) {
            /** @var DisplayConditionRegistry $displayConditions */
            $displayConditions = $this->app->make(DisplayConditionRegistry::class);
            foreach ($plugin->displayConditions() as $condition) {
                $displayConditions->register($condition);
            }
        }

        // Wire ProvidesFrontendPages: public pages the Pages router mounts.
        if ($plugin instanceof ProvidesFrontendPages) {
            /** @var FrontendPageRegistry $frontendPages */
            $frontendPages = $this->app->make(FrontendPageRegistry::class);
            foreach ($plugin->frontendPages() as $page) {
                $frontendPages->register($page);
            }
        }

        // Wire ExtendsEntryForm: accumulate plugins in the container so the
        // Filament admin EntryResource (Magna\Admin\Resources\EntryResource)
        // can merge their form components.
        if ($plugin instanceof ExtendsEntryForm) {
            /** @var list<ExtendsEntryForm> $current */
            $current = $this->app->bound('magna.entry_form_plugins')
                ? $this->app->make('magna.entry_form_plugins')
                : [];
            $current[] = $plugin;
            $this->app->instance('magna.entry_form_plugins', $current);
        }

        // Wire DecoratesDeliveryResponse: accumulate plugins in the container so
        // EntryTransformer can inject their data into delivery API responses.
        if ($plugin instanceof DecoratesDeliveryResponse) {
            /** @var list<DecoratesDeliveryResponse> $current */
            $current = $this->app->bound('magna.delivery_decorators')
                ? $this->app->make('magna.delivery_decorators')
                : [];
            $current[] = $plugin;
            $this->app->instance('magna.delivery_decorators', $current);
        }

        // Wire RegistersLoginChecks: accumulate pre-authentication checks the
        // login seam runs before credentials are verified. Accumulation order is
        // boot order (dependency-ordered, deterministic); the checks are AND-ed
        // — any one denying denies the attempt — so order does not affect the
        // security outcome. Only enabled plugins are wired.
        if ($plugin instanceof RegistersLoginChecks) {
            /** @var list<class-string<LoginCheck>|LoginCheck> $current */
            $current = $this->app->bound('magna.auth.login_checks')
                ? $this->app->make('magna.auth.login_checks')
                : [];
            foreach ($plugin->loginChecks() as $check) {
                $current[] = $check;
            }
            $this->app->instance('magna.auth.login_checks', $current);
        }

        // Wire RegistersCaptchaSurfaces: accumulate the captcha-protectable
        // surfaces plugins expose, so a security plugin can enumerate them for
        // per-surface toggles without hardcoding which plugins exist.
        if ($plugin instanceof RegistersCaptchaSurfaces) {
            /** @var list<CaptchaSurface> $current */
            $current = $this->app->bound('magna.captcha.surfaces')
                ? $this->app->make('magna.captcha.surfaces')
                : [];
            foreach ($plugin->captchaSurfaces() as $surface) {
                $current[] = $surface;
            }
            $this->app->instance('magna.captcha.surfaces', $current);
        }
    }
}
