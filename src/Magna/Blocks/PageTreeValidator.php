<?php

declare(strict_types=1);

namespace Magna\Blocks;

/**
 * Structural validation for a decoded block document.
 *
 * Purely structural — schema shape, spans, id uniqueness, nesting depth,
 * registered handles, field rules. It never reads the authenticated user:
 * permission questions (who may save raw-HTML blocks) live in
 * {@see PageTreeAuthorizer} so that render paths, CLI imports, theme
 * activation, and system contexts can validate without an actor
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §C2).
 *
 * Accepts both document shapes: the legacy plain list of sections, and the
 * wrapped form {"schemaVersion": "1.0", "sections": [...]}. A schemaVersion
 * this build does not support fails closed with an error — newer documents
 * are rejected, never silently stripped.
 *
 * Validation is enforced on save and skipped on read so that
 * disabled-plugin blocks remain tolerated.
 */
final class PageTreeValidator
{
    /**
     * Maximum depth of nested block `children` below a column
     * (column-level block = depth 1).
     */
    public const MAX_BLOCK_DEPTH = 6;

    public function __construct(private readonly BlockRegistry $registry) {}

    /**
     * Validate a decoded blocks_data payload (either document shape).
     *
     * @param  array<mixed, mixed>  $data
     * @return list<string> Error messages (empty = valid)
     */
    public function validate(array $data): array
    {
        $errors = [];

        $sections = $this->unwrap($data, $errors);
        if ($sections === null) {
            return $errors;
        }

        $seenIds = [];

        foreach ($sections as $sectionIndex => $sectionRaw) {
            if (! is_array($sectionRaw)) {
                $errors[] = "Section #{$sectionIndex} must be an object.";

                continue;
            }

            $sectionId = $this->collectId($sectionRaw, $seenIds, $errors);
            if ($sectionId === '') {
                $errors[] = "Section #{$sectionIndex} is missing an 'id'.";
            }

            $type = isset($sectionRaw['type']) && is_string($sectionRaw['type']) && $sectionRaw['type'] !== ''
                ? $sectionRaw['type']
                : SectionNode::TYPE_SECTION;

            if ($type === SectionNode::TYPE_REF) {
                $part = $sectionRaw['part'] ?? null;
                if (! is_string($part) || $part === '') {
                    $errors[] = "Ref section '{$sectionId}' is missing a 'part' reference.";
                }

                continue; // a ref carries no own columns to validate
            }

            if ($type !== SectionNode::TYPE_SECTION) {
                $errors[] = "Section '{$sectionId}' has unknown type '{$type}'.";

                continue;
            }

            $this->validateTokenOverrides($sectionRaw['settings'] ?? [], $sectionIndex, $errors);

            $columns = $sectionRaw['columns'] ?? [];
            if (! is_array($columns)) {
                $errors[] = "Section '{$sectionId}' columns must be an array.";

                continue;
            }

            $spanSum = 0;
            foreach ($columns as $col) {
                if (is_array($col)) {
                    $spanVal = $col['span'] ?? 0;
                    $spanSum += is_int($spanVal) ? $spanVal : 0;
                }
            }
            if (count($columns) > 0 && $spanSum !== 12) {
                $errors[] = "Section '{$sectionId}' column spans sum to {$spanSum}, must be 12.";
            }

            foreach ($columns as $colIndex => $colRaw) {
                if (! is_array($colRaw)) {
                    continue;
                }

                $this->collectId($colRaw, $seenIds, $errors);

                $blocks = $colRaw['blocks'] ?? [];
                if (! is_array($blocks)) {
                    continue;
                }

                foreach ($blocks as $blockIndex => $blockRaw) {
                    if (! is_array($blockRaw)) {
                        continue;
                    }

                    $this->validateBlock(
                        $blockRaw,
                        "section '{$sectionId}', column #{$colIndex}, block #{$blockIndex}",
                        depth: 1,
                        seenIds: $seenIds,
                        errors: $errors,
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Resolve the document shape to its sections list, validating the
     * wrapper when present. Returns null when the shape itself is invalid.
     *
     * @param  array<mixed, mixed>  $data
     * @param  list<string>  $errors
     * @return array<int, mixed>|null
     */
    private function unwrap(array $data, array &$errors): ?array
    {
        if (array_is_list($data)) {
            return $data;
        }

        $schemaVersion = $data['schemaVersion'] ?? null;
        if ($schemaVersion !== null
            && (! is_string($schemaVersion) || ! in_array($schemaVersion, PageTree::SUPPORTED_SCHEMA_VERSIONS, true))
        ) {
            $label = is_scalar($schemaVersion) ? (string) $schemaVersion : gettype($schemaVersion);
            $errors[] = "Unsupported document schemaVersion '{$label}'. This installation supports: "
                .implode(', ', PageTree::SUPPORTED_SCHEMA_VERSIONS)
                .'. Upgrade Magna before saving this document — saving would destroy newer content.';

            return null;
        }

        $sections = $data['sections'] ?? null;
        if (! is_array($sections)) {
            $errors[] = "Document 'sections' must be an array.";

            return null;
        }

        return array_values($sections);
    }

    /**
     * Validate one block node and recurse into its children.
     *
     * @param  array<mixed, mixed>  $blockRaw
     * @param  list<string>  $seenIds
     * @param  list<string>  $errors
     */
    private function validateBlock(array $blockRaw, string $location, int $depth, array &$seenIds, array &$errors): void
    {
        $blockId = $this->collectId($blockRaw, $seenIds, $errors);

        if ($depth > self::MAX_BLOCK_DEPTH) {
            $errors[] = "Block '{$blockId}' exceeds the maximum nesting depth of ".self::MAX_BLOCK_DEPTH.'.';

            return;
        }

        $handle = isset($blockRaw['block']) && is_string($blockRaw['block']) ? $blockRaw['block'] : '';
        if (! $this->registry->has($handle)) {
            $errors[] = "Block handle '{$handle}' in {$location} is not registered.";
        } else {
            $definition = $this->registry->get($handle);
            if ($definition !== null) {
                $blockData = is_array($blockRaw['data'] ?? null) ? $blockRaw['data'] : [];
                $fieldErrors = $definition->validate($blockData);
                foreach ($fieldErrors as $fieldMessages) {
                    foreach ($fieldMessages as $message) {
                        $errors[] = "Block '{$handle}' (id: {$blockId}): {$message}";
                    }
                }
            }
        }

        $children = $blockRaw['children'] ?? null;
        if (is_array($children)) {
            foreach ($children as $childIndex => $childRaw) {
                if (is_array($childRaw)) {
                    $this->validateBlock(
                        $childRaw,
                        "{$location}, child #{$childIndex}",
                        depth: $depth + 1,
                        seenIds: $seenIds,
                        errors: $errors,
                    );
                }
            }
        }
    }

    /**
     * Record a node id into the seen set, reporting duplicates.
     * Returns the id ('' when missing/invalid).
     *
     * @param  array<mixed, mixed>  $nodeRaw
     * @param  list<string>  $seenIds
     * @param  list<string>  $errors
     */
    private function collectId(array $nodeRaw, array &$seenIds, array &$errors): string
    {
        $id = isset($nodeRaw['id']) && is_string($nodeRaw['id']) ? $nodeRaw['id'] : '';
        if ($id === '') {
            return '';
        }

        if (in_array($id, $seenIds, true)) {
            $errors[] = "Duplicate id '{$id}' in the page tree.";
        } else {
            $seenIds[] = $id;
        }

        return $id;
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateTokenOverrides(mixed $settings, int $sectionIndex, array &$errors): void
    {
        if (! is_array($settings)) {
            return;
        }

        $overrides = $settings['tokenOverrides'] ?? null;
        if ($overrides === null) {
            return;
        }

        if (! is_array($overrides)) {
            $errors[] = "Section #{$sectionIndex} tokenOverrides must be an object.";

            return;
        }

        foreach ($overrides as $key => $value) {
            if (! is_string($key) || $key === '') {
                $errors[] = "Section #{$sectionIndex} tokenOverrides contains an empty or non-string key.";
            } elseif (str_contains($key, ';') || str_contains($key, ':')) {
                $errors[] = "Section #{$sectionIndex} tokenOverrides key '{$key}' must not contain ';' or ':'.";
            }
            if (! is_string($value) || $value === '') {
                $errors[] = "Section #{$sectionIndex} tokenOverrides key '{$key}' has an empty or non-string value.";
            } elseif (str_contains($value, ';')) {
                $errors[] = "Section #{$sectionIndex} tokenOverrides key '{$key}' value must not contain ';'.";
            }
        }
    }
}
