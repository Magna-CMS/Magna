<?php

declare(strict_types=1);

use Tests\Support\CloneScanner;

/**
 * The duplication ratchet.
 *
 * Copy-paste is how an AI-assisted codebase rots fastest: each paste works,
 * reviews clean in isolation, and the third copy is where the bug fix misses
 * one. This test makes every clone a decision instead of an accident — a
 * detected clone is either extracted or accepted below WITH A REASON, and an
 * accepted clone that stops existing must leave the list. The list only
 * shrinks or is consciously re-justified; it never absorbs new entries as a
 * way past a red test.
 *
 * What counts as a clone, the thresholds, and why identifiers stay literal
 * are CloneScanner's contract (75 normalised tokens, variable names folded).
 * The scan deliberately skips tests/ and database/ — tests repeat set-up as
 * a feature, and migration boilerplate is the framework's shape — and covers
 * every plugin's src/ alongside core, because plugins are where the same
 * login stack has already been pasted four times.
 *
 * Keys are content-addressed: `<fingerprint> <fileA> <-> <fileB>`, where the
 * fingerprint hashes the clone's normalised token text. Editing either copy
 * changes the fingerprint, so a stale entry fails and the acceptance has to
 * be re-earned. On CI plugins-dev is absent; entries whose files are not on
 * disk are ignored rather than reported stale, so the same list serves both
 * environments.
 */
function duplicationRoot(): string
{
    return str_replace('\\', '/', dirname(__DIR__, 3));
}

function duplicationScanSet(): array
{
    $base = duplicationRoot();
    $roots = [$base.'/src/Magna', $base.'/app'];

    foreach (glob($base.'/plugins-dev/*/*/src') ?: [] as $pluginSrc) {
        if (str_contains($pluginSrc, 'quarantine')) {
            continue;
        }

        $roots[] = $pluginSrc;
    }

    $paths = [];
    $base .= '/';

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = str_replace([$base, '\\'], ['', '/'], $file->getPathname());
            }
        }
    }

    sort($paths);

    return $paths;
}

