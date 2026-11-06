<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The one reader for an Enthusiast source listing's extra fields. It gives each
 field's name, the column its answers are in, and only the details the source really
 supplied, so definitions and columns can't disagree, and a parser's default can
 never be mistaken for a choice someone made.
*/

declare(strict_types=1);

namespace Luster\Import;

use Luster\Support\FieldColumnMap;

/**
 * What a source listing says its extra fields are.
 *
 * Formats, the same ones FieldRepo reads: a PHP-serialized list (with named or numbered
 * keys) on either layout, a comma list of names on the bare layout (real Enthusiast), and
 * pipe-separated "name,Label,type,required" entries on the enth_ layout.
 *
 * label, type, required and options are null when the source didn't supply them. A
 * comma list supplies none of them, and "fave,Fave" supplies a label and nothing else.
 */
final class EnthusiastSourceFields
{
    /**
     * @param list<array{name: string, column: string, label: ?string, type: ?string, required: ?bool, options: ?string}> $fields
     * @param list<string> $notes  What was left out, and why.
     * @param bool $altered        True when something in the source wasn't kept.
     */
    private function __construct(
        public readonly array $fields,
        public readonly array $notes,
        private readonly string $raw,
        private readonly string $schema,
        private readonly bool $altered,
    ) {}

    public static function read(string $raw, string $schema): self
    {
        $raw       = trim($raw);
        $enth      = $schema === 'enthusiast_enth';
        $fields    = [];
        $notes     = [];
        $seen      = [];
        $altered   = false;

        foreach (self::entries($raw, $enth) as $entry) {
            $name = $entry['name'];
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                $notes[] = "Field \"{$name}\" wasn't copied: its name has characters Luster can't use. Its answers stay in the old database.";
                $altered = true;
                continue;
            }
            $lower = strtolower($name);
            if (isset($seen[$lower])) {
                $notes[] = "Field \"{$name}\" is listed twice, so only \"{$seen[$lower]}\" was kept.";
                $altered = true;
                continue;
            }
            if (!$enth && in_array($lower, FieldColumnMap::CORE_COLUMNS, true)) {
                $notes[] = "Field \"{$name}\" wasn't copied: that's the name of one of the built-in member columns.";
                $altered = true;
                continue;
            }
            $seen[$lower] = $name;
            $entry['column'] = $enth ? 'additional_' . $name : $name;
            $fields[] = $entry;
        }

        return new self($fields, $notes, $raw, $schema, $altered);
    }

    /**
     * The same fields with only $names kept, and why the rest went.
     *
     * @param list<string> $names
     * @param list<string> $notes
     */
    public function keeping(array $names, array $notes): self
    {
        return new self(
            array_values(array_filter($this->fields, static fn(array $field): bool => in_array($field['name'], $names, true))),
            [...$this->notes, ...$notes],
            $this->raw,
            $this->schema,
            true,
        );
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->fields, 'name');
    }

    /** The real columns these fields have, for making a new member table. @return list<string> */
    public function columns(): array
    {
        return array_column($this->fields, 'column');
    }

    /**
     * Definitions in the form owned.additional stores, with the usual defaults filled in
     * for whatever the source didn't say.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $out = [];
        foreach ($this->fields as $field) {
            $definition = [
                'name'     => $field['name'],
                'label'    => $field['label'] ?? '',
                'type'     => $field['type'] ?? 'text',
                'required' => $field['required'] ?? false,
            ];
            if ($field['options'] !== null) {
                $definition['options'] = $field['options'];
            }
            $out[] = $definition;
        }
        return $out;
    }

    /**
     * What owned.additional gets on a listing being created. A normal comma list goes in
     * just as it was. The enth_ layout's definitions are stored whole, so a dropdown stays
     * a dropdown, and a list with something left out loses only that.
     */
    public function forNewListing(): string
    {
        if ($this->fields === []) {
            return '';
        }
        if ($this->schema === 'enthusiast_enth') {
            return serialize($this->definitions());
        }
        if (!$this->altered) {
            return $this->raw;
        }
        return self::decode($this->raw) !== null ? serialize($this->definitions()) : implode(',', $this->names());
    }

    /**
     * @return list<array{name: string, column: string, label: ?string, type: ?string, required: ?bool, options: ?string}>
     */
    private static function entries(string $raw, bool $enth): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = self::decode($raw);
        if ($decoded !== null) {
            return array_values(array_map(self::fromSerialized(...), $decoded));
        }

        if ($enth) {
            return array_map(self::fromPipe(...), explode('|', $raw));
        }

        return array_map(
            static fn(string $name): array => self::entry(trim($name), null, null, null, null),
            explode(',', $raw),
        );
    }

    /**
     * The value unserialized, or null when it isn't a serialized list. Never builds an
     * object, whatever it says.
     *
     * @return array<mixed>|null
     */
    private static function decode(string $raw): ?array
    {
        if (!str_starts_with($raw, 'a:')) {
            return null;
        }

        set_error_handler(static fn(): bool => true);
        try {
            $data = unserialize($raw, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        return is_array($data) ? $data : null;
    }

    /** @return array{name: string, column: string, label: ?string, type: ?string, required: ?bool, options: ?string} */
    private static function fromSerialized(mixed $item): array
    {
        if (!is_array($item)) {
            return self::entry(trim((string) (is_scalar($item) ? $item : '')), null, null, null, null);
        }

        $name     = $item['name'] ?? $item[0] ?? '';
        $label    = $item['label'] ?? $item[1] ?? null;
        $type     = $item['type'] ?? $item[2] ?? null;
        $required = $item['required'] ?? $item[3] ?? null;
        $options  = $item['options'] ?? null;

        return self::entry(
            trim((string) $name),
            is_scalar($label) ? self::textOrNull((string) $label) : null,
            is_scalar($type) ? self::textOrNull((string) $type) : null,
            is_scalar($required) ? (bool) (int) $required : null,
            is_scalar($options) ? (string) $options : null,
        );
    }

    /** @return array{name: string, column: string, label: ?string, type: ?string, required: ?bool, options: ?string} */
    private static function fromPipe(string $text): array
    {
        $parts = explode(',', trim($text), 4);

        return self::entry(
            trim($parts[0]),
            self::textOrNull($parts[1] ?? ''),
            self::textOrNull($parts[2] ?? ''),
            array_key_exists(3, $parts) ? (bool) (int) trim($parts[3]) : null,
            null,
        );
    }

    private static function textOrNull(string $text): ?string
    {
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /** @return array{name: string, column: string, label: ?string, type: ?string, required: ?bool, options: ?string} */
    private static function entry(string $name, ?string $label, ?string $type, ?bool $required, ?string $options): array
    {
        return ['name' => $name, 'column' => '', 'label' => $label, 'type' => $type, 'required' => $required, 'options' => $options];
    }
}
