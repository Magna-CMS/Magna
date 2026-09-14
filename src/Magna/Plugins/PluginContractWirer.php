<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Conditions\DisplayConditionRegistry;
use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Blocks\DynamicTags\DynamicTagRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Contracts\DecoratesDeliveryResponse;
use Magna\Contracts\ExtendsEntryForm;
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
 * One private method per contract; the contracts whose consumers read a
 * plain list out of the container all go through appendToBinding(), which
 * owns the create-on-first-plugin/append-on-the-next accumulation those
 * four blocks used to each repeat by hand.
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
        $this->wireAdminNavigation($plugin);
        $this->loadSchemas($plugin);
        $this->wireBlocks($plugin);
        $this->wireDataSources($plugin);
        $this->wireDynamicTags($plugin);
        $this->wireDisplayConditions($plugin);
        $this->wireFrontendPages($plugin);

        // ExtendsEntryForm: the Filament admin EntryResource merges these
        // plugins' form components.
        if ($plugin instanceof ExtendsEntryForm) {
            $this->appendToBinding('magna.entry_form_plugins', [$plugin]);
        }

        // DecoratesDeliveryResponse: EntryTransformer injects these plugins'
        // data into delivery API responses.
        if ($plugin instanceof DecoratesDeliveryResponse) {
            $this->appendToBinding('magna.delivery_decorators', [$plugin]);
        }

        // RegistersLoginChecks: pre-authentication checks the login seam runs
        // before credentials are verified. Accumulation order is boot order
        // (dependency-ordered, deterministic); the checks are AND-ed — any one
        // denying denies the attempt — so order does not affect the security
        // outcome. Only enabled plugins are wired.
        if ($plugin instanceof RegistersLoginChecks) {
            $this->appendToBinding('magna.auth.login_checks', $plugin->loginChecks());
        }

        // RegistersCaptchaSurfaces: the captcha-protectable surfaces plugins
        // expose, so a security plugin can enumerate them for per-surface
        // toggles without hardcoding which plugins exist.
        if ($plugin instanceof RegistersCaptchaSurfaces) {
            $this->appendToBinding('magna.captcha.surfaces', $plugin->captchaSurfaces());
        }
    }

    private function wireAdminNavigation(Plugin $plugin): void
    {
        if ($plugin instanceof RegistersAdminNavigation) {
            $this->app->instance(
                'magna.nav.'.$plugin->getManifest()->name,
                $plugin->adminNavigation(),
            );
        }
    }

    /** Load plugin content type schemas from the schemas/ directory. */
    private function loadSchemas(Plugin $plugin): void
    {
        $schemasDir = $plugin->getBasePath().'/schemas';
        if (! is_dir($schemasDir)) {
            return;
        }

        /** @var SchemaRegistry $schemaRegistry */
        $schemaRegistry = $this->app->make(SchemaRegistry::class);
        $schemaRegistry->loadFromDirectory($schemasDir);
    }

    /**
     * Load plugin block definitions into the BlockRegistry, stamped with
     * their source plugin — theme addons may only override views for blocks
     * their pairsWith names (§C8).
     */
    private function wireBlocks(Plugin $plugin): void
    {
        if (! $plugin instanceof RegistersBlocks) {
            return;
        }

        /** @var BlockRegistry $blockRegistry */
        $blockRegistry = $this->app->make(BlockRegistry::class);
        foreach ($plugin->blocks() as $definition) {
            $blockRegistry->register(
                $definition->withSourcePlugin($plugin->getManifest()->name)
            );
        }
    }

    /** Plugin data feeds for the Loop block. */
    private function wireDataSources(Plugin $plugin): void
    {
        if (! $plugin instanceof RegistersDataSources) {
            return;
        }

        /** @var DataSourceRegistry $dataSources */
        $dataSources = $this->app->make(DataSourceRegistry::class);
        foreach ($plugin->dataSources() as $source) {
            $dataSources->register($source);
        }
    }

    /** Plugin values page fields can bind to. */
    private function wireDynamicTags(Plugin $plugin): void
    {
        if (! $plugin instanceof RegistersDynamicTags) {
            return;
        }

        /** @var DynamicTagRegistry $dynamicTags */
        $dynamicTags = $this->app->make(DynamicTagRegistry::class);
        foreach ($plugin->dynamicTags() as $tag) {
            $dynamicTags->register($tag);
        }
    }

    /** Plugin show/hide rules for nodes. */
    private function wireDisplayConditions(Plugin $plugin): void
    {
        if (! $plugin instanceof RegistersDisplayConditions) {
            return;
        }

        /** @var DisplayConditionRegistry $displayConditions */
        $displayConditions = $this->app->make(DisplayConditionRegistry::class);
        foreach ($plugin->displayConditions() as $condition) {
            $displayConditions->register($condition);
        }
    }

    /** Public pages the Pages router mounts. */
    private function wireFrontendPages(Plugin $plugin): void
    {
        if (! $plugin instanceof ProvidesFrontendPages) {
            return;
        }

        /** @var FrontendPageRegistry $frontendPages */
        $frontendPages = $this->app->make(FrontendPageRegistry::class);
        foreach ($plugin->frontendPages() as $page) {
            $frontendPages->register($page);
        }
    }

    /**
     * Append items to a container-held list binding, creating the list the
     * first time any plugin contributes to it. Consumers read the finished
     * list back out of the container by name.
     *
     * @param  iterable<mixed>  $items
     */
    private function appendToBinding(string $binding, iterable $items): void
    {
        /** @var list<mixed> $current */
        $current = $this->app->bound($binding) ? $this->app->make($binding) : [];

        foreach ($items as $item) {
            $current[] = $item;
        }

        $this->app->instance($binding, $current);
    }
}