it('treats every code clone as a decision, never an accident', function (): void {
    $accepted = [
        '01aed7e560b0 plugins-dev/embhas/embhas/src/Services/LoginChallenge.php <-> plugins-dev/roya/erp/src/Services/LoginChallenge.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '02089ce383bd plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordPaymentAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierPaymentAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '0383b154c8b2 plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineInvoiceAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflinePurchaseAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '03f51ce3b239 plugins-dev/lekha/core/src/Modules/Documents/CreditNoteDocumentData.php <-> plugins-dev/lekha/core/src/Modules/Documents/DebitNoteDocumentData.php' => 'lekha accounting document family; the parallel shape is the design',
        '03f51ce3b239 plugins-dev/lekha/core/src/Modules/Documents/DebitNoteDocumentData.php <-> plugins-dev/lekha/core/src/Modules/Documents/SalesDebitNoteDocumentData.php' => 'lekha accounting document family; the parallel shape is the design',
        '0484adb18ed9 plugins-dev/embhas/embhas/src/Services/LoginService.php <-> plugins-dev/roya/erp/src/Services/LoginService.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '08d89219d15f src/Magna/Delivery/OpenApi/DeliveryPaths.php <-> src/Magna/Delivery/OpenApi/DeliveryPaths.php' => 'OpenAPI path builders repeat response scaffolding by the nature of the document',
        '0f180f69b189 plugins-dev/magna/defence/src/Filament/Resources/DefenceEventResource.php <-> src/Magna/Admin/Resources/AuditLogResource.php' => 'parallel Filament table definitions across repos; Filament idiom, not logic',
        '0f180f69b189 plugins-dev/magna/defence/src/Filament/Resources/DefenceEventResource.php <-> src/Magna/Admin/Resources/BackupResource.php' => 'parallel Filament table definitions across repos; Filament idiom, not logic',
        '13264747dc3d plugins-dev/embhas/embhas/src/Services/DeviceSessionService.php <-> plugins-dev/roya/erp/src/Services/DeviceSessionService.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '14edeaac0566 plugins-dev/embhas/embhas/src/Services/MessagingPolicy.php <-> plugins-dev/embhas/embhas/src/Services/MessagingPolicy.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '1c646f968798 plugins-dev/magna/blog/src/Editor/EditorJsSanitizer.php <-> plugins-dev/magna/blog/src/Support/BlockRenderer.php' => 'intra-plugin duplication in magna/blog; extraction backlog for that plugin',
        '1d27f559fb02 plugins-dev/lekha/core/src/Modules/TaxRules/Http/Controllers/ProductImpactsController.php <-> plugins-dev/lekha/core/src/Modules/TaxRules/Http/Controllers/ProductImpactsController.php' => 'lekha accounting document family; the parallel shape is the design',
        '1ebc7feb45e6 plugins-dev/lekha/core/src/Modules/Payments/Http/Requests/RecordSupplierPaymentRequest.php <-> plugins-dev/lekha/core/src/Modules/Payments/Http/Requests/RecordSupplierRefundRequest.php' => 'lekha accounting document family; the parallel shape is the design',
        '1f56c835df56 plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/PaymentsController.php <-> plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/PaymentsController.php' => 'lekha accounting document family; the parallel shape is the design',
        '21867d724619 plugins-dev/magna/blog/src/Filament/Resources/CategoryResource.php <-> plugins-dev/magna/blog/src/Filament/Resources/TagResource.php' => 'intra-plugin duplication in magna/blog; extraction backlog for that plugin',
        '22053532d3c0 plugins-dev/roya/dms/src/Http/Controllers/Documents/DocumentArchiveController.php <-> plugins-dev/roya/dms/src/Http/Controllers/Documents/RecycleBinController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '26468b895b78 plugins-dev/embhas/embhas/src/Services/TokenIssuer.php <-> plugins-dev/roya/erp/src/Services/TokenIssuer.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '287d76dbd432 plugins-dev/embhas/embhas/src/Support/ModelId.php <-> plugins-dev/magna/message-lite/src/Support/ModelId.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        '2954e8de10f8 plugins-dev/magna/docs/src/Filament/Resources/DocCollectionResource.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource.php' => 'intra-plugin duplication in magna/docs; extraction backlog for that plugin',
        '2b90be890f67 plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/CreateDocPage.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/EditDocPage.php' => 'intra-plugin duplication in magna/docs; extraction backlog for that plugin',
        '2d250ecb829e plugins-dev/embhas/embhas/src/Http/Controllers/Auth/LoginController.php <-> plugins-dev/roya/erp/src/Http/Controllers/Auth/LoginController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '2e446f5c2ec3 plugins-dev/magna/blog/src/Webhooks/BlogWebhookDispatcher.php <-> src/Magna/Webhooks/WebhookDispatcher.php' => 'blog plugin mirrors the core dispatcher; exposing it through an SDK contract is a cross-repo API decision (deferred)',
        '321cebce542a plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '327b375d557a plugins-dev/roya/dms/src/Http/Requests/StoreCommentRequest.php <-> plugins-dev/roya/dms/src/Http/Requests/UpdateCommentRequest.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '3307fdbc6f24 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '3307fdbc6f24 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '339ab2a506e7 plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestDocumentVoidAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '339ab2a506e7 plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestDocumentVoidAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '3677fa384158 plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/DebitNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/CreditNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        '3677fa384158 plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/DebitNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/SalesDebitNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        '3adfa23aab33 plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflinePurchaseAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '3adfa23aab33 plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflinePurchaseAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '41fec5e0abaf src/Magna/Content/FieldTypes/MarkdownField.php <-> src/Magna/Content/FieldTypes/TextareaField.php' => 'field-type family; parallel small classes are the FieldType contract working as designed',
        '4675c48cf109 plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '48e5fe411165 plugins-dev/roya/dms/src/Http/Controllers/Documents/InboxController.php <-> plugins-dev/roya/erp/src/Http/Controllers/NotificationsController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '48f8bc493346 plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordPaymentAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierRefundAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '49871b15b02b plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '4f0d1e3680e4 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '543de9ac6377 plugins-dev/magna/blog/src/Filament/Resources/CategoryResource.php <-> plugins-dev/magna/blog/src/Filament/Resources/SeriesResource.php' => 'intra-plugin duplication in magna/blog; extraction backlog for that plugin',
        '543de9ac6377 plugins-dev/magna/blog/src/Filament/Resources/SeriesResource.php <-> plugins-dev/magna/blog/src/Filament/Resources/TagResource.php' => 'intra-plugin duplication in magna/blog; extraction backlog for that plugin',
        '55b3f000f13d plugins-dev/embhas/embhas/src/Services/DocumentStorage.php <-> plugins-dev/magna/message-lite/src/Services/AttachmentService.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        '57fde25b4ff9 plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/PaymentsController.php <-> plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/PaymentsController.php' => 'lekha accounting document family; the parallel shape is the design',
        '5889e98af594 plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordCustomerRefundAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordPaymentAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '5889e98af594 plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordPaymentAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierPaymentAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '59e5b8df8203 plugins-dev/magna/blog/src/Filament/Resources/PostResource/Pages/EditsPostInBuilder.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/CreateDocPage.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        '59e5b8df8203 plugins-dev/magna/blog/src/Filament/Resources/PostResource/Pages/EditsPostInBuilder.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/EditDocPage.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        '5b7afdd6b38a plugins-dev/embhas/embhas/src/Http/Controllers/Portal/QualificationController.php <-> plugins-dev/embhas/embhas/src/Http/Controllers/Portal/WorkExperienceController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '5d9ae4f245fc plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordCustomerRefundAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordPaymentAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '5e344f617f6a plugins-dev/embhas/embhas/src/Filament/Resources/DistrictResource.php <-> plugins-dev/embhas/embhas/src/Filament/Resources/LocalAreaResource.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '5e344f617f6a plugins-dev/embhas/embhas/src/Filament/Resources/DistrictResource.php <-> plugins-dev/embhas/embhas/src/Filament/Resources/StateResource.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '5e344f617f6a plugins-dev/embhas/embhas/src/Filament/Resources/LocalAreaResource.php <-> plugins-dev/embhas/embhas/src/Filament/Resources/StateResource.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '04b311e1f461 plugins-dev/roya/dms/src/RoyaDmsPlugin.php <-> plugins-dev/roya/erp/src/RoyaErpPlugin.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '62851e3fd81b plugins-dev/roya/dms/src/Http/Controllers/Documents/CommentController.php <-> plugins-dev/roya/dms/src/Http/Controllers/Documents/CommentController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '6333be01bc75 plugins-dev/embhas/embhas/src/Services/AreaScope.php <-> plugins-dev/embhas/embhas/src/Services/AreaScope.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '638159984afb src/Magna/Admin/Resources/AuditLogResource.php <-> src/Magna/Admin/Resources/BackupResource.php' => 'parallel Filament table definitions; the shape is Filament idiom, not logic',
        '6505bec07d81 plugins-dev/magna/blog/src/Commands/ImportContentCommand.php <-> plugins-dev/magna/blog/src/Commands/ImportWxrCommand.php' => 'intra-plugin duplication in magna/blog; extraction backlog for that plugin',
        '65450c1f13ae plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/PaymentsController.php <-> plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/SupplierPaymentsController.php' => 'lekha accounting document family; the parallel shape is the design',
        '65a3d2718cdd src/Magna/Blocks/Livewire/BlockEditor.php <-> src/Magna/Delivery/Console/BenchSeedCommand.php' => 'bench seeder intentionally mirrors the editor default-block shape so benchmarks exercise real content',
        '67f28b2827cd plugins-dev/embhas/embhas/src/Filament/Resources/DistrictResource.php <-> plugins-dev/embhas/embhas/src/Filament/Resources/LocalAreaResource.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '6ba970590359 plugins-dev/roya/dms/src/Services/DmsSettings.php <-> plugins-dev/roya/erp/src/Services/ErpSettings.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '6e4205b8b6e5 plugins-dev/lekha/core/src/Modules/Documents/InvoiceDocumentData.php <-> plugins-dev/lekha/core/src/Modules/Documents/SalesDebitNoteDocumentData.php' => 'lekha accounting document family; the parallel shape is the design',
        '6ec664163ee2 plugins-dev/embhas/embhas/src/Http/Controllers/SpaController.php <-> plugins-dev/magna/restaurant-finance/src/Http/Controllers/PortalController.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        '6ec664163ee2 plugins-dev/magna/restaurant-finance/src/Http/Controllers/PortalController.php <-> plugins-dev/roya/dms/src/Http/Controllers/SpaController.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        '6ec664163ee2 plugins-dev/magna/restaurant-finance/src/Http/Controllers/PortalController.php <-> plugins-dev/roya/erp/src/Http/Controllers/SpaController.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        '70f3f1585013 src/Magna/Admin/Pages/PerformanceSettingsPage.php <-> src/Magna/Admin/Pages/SettingsPage.php' => 'the two settings pages share persist scaffolding; unifying them is the standing SettingsPage backlog',
        '71dad2cd1c15 plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '7492d4d2541a plugins-dev/lekha/core/src/Modules/Documents/CreditNoteDocumentData.php <-> plugins-dev/lekha/core/src/Modules/Documents/DebitNoteDocumentData.php' => 'lekha accounting document family; the parallel shape is the design',
        '750cb97ceacb plugins-dev/magna/marketplace/src/Models/LibraryAsset.php <-> plugins-dev/magna/pages/src/Render/Conditions/DocumentConditions.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        '7540d8f1da2f plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/PurchasesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/InvoicesController.php' => 'lekha accounting document family; the parallel shape is the design',
        '756932157402 plugins-dev/roya/dms/src/Http/Controllers/Documents/DocumentArchiveController.php <-> plugins-dev/roya/dms/src/Http/Controllers/Documents/RecycleBinController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '785ba019e0b6 plugins-dev/roya/dms/src/Http/Controllers/SpaController.php <-> plugins-dev/roya/erp/src/Http/Controllers/SpaController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '79edc4253924 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '7beafcde12b3 src/Magna/Content/FieldTypes/JsonField.php <-> src/Magna/Content/FieldTypes/RichtextField.php' => 'field-type family; parallel small classes are the FieldType contract working as designed',
        '7bfc1b1fe551 plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/CreditNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/SalesDebitNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        '7de590434577 plugins-dev/lekha/core/src/Modules/Documents/CreditNoteDocumentData.php <-> plugins-dev/lekha/core/src/Modules/Documents/SalesDebitNoteDocumentData.php' => 'lekha accounting document family; the parallel shape is the design',
        '7e0d3bcf181e plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '7f1be3c3285f plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '80a3b9e8e113 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '85fa8b1b8e7f plugins-dev/roya/dms/src/Http/Requests/Concerns/AuthorizesBoundModel.php <-> plugins-dev/roya/erp/src/Http/Requests/Concerns/AuthorizesBoundModel.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '86255e31153c plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '88a20a68562b plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierPaymentAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierRefundAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '8915e1df5aac plugins-dev/lekha/core/src/Modules/Purchases/Actions/PurchaseLineDraft.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/InvoiceLineDraft.php' => 'lekha accounting document family; the parallel shape is the design',
        '8b26e22b876d plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/PurchasesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/InvoicesController.php' => 'lekha accounting document family; the parallel shape is the design',
        '8bab3a465831 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '8c52ff2f991a plugins-dev/embhas/embhas/src/Http/Controllers/SpaController.php <-> plugins-dev/magna/pages/src/Http/Controllers/BuilderSpaController.php' => 'the SPA asset-serving shim, repeated per plugin that ships one; shared-package extraction is tracked backlog',
        '8c52ff2f991a plugins-dev/magna/pages/src/Http/Controllers/BuilderSpaController.php <-> plugins-dev/magna/restaurant-finance/src/Http/Controllers/PortalController.php' => 'the SPA asset-serving shim, repeated per plugin that ships one; shared-package extraction is tracked backlog',
        '90cf942fe4e9 plugins-dev/roya/erp/src/Http/Requests/AssignCompanyUserRequest.php <-> plugins-dev/roya/erp/src/Http/Requests/StoreUserRequest.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '91af96046658 plugins-dev/lekha/core/src/Modules/Documents/CreditNoteDocumentData.php <-> plugins-dev/lekha/core/src/Modules/Documents/SalesDebitNoteDocumentData.php' => 'lekha accounting document family; the parallel shape is the design',
        '922be54e1df4 plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordPaymentAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierPaymentAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '95767b0fd1bb plugins-dev/roya/dms/src/Http/Controllers/Documents/InboxController.php <-> plugins-dev/roya/erp/src/Http/Controllers/NotificationsController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '96d70c72400e plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineInvoiceAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflinePurchaseAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '9b3153b0946b plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        '9ce44fcc09e4 plugins-dev/roya/erp/src/Finance/BreakEven.php <-> plugins-dev/roya/erp/src/Finance/Pacing.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        '9e5e506e5465 plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'a008876fde29 plugins-dev/magna/marketplace/src/Http/Controllers/CheckoutController.php <-> plugins-dev/magna/marketplace/src/Http/Controllers/CheckoutController.php' => 'intra-plugin duplication in magna/marketplace; extraction backlog for that plugin',
        'a0a333ac7679 plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierPaymentAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierRefundAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'a43a33aa9b1e plugins-dev/embhas/embhas/src/Services/EmbhasSettings.php <-> plugins-dev/roya/erp/src/Services/ErpSettings.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'a86c4b45533e plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/CreateDocPage.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/EditDocPage.php' => 'intra-plugin duplication in magna/docs; extraction backlog for that plugin',
        'a33d5812152b plugins-dev/roya/dms/src/Http/Requests/StoreCommentRequest.php <-> plugins-dev/roya/dms/src/Http/Requests/UpdateCommentRequest.php' => 'store/update request pair: identical body rules and the same mention-ownership guard, differing only in how each resolves the company; extraction belongs to that repo',
        'a8ae8960db6b plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'ab4a8e228fbb plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordCustomerRefundAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierPaymentAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'abd5047dbd8c plugins-dev/lekha/core/src/Modules/Sync/Http/Controllers/SyncController.php <-> plugins-dev/lekha/core/src/Modules/Sync/Http/Controllers/SyncController.php' => 'lekha sync controller; the offline-ingest handlers share their shape; extraction belongs to the lekha repo',
        'af3dcfb557ed plugins-dev/magna/docs/src/Transfer/CollectionImporter.php <-> plugins-dev/magna/docs/src/Transfer/PageImporter.php' => 'intra-plugin duplication in magna/docs; extraction backlog for that plugin',
        'b03ef489d22a plugins-dev/magna/restaurant-finance/src/Http/Controllers/GroupConsoleController.php <-> plugins-dev/magna/restaurant-finance/src/Http/Controllers/OrderController.php' => 'intra-plugin duplication in magna/restaurant-finance; extraction backlog for that plugin',
        'b60c6d67e649 plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/CreateDocPage.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/EditDocPage.php' => 'intra-plugin duplication in magna/docs; extraction backlog for that plugin',
        'b6227a1ef1ce plugins-dev/magna/marketplace/src/PluginSubmissions.php <-> plugins-dev/magna/marketplace/src/PluginSubmissions.php' => 'intra-plugin duplication in magna/marketplace; extraction backlog for that plugin',
        'b8a522f15b8c plugins-dev/embhas/embhas/src/Http/Requests/LoginRequest.php <-> plugins-dev/roya/erp/src/Http/Requests/LoginRequest.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'b8fc674e5931 plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/CreateDocPage.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/EditDocPage.php' => 'intra-plugin duplication in magna/docs; extraction backlog for that plugin',
        'b91bd0c88fa4 plugins-dev/lekha/core/src/Modules/Purchases/Http/Requests/PostPurchaseRequest.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Requests/PostInvoiceRequest.php' => 'lekha accounting document family; the parallel shape is the design',
        'bcab4b003d5a plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'be255f6220f0 plugins-dev/embhas/embhas/src/Services/Reports/Tables/ClientTable.php <-> plugins-dev/embhas/embhas/src/Services/Reports/Tables/ServiceRequestTable.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'be255f6220f0 plugins-dev/embhas/embhas/src/Services/Reports/Tables/ClientTable.php <-> plugins-dev/embhas/embhas/src/Services/Reports/Tables/StakeholderTable.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'be255f6220f0 plugins-dev/embhas/embhas/src/Services/Reports/Tables/ServiceRequestTable.php <-> plugins-dev/embhas/embhas/src/Services/Reports/Tables/StakeholderTable.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'be6f8819805f plugins-dev/magna/marketplace/src/Services/RazorpayGateway.php <-> plugins-dev/magna/marketplace/src/Services/RazorpayGateway.php' => 'intra-plugin duplication in magna/marketplace; extraction backlog for that plugin',
        'bea67ad56036 src/Magna/Backup/BackupPlanPersister.php <-> src/Magna/Settings/GeneralSettingsPersister.php' => 'GeneralSettingsPersister was modelled on BackupPlanPersister by instruction; the shared shape is the precedent working',
        'bf38db08f376 plugins-dev/embhas/embhas/src/Http/Controllers/SpaController.php <-> plugins-dev/magna/restaurant-finance/src/Http/Controllers/PortalController.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        'c107b53e3bba plugins-dev/embhas/embhas/src/Support/DeviceCookie.php <-> plugins-dev/embhas/embhas/src/Support/OAuthStateCookie.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'c2d671920dd2 plugins-dev/embhas/embhas/src/Http/Requests/LoginRequest.php <-> plugins-dev/roya/erp/src/Http/Requests/LoginRequest.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'c661b3887600 plugins-dev/roya/erp/src/Http/Requests/StoreCollectionItemRequest.php <-> plugins-dev/roya/erp/src/Http/Requests/StoreEventRequest.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'c7f911e4a2b8 plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordCustomerRefundAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierRefundAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'c7f911e4a2b8 plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordPaymentAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierRefundAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'c7f911e4a2b8 plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierPaymentAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierRefundAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'c89b01300f4e plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/SupplierPaymentsController.php <-> plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/SupplierPaymentsController.php' => 'lekha accounting document family; the parallel shape is the design',
        'caedb96a0886 plugins-dev/embhas/embhas/src/Http/Controllers/SpaController.php <-> plugins-dev/roya/dms/src/Http/Controllers/SpaController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'caedb96a0886 plugins-dev/embhas/embhas/src/Http/Controllers/SpaController.php <-> plugins-dev/roya/erp/src/Http/Controllers/SpaController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'caedb96a0886 plugins-dev/roya/dms/src/Http/Controllers/SpaController.php <-> plugins-dev/roya/erp/src/Http/Controllers/SpaController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'cc91dab5d01c plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidPurchaseAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'cc91dab5d01c plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'cc91dab5d01c plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'cc91dab5d01c plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'cc91dab5d01c plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'cc91dab5d01c plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'cfe37b9d3478 plugins-dev/magna/plugin-manager/src/ZipSafeExtractor.php <-> src/Magna/Licensing/PackageExtractor.php' => 'zip-entry safety checks deliberately mirrored: plugin-manager is a standalone repo that cannot import core, and both sides must stay independently safe',
        'd047ef8c9b2a plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordCustomerRefundAction.php <-> plugins-dev/lekha/core/src/Modules/Payments/Actions/RecordSupplierRefundAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'd0f4b453d98f plugins-dev/lekha/core/src/Modules/EInvoice/InvoicePayloadBuilder.php <-> plugins-dev/lekha/core/src/Modules/EWayBill/ConsignmentPayloadBuilder.php' => 'lekha GST payload family; the e-way bill consignment mirrors the e-invoice payload by the shape of the government APIs; extraction belongs to the lekha repo',
        'd40cfd00d829 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'd5133477fa72 plugins-dev/lekha/core/src/Modules/Tenancy/Actions/AddBusinessAction.php <-> plugins-dev/lekha/core/src/Modules/Tenancy/Actions/OnboardBusinessAction.php' => 'lekha tenancy; onboarding repeats the add-business steps inside its own transaction; extraction belongs to the lekha repo',
        '1bba97e6b19c plugins-dev/lekha/core/src/Modules/Gst/ReturnPeriod.php <-> plugins-dev/lekha/core/src/Modules/Gst/ReturnQuarter.php' => 'lekha GST return periods; month and quarter value objects share their parsing shape; extraction belongs to the lekha repo',
        'dc9cc3e70462 plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/InvoicesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/SalesDebitNotesController.php' => 'lekha sales documents; the controllers share their listing shape; extraction belongs to the lekha repo',
        'd52c1c8f3c37 src/Magna/Updater/UpdateHousekeeping.php <-> src/Magna/Updater/UpdateHousekeeping.php' => 'two prune passes over different directories; in-flight updater refactor owns this file',
        'd59bf0b995e4 plugins-dev/roya/erp/src/Services/License/WalletAdoption.php <-> plugins-dev/roya/erp/src/Services/License/WalletAdoption.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'd7c7ebd7b90b plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/CreateDocPage.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/EditDocPage.php' => 'intra-plugin duplication in magna/docs; extraction backlog for that plugin',
        'd80793c8470d plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'dda69f68dd19 plugins-dev/embhas/embhas/src/Services/LoginService.php <-> plugins-dev/roya/erp/src/Services/LoginService.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'de5c99e6e385 plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/CreditNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/SalesDebitNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        'def7ab5a5f2b plugins-dev/embhas/embhas/src/Services/PortalTwoFactor.php <-> plugins-dev/roya/erp/src/Services/PortalTwoFactor.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'e5d061386741 plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sync/Actions/IngestOfflineDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'e646bd56cf49 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/InvoiceRecorder.php' => 'lekha accounting document family; the parallel shape is the design',
        'e869a718904a src/Magna/Auth/ApiKey.php <-> src/Magna/Auth/MagnaToken.php' => 'two token models with the same hashing contract, kept separate so neither leaks the other via mass assignment',
        'e8a28a992285 plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/DebitNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/CreditNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        'e8bdbe44a46d plugins-dev/lekha/core/src/Modules/Sales/Actions/PostCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'ead09d8962f8 plugins-dev/lekha/core/src/Modules/Purchases/Actions/PostPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'ead09d8962f8 plugins-dev/lekha/core/src/Modules/Sales/Actions/InvoiceRecorder.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/PostSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'eb2d2ea294fe plugins-dev/magna/pages/src/Filament/Pages/ReviewQueuePage.php <-> plugins-dev/magna/pages/src/Http/Controllers/BuilderApprovalController.php' => 'intra-plugin duplication in magna-cms/pages; extraction backlog for that plugin',
        'ef76c55d066c plugins-dev/embhas/embhas/src/Http/Controllers/Auth/LoginController.php <-> plugins-dev/roya/erp/src/Http/Controllers/Auth/LoginController.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'f3e9fd2d76ed plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/CreateDocPage.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/EditDocPage.php' => 'intra-plugin duplication in magna/docs; extraction backlog for that plugin',
        'f46ff0f01423 plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/PaymentsController.php <-> plugins-dev/lekha/core/src/Modules/Payments/Http/Controllers/SupplierPaymentsController.php' => 'lekha accounting document family; the parallel shape is the design',
        'f5ab74b3eb1b plugins-dev/magna/blog/src/Filament/Resources/PostResource/Pages/EditsPostInBuilder.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/CreateDocPage.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        'f5ab74b3eb1b plugins-dev/magna/blog/src/Filament/Resources/PostResource/Pages/EditsPostInBuilder.php <-> plugins-dev/magna/docs/src/Filament/Resources/DocPageResource/Pages/EditDocPage.php' => 'cross-plugin duplication; shared-package extraction is tracked backlog',
        'f6215a242bc8 plugins-dev/roya/erp/src/Support/Decimal.php <-> plugins-dev/roya/erp/src/Support/Decimal.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'f780f96ed193 plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/CreditNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/SalesDebitNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        'f8f808bb5204 plugins-dev/embhas/embhas/src/Filament/Resources/CategoryResource.php <-> plugins-dev/embhas/embhas/src/Filament/Resources/DistrictResource.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'f8f808bb5204 plugins-dev/embhas/embhas/src/Filament/Resources/CategoryResource.php <-> plugins-dev/embhas/embhas/src/Filament/Resources/LocalAreaResource.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'f8f808bb5204 plugins-dev/embhas/embhas/src/Filament/Resources/CategoryResource.php <-> plugins-dev/embhas/embhas/src/Filament/Resources/StateResource.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'f9b6312c9256 plugins-dev/embhas/embhas/src/Http/Controllers/Portal/RegistrationController.php <-> plugins-dev/embhas/embhas/src/Services/StakeholderRegistrationService.php' => 'embhas/roya sibling-product portal lineage; shared-package extraction is tracked backlog',
        'faa5c7069370 plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/DebitNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/CreditNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        'faa5c7069370 plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/DebitNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/SalesDebitNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc183329e3ef plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc183329e3ef plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc183329e3ef plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc183329e3ef plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidInvoiceAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidSalesDebitNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc190d96718f plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidPurchaseAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc190d96718f plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc190d96718f plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidDebitNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc190d96718f plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc190d96718f plugins-dev/lekha/core/src/Modules/Purchases/Actions/VoidPurchaseAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fc190d96718f plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidCreditNoteAction.php <-> plugins-dev/lekha/core/src/Modules/Sales/Actions/VoidInvoiceAction.php' => 'lekha accounting document family; the parallel shape is the design',
        'fd8211bdd207 plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/DebitNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/CreditNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        'fd8211bdd207 plugins-dev/lekha/core/src/Modules/Purchases/Http/Controllers/DebitNotesController.php <-> plugins-dev/lekha/core/src/Modules/Sales/Http/Controllers/SalesDebitNotesController.php' => 'lekha accounting document family; the parallel shape is the design',
        'ff64f4aa4483 plugins-dev/magna/pages/src/Render/BlockStyleMarkup.php <-> plugins-dev/magna/pages/src/Render/BuilderMarkup.php' => 'intra-plugin duplication in magna-cms/pages; extraction backlog for that plugin',
    ];

    $regions = (new CloneScanner)->scanFiles(duplicationScanSet());

    $found = [];

    foreach ($regions as $region) {
        $key = "{$region['fingerprint']} {$region['a']['file']} <-> {$region['b']['file']}";
        $found[$key] ??= $region;
    }

    // 1. Every clone on disk is an accepted clone.
    $unexpected = [];

    foreach ($found as $key => $region) {
        if (! array_key_exists($key, $accepted)) {
            $unexpected[] = sprintf(
                '%s  (%d tokens)  %s:%d-%d  <->  %s:%d-%d',
                $key,
                $region['tokens'],
                $region['a']['file'],
                $region['a']['from'],
                $region['a']['to'],
                $region['b']['file'],
                $region['b']['from'],
                $region['b']['to'],
            );
        }
    }

    expect($unexpected)->toBe([], "New duplication detected. Extract it into a shared collaborator, or accept it above with a reason:\n".implode("\n", $unexpected));

    // 2. Shrink-only: an accepted clone that no longer exists (extracted, or
    //    either copy edited) must leave the list. Entries whose files are not
    //    in this checkout (plugins on CI) are exempt, not stale.
    $stale = [];

    foreach (array_keys($accepted) as $key) {
        if (array_key_exists($key, $found)) {
            continue;
        }

        [, $pair] = explode(' ', $key, 2);
        [$fileA, $fileB] = explode(' <-> ', $pair);

        if (is_file(duplicationRoot().'/'.$fileA) && is_file(duplicationRoot().'/'.$fileB)) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], "Stale accepted-clone entries — the clone is gone or its content changed. Remove them (the list only shrinks):\n".implode("\n", $stale));
});
